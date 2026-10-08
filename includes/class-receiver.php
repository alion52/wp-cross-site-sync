<?php
if (!defined('ABSPATH')) exit;

class WPCS_Receiver {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('wpcs/v1', '/terms', [
            'methods'             => 'POST',
            'callback'            => [$this, 'receive_terms'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route('wpcs/v1', '/posts', [
            'methods'             => 'POST',
            'callback'            => [$this, 'receive_posts'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route('wpcs/v1', '/options', [
            'methods'             => 'POST',
            'callback'            => [$this, 'receive_options'],
            'permission_callback' => [$this, 'check_auth'],
        ]);
    }

    /**
     * Проверка авторизации.
     * Принимаем либо Application Password (Basic Auth),
     * либо токен из настроек.
     */
    public function check_auth($request) {
        // Способ 1: Application Password через Basic Auth
        $auth = $request->get_header('Authorization');

        if (!empty($auth) && stripos($auth, 'Basic ') === 0) {
            $decoded = base64_decode(substr($auth, 6));

            if ($decoded && strpos($decoded, ':') !== false) {
                list($username, $password) = explode(':', $decoded, 2);

                // Явно проверяем Application Password
                $user = wp_authenticate_application_password(null, $username, $password);

                if (!is_wp_error($user) && $user instanceof WP_User && $user->ID) {
                    if (user_can($user, 'manage_options')) {
                        wp_set_current_user($user->ID);
                        return true;
                    }
                }
            }
        }

        // Способ 2: секретный ключ из заголовка
        $secret = $request->get_header('X-WPCS-Sync-Key');
        $expected = get_option('wpcs_secret_key', '');

        if (!empty($secret) && !empty($expected) && hash_equals($expected, $secret)) {
            return true;
        }

        return new WP_Error('rest_forbidden', 'Доступ запрещён', ['status' => 401]);
    }

    // ============================================================
    //  ТЕРМИНЫ
    // ============================================================

    public function receive_terms($request) {
        $terms = $request->get_json_params();

        if (!is_array($terms)) {
            return new WP_Error('invalid_data', 'Неверный формат данных', ['status' => 400]);
        }

        $results = [
            'received' => count($terms),
            'created'  => 0,
            'updated'  => 0,
            'errors'   => [],
        ];

        foreach ($terms as $term) {
            $taxonomy = $term['taxonomy'] ?? '';
            $slug     = $term['slug'] ?? '';
            $name     = $term['name'] ?? '';
            $parent   = $term['parent'] ?? 0;

            if (empty($taxonomy) || empty($slug) || empty($name)) {
                $results['errors'][] = 'Пропущен термин: нет taxonomy/slug/name';
                continue;
            }

            if (!taxonomy_exists($taxonomy)) {
                $results['errors'][] = "Таксономия '$taxonomy' не зарегистрирована";
                continue;
            }

            // Ищем термин по slug
            $existing = get_term_by('slug', $slug, $taxonomy);

            if ($existing) {
                // Обновляем
                $update = wp_update_term($existing->term_id, $taxonomy, [
                    'name'   => $name,
                    'parent' => $parent,
                ]);
                if (!is_wp_error($update)) {
                    $results['updated']++;
                } else {
                    $results['errors'][] = "Ошибка обновления '$slug': " . $update->get_error_message();
                }
            } else {
                // Создаём
                $insert = wp_insert_term($name, $taxonomy, [
                    'slug'   => $slug,
                    'parent' => $parent,
                ]);
                if (!is_wp_error($insert)) {
                    $results['created']++;
                } else {
                    $results['errors'][] = "Ошибка создания '$slug': " . $insert->get_error_message();
                }
            }
        }

        $this->log_receive('terms', $results);

        return [
            'ok'      => true,
            'results' => $results,
        ];
    }

    // ============================================================
    //  ПОСТЫ
    // ============================================================

    public function receive_posts($request) {
        $posts = $request->get_json_params();

        if (!is_array($posts)) {
            return new WP_Error('invalid_data', 'Неверный формат данных', ['status' => 400]);
        }

        $results = [
            'received' => count($posts),
            'created'  => 0,
            'updated'  => 0,
            'errors'   => [],
        ];

        foreach ($posts as $post) {
            $post_id = $post['ID'] ?? 0;
            $post_type = $post['post_type'] ?? 'post';

            if (empty($post_id)) {
                $results['errors'][] = 'Пропущен пост: нет ID';
                continue;
            }

            $data = [
                'ID'           => $post_id,
                'post_title'   => $post['post_title'] ?? '',
                'post_content' => $post['post_content'] ?? '',
                'post_excerpt' => $post['post_excerpt'] ?? '',
                'post_status'  => $post['post_status'] ?? 'publish',
                'post_type'    => $post_type,
                'post_name'    => $post['post_name'] ?? '',
                'post_date'    => $post['post_date'] ?? current_time('mysql'),
                'post_author'  => $post['post_author'] ?? 1,
                'menu_order'   => $post['menu_order'] ?? 0,
                'comment_status' => $post['comment_status'] ?? 'closed',
                'ping_status'    => $post['ping_status'] ?? 'closed',
            ];

            $existing = get_post($post_id);

            if ($existing) {
                $update = wp_update_post($data, true);
                if (!is_wp_error($update)) {
                    $results['updated']++;
                } else {
                    $results['errors'][] = "Ошибка обновления #$post_id: " . $update->get_error_message();
                }
            } else {
                $insert = wp_insert_post($data, true);
                if (!is_wp_error($insert)) {
                    $results['created']++;
                } else {
                    $results['errors'][] = "Ошибка создания #$post_id: " . $insert->get_error_message();
                }
            }

            // Метаполя
            if (!empty($post['meta']) && is_array($post['meta'])) {
                foreach ($post['meta'] as $key => $value) {
                    update_post_meta($post_id, $key, $value);
                }
            }

            // Термины
            if (!empty($post['terms']) && is_array($post['terms'])) {
                foreach ($post['terms'] as $tax => $term_ids) {
                    if (taxonomy_exists($tax)) {
                        wp_set_object_terms($post_id, $term_ids, $tax);
                    }
                }
            }
        }

        $this->log_receive('posts', $results);

        return [
            'ok'      => true,
            'results' => $results,
        ];
    }

    // ============================================================
    //  ОПЦИИ
    // ============================================================

    public function receive_options($request) {
        $options = $request->get_json_params();

        if (!is_array($options)) {
            return new WP_Error('invalid_data', 'Неверный формат данных', ['status' => 400]);
        }

        $results = [
            'received' => count($options),
            'updated'  => 0,
            'skipped'  => 0,
            'errors'   => [],
        ];

        // Опции, которые НИКОГДА не перезаписываем
        $protected = [
            'siteurl', 'home', 'admin_email', 'new_admin_email',
            'secret_key', 'adminhash', 'recovery_keys',
            'active_plugins', 'template', 'stylesheet',
            'cron', 'rewrite_rules',
        ];

        foreach ($options as $name => $value) {
            if (in_array($name, $protected, true)) {
                $results['skipped']++;
                continue;
            }

            // Пропускаем служебные
            if (strpos($name, '_transient_') === 0 || strpos($name, '_site_transient_') === 0) {
                $results['skipped']++;
                continue;
            }

            $result = update_option($name, $value);

            if ($result !== false) {
                $results['updated']++;
            } else {
                // update_option возвращает false, если значение не изменилось — это не ошибка
                $results['updated']++;
            }
        }

        $this->log_receive('options', $results);

        return [
            'ok'      => true,
            'results' => $results,
        ];
    }

    // ============================================================
    //  Лог
    // ============================================================

    private function log_receive($type, $results) {
        $log = get_option('wpcs_receive_log', []);
        $log[] = current_time('mysql') . ' | ' . $type . ' | получено: ' . $results['received']
            . ' | создано: ' . ($results['created'] ?? 0)
            . ' | обновлено: ' . ($results['updated'] ?? 0)
            . ' | ошибок: ' . count($results['errors'] ?? []);

        if (count($log) > 500) {
            $log = array_slice($log, -500);
        }

        update_option('wpcs_receive_log', $log);
        update_option('wpcs_last_received', current_time('mysql'));
    }
}