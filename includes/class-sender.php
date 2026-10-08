<?php
if (!defined('ABSPATH')) exit;

class WPCS_Sender {

    private $receiver_url;
    private $auth_header;
    private $secret_key;

    public function __construct() {
        $this->receiver_url = trailingslashit(get_option('wpcs_receiver_url', ''));
        $user = get_option('wpcs_receiver_user', '');
        $pass = get_option('wpcs_receiver_pass', '');
        $this->auth_header = 'Basic ' . base64_encode($user . ':' . $pass);
        $this->secret_key = get_option('wpcs_secret_key', '');
    }

    public function send($endpoint, $data) {
        if (empty($this->receiver_url)) {
            return new WP_Error('no_url', 'URL приёмника не задан');
        }

        $url = $this->receiver_url . 'wp-json/wpcs/v1/' . ltrim($endpoint, '/');

        $response = wp_remote_post($url, [
            'timeout'   => 120,
            'sslverify' => false,
            'headers'   => [
                'Authorization'    => $this->auth_header,
                'X-WPCS-Sync-Key' => $this->secret_key,
                'Content-Type'     => 'application/json',
            ],
            'body' => wp_json_encode($this->replace_urls($data)),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            return new WP_Error('http_error', "HTTP $code: " . substr($body, 0, 300));
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return new WP_Error('invalid_json', 'Ответ не JSON: ' . substr($body, 0, 200));
        }

        return $json;
    }

    /**
     * Рекурсивно заменяет URL источника на URL приёмника.
     */
    private function replace_urls($data) {
        // Список замен
        $replacements = [
            'https://WPCS.by' => 'https://WPCSrussia.ru',
            'http://WPCS.by'  => 'https://WPCSrussia.ru',
            '//WPCS.by'       => '//WPCSrussia.ru',
            'WPCS.by'         => 'WPCSrussia.ru',
        ];

        return $this->replace_urls_recursive($data, $replacements);
    }

    /**
     * Рекурсивная замена в массивах, объектах и строках.
     */
    private function replace_urls_recursive($data, $replacements) {
        // Строка — заменяем и возвращаем
        if (is_string($data)) {
            // Пропускаем сериализованные данные (там строка с длинами)
            if (preg_match('/^a:\d+:\{/', $data) || preg_match('/^O:\d+:/', $data)) {
                // Это сериализованные данные — пытаемся десериализовать
                $unserialized = @maybe_unserialize($data);
                if ($unserialized !== $data) {
                    // Успешно десериализовали — рекурсивно заменяем и сериализуем обратно
                    $replaced = $this->replace_urls_recursive($unserialized, $replacements);
                    return serialize($replaced);
                }
                // Не удалось — заменяем в сырой строке (может сломать длины, но лучше, чем ничего)
                foreach ($replacements as $from => $to) {
                    $data = str_replace($from, $to, $data);
                }
                return $data;
            }

            foreach ($replacements as $from => $to) {
                $data = str_replace($from, $to, $data);
            }
            return $data;
        }

        // Массив — рекурсивно по каждому элементу
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                // Ключи тоже заменяем (на случай, если в ключе URL)
                $new_key = is_string($key) ? $this->replace_urls_recursive($key, $replacements) : $key;
                $result[$new_key] = $this->replace_urls_recursive($value, $replacements);
            }
            return $result;
        }

        // Объект — рекурсивно по свойствам
        if (is_object($data)) {
            $result = clone $data;
            foreach (get_object_vars($data) as $key => $value) {
                $result->$key = $this->replace_urls_recursive($value, $replacements);
            }
            return $result;
        }

        // Числа, bool, null — возвращаем как есть
        return $data;
    }

    // ============================================================
    //  ТЕРМИНЫ
    // ============================================================

    public function collect_terms() {
        $excluded = get_option('wpcs_excluded_terms', []);
        $taxonomies = ['category', 'post_tag', 'product-category', 'blog-category', 'project-category', 'faq_category'];

        $result = [];
        foreach ($taxonomies as $tax) {
            if (!taxonomy_exists($tax)) continue;
            $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
            if (is_wp_error($terms)) continue;

            foreach ($terms as $t) {
                if (in_array($t->term_id, $excluded, true)) continue;
                $result[] = [
                    'term_id'     => $t->term_id,
                    'name'        => $t->name,
                    'slug'        => $t->slug,
                    'taxonomy'    => $tax,
                    'parent'      => $t->parent,
                    'description' => $t->description,
                ];
            }
        }
        return $result;
    }

    // ============================================================
    //  ПОСТЫ
    // ============================================================

    /**
     * Сколько постов данного типа к отправке.
     */
    public function count_posts($type) {
        $excluded = get_option('wpcs_excluded_pages', []);
        $query = new WP_Query([
            'post_type'      => $type,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post__not_in'   => $excluded,
        ]);
        return $query->found_posts;
    }

    /**
     * Собирает батч постов.
     */
    public function collect_posts_batch($type, $limit = 20, $offset = 0) {
        $excluded = get_option('wpcs_excluded_pages', []);
        $excluded_meta = get_option('wpcs_excluded_meta', []);

        $posts = get_posts([
            'post_type'        => $type,
            'numberposts'      => $limit,
            'offset'           => $offset,
            'post_status'      => 'any',
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'post__not_in'     => $excluded,
            'suppress_filters' => true,
        ]);

        $result = [];
        foreach ($posts as $p) {
            // Meta (исключаем служебные и исключённые)
            $meta = get_post_meta($p->ID);
            $meta_clean = [];
            foreach ($meta as $key => $values) {
                if (in_array($key, $excluded_meta, true)) continue;
                if (strpos($key, '_') === 0) continue;
                $meta_clean[$key] = maybe_unserialize($values[0]);
            }

            // Термины (передаём slug, а не ID — на приёмнике ID могут отличаться)
            $terms = [];
            $taxonomies = get_object_taxonomies($type);
            foreach ($taxonomies as $tax) {
                $term_objects = wp_get_object_terms($p->ID, $tax);
                if (is_wp_error($term_objects) || empty($term_objects)) continue;

                $slugs = [];
                foreach ($term_objects as $t) {
                    $slugs[] = $t->slug;
                }
                $terms[$tax] = $slugs;
            }

            // Миниатюра (передаём URL, не ID)
            $thumb_id = get_post_thumbnail_id($p->ID);
            $thumb_url = $thumb_id ? wp_get_attachment_url($thumb_id) : '';

            $result[] = [
                'ID'             => $p->ID,
                'post_title'     => $p->post_title,
                'post_content'   => $p->post_content,
                'post_excerpt'   => $p->post_excerpt,
                'post_status'    => $p->post_status,
                'post_type'      => $p->post_type,
                'post_name'      => $p->post_name,
                'post_date'      => $p->post_date,
                'post_author'    => $p->post_author,
                'menu_order'     => $p->menu_order,
                'comment_status' => $p->comment_status,
                'ping_status'    => $p->ping_status,
                'meta'           => $meta_clean,
                'terms'          => $terms,
                'thumbnail_url'  => $thumb_url,
            ];
        }
        return $result;
    }

    /**
     * Отправляет все посты одного типа батчами.
     */
    public function send_posts($type, $batch_size = 20) {
        $total = $this->count_posts($type);
        $sent = 0;
        $created = 0;
        $updated = 0;
        $errors = [];

        $offset = 0;
        $batch_num = 0;

        while ($offset < $total) {
            $batch_num++;
            $batch = $this->collect_posts_batch($type, $batch_size, $offset);

            if (empty($batch)) break;

            $result = $this->send('posts', $batch);

            if (is_wp_error($result)) {
                $errors[] = "Батч #$batch_num (offset $offset): " . $result->get_error_message();
                $offset += $batch_size;
                continue;
            }

            $sent += count($batch);
            $created += $result['results']['created'] ?? 0;
            $updated += $result['results']['updated'] ?? 0;

            if (!empty($result['results']['errors'])) {
                foreach ($result['results']['errors'] as $err) {
                    $errors[] = "Батч #$batch_num: $err";
                }
            }

            $offset += $batch_size;
        }

        return [
            'total'   => $total,
            'sent'    => $sent,
            'created' => $created,
            'updated' => $updated,
            'errors'  => $errors,
        ];
    }
    // ============================================================
//  ОПЦИИ
// ============================================================

    /**
     * Сбор опций с учётом исключений.
     * Возвращает только те опции, которые безопасно сериализуются в JSON.
     */
    public function collect_options() {
        $excluded = get_option('wpcs_excluded_options', []);
        $result = [];

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE option_name NOT LIKE '_transient_%'
           AND option_name NOT LIKE '_site_transient_%'
         ORDER BY option_name ASC"
        );

        if (empty($rows)) {
            return $result;
        }

        foreach ($rows as $row) {
            if (in_array($row->option_name, $excluded, true)) continue;
            if ($this->is_option_unsafe($row->option_name)) continue;

            $value = maybe_unserialize($row->option_value);

            $test = wp_json_encode($value);
            if ($test === false) continue;

            $result[$row->option_name] = $value;
        }

        return $result;
    }

    /**
     * Опции, которые опасно синхронизировать — могут сломать сайт.
     */
    private function is_option_unsafe($name) {
        $unsafe = [
            'cron',
            'rewrite_rules',
            'recently_activated',
            'uninstall_plugins',
            'active_plugins',
            'wp_user_roles',
            'adminhash',
            'secret_key',
            'recovery_keys',
            'recovery_mode_email_last_sent',
            'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt',
            'nonce_key', 'nonce_salt',
            'can_compress_scripts',
            'auto_update_core_dev', 'auto_update_core_major', 'auto_update_core_minor',
        ];

        if (in_array($name, $unsafe, true)) return true;

        // Защита от сессий и кэшей
        if (strpos($name, '_transient_') === 0) return true;
        if (strpos($name, '_site_transient_') === 0) return true;
        if (strpos($name, 'action_scheduler_') === 0) return true;
        if (strpos($name, 'wp_mail_smtp_debug') === 0) return true;

        return false;
    }
}