<?php
if (!defined('ABSPATH')) exit;

class WPCS {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('wp_ajax_wpcs_run', [$this, 'ajax_run']);
        add_action('wp_ajax_wpcs_test_terms', [$this, 'ajax_test_terms']);
        add_action('wp_ajax_wpcs_test_posts', [$this, 'ajax_test_posts']);
        add_action('wp_ajax_wpcs_test_options', [$this, 'ajax_test_options']);
        add_action('wp_ajax_wpcs_all', [$this, 'ajax_sync_all']);
        add_action('wp_ajax_wpcs_media', [$this, 'ajax_sync_media']);
        add_action('wp_ajax_wpcs_clear_log', [$this, 'ajax_clear_log']);

        // WP-Cron
        add_action('wpcs_cron_hook', [$this, 'cron_sync']);
        add_action('wpcs_media_cron_hook', [$this, 'cron_sync_media']);
        add_action('init', [$this, 'schedule_cron']);

        if (get_option('wpcs_mode') === 'receiver') {
            new WPCS_Receiver();
        }
    }

    // ============================================================
    //  WP-Cron
    // ============================================================

    public function schedule_cron() {
        if (get_option('wpcs_mode') !== 'source') return;

        // --- Синхронизация контента (01:00 и 13:00) ---
        // НЕ удаляем хук каждый раз! Только если его нет.
        if (!wp_next_scheduled('wpcs_cron_hook')) {
            $tz = wp_timezone();
            $now = new DateTime('now', $tz);

            $morning = new DateTime('today 01:00', $tz);
            if ($morning < $now) {
                $morning->modify('+1 day');
            }

            // ОДНО событие с интервалом twicedaily (12ч) → 01:00 и 13:00
            wp_schedule_event($morning->getTimestamp(), 'twicedaily', 'wpcs_cron_hook');
        }

        // --- Синхронизация медиа (каждый час) ---
        if (!wp_next_scheduled('wpcs_media_cron_hook')) {
            wp_schedule_event(time() + 300, 'hourly', 'wpcs_media_cron_hook');
        }
    }

    public function cron_sync() {
        if (get_option('wpcs_mode') !== 'source') return;
        $this->log('=== CRON: автоматическая синхронизация ===');
        $this->run_full_sync(false);
    }

    public function cron_sync_media() {
        if (get_option('wpcs_mode') !== 'source') return;
        $this->log('=== CRON: синхронизация медиа ===');
        $this->run_media_sync();
    }

    // ============================================================
    //  REST
    // ============================================================

    public function register_rest_routes() {
        register_rest_route('wpcs/v1', '/ping', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_ping'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function rest_ping() {
        return [
            'ok'      => true,
            'site'    => get_bloginfo('name'),
            'url'     => home_url(),
            'time'    => current_time('mysql'),
            'version' => defined('WPCS_VERSION') ? WPCS_VERSION : '1.0',
            'mode'    => get_option('wpcs_mode', 'source'),
        ];
    }

    // ============================================================
    //  AJAX: проверка связи
    // ============================================================

    public function ajax_run() {
        check_ajax_referer('wpcs_run', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав']);
        }

        $receiver_url = get_option('wpcs_receiver_url', '');
        if (empty($receiver_url)) {
            wp_send_json_error(['message' => 'URL приёмника не задан.']);
        }

        $ping_url = trailingslashit($receiver_url) . 'wp-json/wpcs/v1/ping';

        $test = wp_remote_get($ping_url, ['timeout' => 15, 'sslverify' => false]);

        if (is_wp_error($test)) {
            $this->log('ОШИБКА подключения: ' . $test->get_error_message());
            wp_send_json_error(['message' => 'Не удалось подключиться: ' . $test->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($test);
        $body = wp_remote_retrieve_body($test);

        if ($code !== 200) {
            wp_send_json_error(['message' => 'Приёмник ответил HTTP ' . $code . '.']);
        }

        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['ok'])) {
            wp_send_json_error(['message' => 'Приёмник не подтвердил готовность.']);
        }

        update_option('wpcs_last_run', current_time('mysql'));
        $this->log('Приёмник доступен: ' . $json['site']);

        wp_send_json_success([
            'message' => 'Приёмник доступен.',
            'receiver_status' => $json,
        ]);
    }

    // ============================================================
    //  AJAX: тестовая отправка терминов
    // ============================================================

    public function ajax_test_terms() {
        check_ajax_referer('wpcs_run', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав']);
        }

        if (get_option('wpcs_mode') !== 'source') {
            wp_send_json_error(['message' => 'Этот сайт не источник']);
        }

        $sender = new WPCS_Sender();
        $terms = $sender->collect_terms();

        if (empty($terms)) {
            wp_send_json_error(['message' => 'Нет терминов для отправки']);
        }

        $result = $sender->send('terms', $terms);

        if (is_wp_error($result)) {
            $this->log('ОШИБКА отправки терминов: ' . $result->get_error_message());
            wp_send_json_error(['message' => 'Ошибка: ' . $result->get_error_message()]);
        }

        $this->log('Термины: ' . wp_json_encode($result['results']));

        wp_send_json_success([
            'message' => 'Термины отправлены (' . count($terms) . ')',
            'results' => $result['results'],
        ]);
    }

    // ============================================================
    //  AJAX: тестовая отправка постов
    // ============================================================

    public function ajax_test_posts() {
        check_ajax_referer('wpcs_run', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав']);
        }

        if (get_option('wpcs_mode') !== 'source') {
            wp_send_json_error(['message' => 'Этот сайт не источник']);
        }

        $type = sanitize_text_field($_POST['type'] ?? 'page');
        $batch_size = intval($_POST['batch_size'] ?? 20);

        $this->log("=== ТЕСТ: отправка постов типа '$type' (батч $batch_size) ===");

        $sender = new WPCS_Sender();
        $result = $sender->send_posts($type, $batch_size);

        $this->log("Итог: {$result['sent']} из {$result['total']}, ошибок " . count($result['errors']));

        wp_send_json_success([
            'message' => "Отправлено {$result['sent']} из {$result['total']} ({$type})",
            'result'  => $result,
        ]);
    }

    // ============================================================
    //  AJAX: тестовая отправка опций
    // ============================================================

    public function ajax_test_options() {
        check_ajax_referer('wpcs_run', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав']);
        }

        if (get_option('wpcs_mode') !== 'source') {
            wp_send_json_error(['message' => 'Этот сайт не источник']);
        }

        $batch_size = intval($_POST['batch_size'] ?? 100);

        $this->log("=== ТЕСТ: отправка опций (батч $batch_size) ===");

        $sender = new WPCS_Sender();
        $options = $sender->collect_options();

        $total = count($options);
        if (empty($options)) {
            wp_send_json_error(['message' => 'Нет опций для отправки']);
        }

        $sent = 0; $updated = 0; $skipped = 0; $errors = [];
        $chunks = array_chunk($options, $batch_size, true);
        $batch_num = 0;

        foreach ($chunks as $chunk) {
            $batch_num++;
            $result = $sender->send('options', $chunk);

            if (is_wp_error($result)) {
                $errors[] = "Батч #$batch_num: " . $result->get_error_message();
                continue;
            }

            $sent += count($chunk);
            $updated += $result['results']['updated'] ?? 0;
            $skipped += $result['results']['skipped'] ?? 0;
        }

        $this->log("Опции: $sent из $total, ошибок " . count($errors));

        wp_send_json_success([
            'message' => "Отправлено $sent из $total опций",
            'result' => [
                'total'   => $total,
                'sent'    => $sent,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors'  => $errors,
            ],
        ]);
    }

    // ============================================================
    //  AJAX: синхронизировать ВСЁ
    // ============================================================

    public function ajax_sync_all() {
        check_ajax_referer('wpcs_run', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав']);
        }

        if (get_option('wpcs_mode') !== 'source') {
            wp_send_json_error(['message' => 'Этот сайт не источник']);
        }

        $result = $this->run_full_sync(true);

        wp_send_json_success($result);
    }

    // ============================================================
    //  AJAX: синхронизация медиа (rsync)
    // ============================================================

    public function ajax_sync_media() {
        check_ajax_referer('wpcs_run', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав']);
        }

        if (get_option('wpcs_mode') !== 'source') {
            wp_send_json_error(['message' => 'Этот сайт не источник']);
        }

        if (!function_exists('shell_exec')) {
            wp_send_json_error(['message' => 'shell_exec отключён на сервере']);
        }

        $result = $this->run_media_sync();

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * Синхронизация медиа через rsync.
     */
    private function run_media_sync() {
        $this->log('=== СИНХРОНИЗАЦИЯ МЕДИА (rsync) ===');

        // Пути можно переопределить через опции
        $local_path  = get_option('wpcs_media_local_path',
            '/var/www/WPCSby/data/www/WPCS.by/wp-content/uploads/');
        $remote_host = get_option('wpcs_media_remote_host',
            'rsyncuser@62.109.1.144');
        $remote_path = get_option('wpcs_media_remote_path',
            '/var/www/www-root/data/www/WPCSrussia.ru/wp-content/uploads/');
        $ssh_key     = get_option('wpcs_media_ssh_key',
            '/root/.ssh/id_WPCS');

        // Проверки
        if (!is_dir($local_path)) {
            $msg = "Локальный путь не найден: $local_path";
            $this->log('ОШИБКА: ' . $msg);
            return new WP_Error('no_path', $msg);
        }

        clearstatcache(true, $ssh_key);

        $this->log('DEBUG: Проверка ключа');
        $this->log('DEBUG: $ssh_key = [' . $ssh_key . ']');
        $this->log('DEBUG: strlen = ' . strlen($ssh_key));
        $this->log('DEBUG: file_exists = ' . (file_exists($ssh_key) ? 'YES' : 'NO'));
        $this->log('DEBUG: is_readable = ' . (is_readable($ssh_key) ? 'YES' : 'NO'));
        $this->log('DEBUG: realpath = ' . (realpath($ssh_key) ?: 'NOT FOUND'));

        if (!file_exists($ssh_key)) {
            $msg = "SSH-ключ не найден: $ssh_key";
            $this->log('ОШИБКА: ' . $msg);
            return new WP_Error('no_key', $msg);
        }

        // Команда rsync
        $cmd = sprintf(
            'rsync -avz --chown=www-root:www-root --rsync-path="sudo rsync" -e "ssh -i %s -o StrictHostKeyChecking=no" %s %s:%s 2>&1',
            escapeshellarg($ssh_key),
            escapeshellarg($local_path),
            escapeshellarg($remote_host),
            escapeshellarg($remote_path)
        );

        $this->log('Запуск rsync...');

        @set_time_limit(600);

        $output = shell_exec($cmd);

        if ($output === null) {
            $msg = 'shell_exec вернул null';
            $this->log('ОШИБКА: ' . $msg);
            return new WP_Error('shell_error', $msg);
        }

        // Извлекаем статистику
        $sent = 0;
        if (preg_match('/sent ([\d,\.]+) bytes\s+received/', $output, $m)) {
            $sent = (int)str_replace(',', '', $m[1]);
        }

        $this->log('rsync завершён. Отправлено: ' . number_format($sent) . ' байт');

        update_option('wpcs_last_media_sync', current_time('mysql'));

        // Последние 20 строк
        $lines = explode("\n", trim($output));
        $tail = implode("\n", array_slice($lines, -20));

        return [
            'message'    => 'Медиа синхронизированы',
            'sent_bytes' => $sent,
            'output'     => $tail,
        ];
    }

    /**
     * Полная синхронизация: медиа → термины → посты → опции.
     */
    private function run_full_sync($verbose = false) {
        $sender = new WPCS_Sender();
        $summary = [
            'media'   => null,
            'terms'   => null,
            'posts'   => [],
            'options' => null,
            'errors'  => [],
        ];

        // 0. Медиа
        if ($verbose) $this->log('--- Медиа ---');
        $media = $this->run_media_sync();
        if (is_wp_error($media)) {
            $summary['errors'][] = 'Медиа: ' . $media->get_error_message();
        } else {
            $summary['media'] = $media;
        }

        // 1. Термины
        if ($verbose) $this->log('--- Термины ---');
        $terms = $sender->collect_terms();
        if (!empty($terms)) {
            $r = $sender->send('terms', $terms);
            if (!is_wp_error($r)) {
                $summary['terms'] = $r['results'];
                if ($verbose) $this->log('Термины: ' . wp_json_encode($r['results']));
            } else {
                $summary['errors'][] = 'Термины: ' . $r->get_error_message();
            }
        }

        // 2. Посты — по каждому типу
        $types = ['page', 'catalog', 'news', 'blog', 'faq', 'projects', 'post'];
        foreach ($types as $type) {
            if ($verbose) $this->log("--- Посты: $type ---");
            $r = $sender->send_posts($type, 20);
            $summary['posts'][$type] = $r;
            if ($verbose) {
                $this->log("$type: {$r['sent']} из {$r['total']}, ошибок " . count($r['errors']));
            }
            if (!empty($r['errors'])) {
                foreach ($r['errors'] as $e) {
                    $summary['errors'][] = "$type: $e";
                }
            }
        }

        // 3. Опции
        if ($verbose) $this->log('--- Опции ---');
        $options = $sender->collect_options();
        if (!empty($options)) {
            $sent = 0; $updated = 0; $errors = [];
            $chunks = array_chunk($options, 100, true);
            foreach ($chunks as $chunk) {
                $r = $sender->send('options', $chunk);
                if (is_wp_error($r)) {
                    $errors[] = $r->get_error_message();
                    continue;
                }
                $sent += count($chunk);
                $updated += $r['results']['updated'] ?? 0;
            }
            $summary['options'] = [
                'total'   => count($options),
                'sent'    => $sent,
                'updated' => $updated,
                'errors'  => $errors,
            ];
            if ($verbose) $this->log("Опции: $sent, обновлено $updated");
            if (!empty($errors)) {
                foreach ($errors as $e) {
                    $summary['errors'][] = "options: $e";
                }
            }
        }

        update_option('wpcs_last_run', current_time('mysql'));
        $this->log('=== Полная синхронизация завершена ===');

        return $summary;
    }

    // ============================================================
    //  AJAX: очистка лога
    // ============================================================

    public function ajax_clear_log() {
        check_ajax_referer('wpcs_run', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав']);
        }

        update_option('wpcs_log', []);
        wp_send_json_success(['message' => 'Лог очищен']);
    }

    // ============================================================
    //  Лог
    // ============================================================

    private function log($message) {
        $log = get_option('wpcs_log', []);
        $log[] = current_time('mysql') . ' | ' . $message;
        if (count($log) > 1000) {
            $log = array_slice($log, -1000);
        }
        update_option('wpcs_log', $log);
    }
}