<?php
if (!defined('ABSPATH')) exit;

class WPCS_Collector {

    private static $acf_field_labels = null;
    private static $acf_field_groups = null;

    public static function get_acf_field_labels() {
        if (self::$acf_field_labels !== null) return self::$acf_field_labels;
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT post_excerpt AS field_name, post_title AS label
             FROM {$wpdb->posts}
             WHERE post_type = 'acf-field' AND post_status = 'publish'"
        );
        $labels = [];
        foreach ($rows as $r) {
            if (!empty($r->field_name)) $labels[$r->field_name] = $r->label;
        }
        self::$acf_field_labels = $labels;
        return $labels;
    }

    public static function get_acf_field_to_group() {
        if (self::$acf_field_groups !== null) return self::$acf_field_groups;
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT f.post_excerpt AS field_name, g.post_title AS group_title
             FROM {$wpdb->posts} f
             LEFT JOIN {$wpdb->posts} g ON g.ID = f.post_parent AND g.post_type = 'acf-field-group'
             WHERE f.post_type = 'acf-field' AND f.post_status = 'publish'"
        );
        $map = [];
        foreach ($rows as $r) {
            if (!empty($r->field_name)) $map[$r->field_name] = $r->group_title ?: 'ACF';
        }
        self::$acf_field_groups = $map;
        return $map;
    }

    public static function humanize_option($name) {
        $map = [
            'blogname' => 'Название сайта',
            'blogdescription' => 'Краткое описание',
            'siteurl' => 'URL WordPress',
            'home' => 'URL сайта',
            'admin_email' => 'Email администратора',
            'new_admin_email' => 'Новый email администратора',
            'users_can_register' => 'Разрешить регистрацию',
            'default_role' => 'Роль по умолчанию',
            'timezone_string' => 'Часовой пояс',
            'gmt_offset' => 'Смещение GMT',
            'date_format' => 'Формат даты',
            'time_format' => 'Формат времени',
            'start_of_week' => 'Начало недели',
            'WPLANG' => 'Язык сайта',
            'posts_per_page' => 'Записей на страницу',
            'posts_per_rss' => 'Записей в RSS',
            'page_on_front' => 'Статическая главная',
            'page_for_posts' => 'Страница записей',
            'show_on_front' => 'Что показывать на главной',
            'default_category' => 'Рубрика по умолчанию',
            'default_post_format' => 'Формат записей',
            'permalink_structure' => 'Структура ссылок',
            'category_base' => 'База рубрик',
            'tag_base' => 'База меток',
            'upload_path' => 'Папка загрузок',
            'upload_url_path' => 'URL загрузок',
            'uploads_use_yearmonth_folders' => 'Сортировать загрузки по дате',
            'template' => 'Тема',
            'stylesheet' => 'Стили темы',
            'current_theme' => 'Активная тема',
            'theme_switched' => 'Переключение темы',
            'theme_mods_' => 'Настройки темы',
            'sidebars_widgets' => 'Виджеты',
            'widget_' => 'Виджет',
            'site_icon' => 'Иконка сайта',
            'custom_logo' => 'Логотип',
            'avatar_default' => 'Аватар по умолчанию',
            'avatar_rating' => 'Рейтинг аватаров',
            'wpseo' => 'Yoast SEO',
            'wpseo_titles' => 'Yoast SEO: заголовки',
            'wpseo_social' => 'Yoast SEO: соцсети',
            'wpseo_taxonomy_meta' => 'Yoast SEO: таксономии',
            'yoast_migrations_free' => 'Yoast SEO: миграции',
            'rank_math_' => 'Rank Math',
            'rank-math-options-general' => 'Rank Math: общие',
            'rank-math-options-titles' => 'Rank Math: заголовки',
            'rank-math-options-sitemap' => 'Rank Math: карта сайта',
            'rank-math-options-instant-indexing' => 'Rank Math: индексация',
            'active_plugins' => 'Активные плагины',
            'recently_activated' => 'Недавно активированные',
            'uninstall_plugins' => 'Плагины к удалению',
            'duplicator_pro_' => 'Duplicator Pro',
            'duplicator_expire_' => 'Duplicator: расписание',
            'PMXI_Plugin_Options' => 'WP All Import: настройки',
            'PMGI_Plugin_Options' => 'WP All Export: настройки',
            'wp_all_import_' => 'WP All Import',
            '_wpallimport_' => 'WP All Import: сессия',
            'acf_version' => 'ACF: версия',
            'ac_version' => 'ACF: версия',
            'ac_sorting_' => 'ACF: сортировка',
            'acp_' => 'ACF: лицензия',
            'cache_enabler' => 'Cache Enabler',
            'warp_imagick_options' => 'ImageMagick',
            'cookie_consent_' => 'Cookie Consent',
            'wp_mail_smtp' => 'WP Mail SMTP',
            'cron' => 'Расписание WP-Cron',
            'rewrite_rules' => 'Правила ЧПУ',
            'db_version' => 'Версия БД',
            'initial_db_version' => 'Начальная версия БД',
            'recently_edited' => 'Недавно редактированные',
            'sticky_posts' => 'Закреплённые записи',
            'wp_user_roles' => 'Роли пользователей',
            'fresh_site' => 'Свежий сайт',
            'can_compress_scripts' => 'Сжатие скриптов',
            'link_manager_enabled' => 'Менеджер ссылок',
            'default_ping_status' => 'Ping по умолчанию',
            'default_pingback_flag' => 'Pingback по умолчанию',
            'default_comment_status' => 'Комментарии по умолчанию',
            'comment_registration' => 'Регистрация для комментариев',
            'comment_moderation' => 'Модерация комментариев',
            'close_comments_days_old' => 'Закрывать комментарии через',
            'thread_comments' => 'Древовидные комментарии',
            'thread_comments_depth' => 'Глубина вложенности',
            'page_comments' => 'Разбивать комментарии',
            'comments_per_page' => 'Комментариев на страницу',
            'comments_notify' => 'Уведомления о комментариях',
            'moderation_notify' => 'Уведомления о модерации',
            'comment_order' => 'Порядок комментариев',
            'show_avatars' => 'Показывать аватары',
            'show_comments_cookies_opt_in' => 'Cookie-согласие в комментариях',
            'require_name_email' => 'Требовать имя и email',
            'comment_max_links' => 'Максимум ссылок в комментарии',
            'moderation_keys' => 'Стоп-слова модерации',
            'disallowed_keys' => 'Запрещённые слова',
            'blacklist_keys' => 'Чёрный список',
            'comment_previously_approved' => 'Ранее одобренные',
            'close_comments_for_old_posts' => 'Закрывать старые посты',
            'default_comments_page' => 'Страница комментариев',
            'ping_sites' => 'Ping-сервисы',
            'blog_public' => 'Видимость для поисковиков',
            'rss_use_excerpt' => 'RSS использует анонс',
            'thumbnail_size_w' => 'Ширина миниатюры',
            'thumbnail_size_h' => 'Высота миниатюры',
            'thumbnail_crop' => 'Обрезка миниатюры',
            'medium_size_w' => 'Средний: ширина',
            'medium_size_h' => 'Средний: высота',
            'medium_large_size_w' => 'Средне-большой: ширина',
            'medium_large_size_h' => 'Средне-большой: высота',
            'large_size_w' => 'Большой: ширина',
            'large_size_h' => 'Большой: высота',
            'image_default_align' => 'Выравнивание изображений',
            'image_default_link_type' => 'Ссылка изображений',
            'image_default_size' => 'Размер изображений',
            'use_smilies' => 'Смайлики',
            'use_balanceTags' => 'Балансировка тегов',
            'use_trackback' => 'Trackback',
            'default_link_category' => 'Рубрика ссылок',
            'nav_menu_options' => 'Настройки меню',
            'wp_page_for_privacy_policy' => 'Страница политики',
            'wp_attachment_pages_enabled' => 'Страницы вложений',
            'wp_notes_notify' => 'Уведомления заметок',
            'wp_force_deactivated_plugins' => 'Принудительно отключённые',
            'recovery_keys' => 'Ключи восстановления',
            'recovery_mode_email_last_sent' => 'Письмо восстановления',
            'auto_update_core_dev' => 'Автообновление: dev',
            'auto_update_core_major' => 'Автообновление: мажор',
            'auto_update_core_minor' => 'Автообновление: минор',
            'auto_core_update_notified' => 'Уведомление об обновлении',
            'auto_plugin_theme_update_emails' => 'Email об обновлении',
            'action_scheduler_' => 'Action Scheduler',
            'schema-' => 'Схема плагина',
            'adminhash' => 'Хэш администратора',
            'secret_key' => 'Секретный ключ',
            'hack_file' => 'Защита файлов',
            'tadv_' => 'TinyMCE Advanced',
            'wbp_' => 'Плагин WBP',
            'bv' => 'BVGredirect',
            'tlap_' => 'TLAP',
            'mtphr_post_duplicator_settings' => 'Post Duplicator',
            'bsr_data' => 'Better Search Replace',
            'scporder_options' => 'Simple Custom Post Order',
            'wpcf7' => 'Contact Form 7',
            'flamingo_' => 'Flamingo',
            'wptools_' => 'WP Tools',
            'PMXI_' => 'WP All Import',
            'pmxi_' => 'WP All Import',
            'wp-all-import-pro_' => 'WP All Import Pro',
            'survey_' => 'Survey (опросы)',
            'chat_messages' => 'Сообщения чата',
            'mailserver_' => 'Настройки почты',
            'edd_sl_' => 'Easy Digital Downloads',
            'duplicator_' => 'Duplicator',
            'wp_mail_smtp_' => 'WP Mail SMTP',
        ];
        foreach ($map as $prefix => $label) {
            if ($name === $prefix) return $label;
            if (strpos($name, $prefix) === 0) {
                $suffix = ltrim(substr($name, strlen($prefix)), '_-');
                return $suffix ? "$label — $suffix" : $label;
            }
        }
        return $name;
    }

    public static function option_group($name) {
        $groups = [
            'Настройки сайта' => ['blogname','blogdescription','siteurl','home','admin_email','WPLANG','timezone','date_format','time_format','start_of_week','users_can_register','default_role'],
            'Контент и ссылки' => ['posts_per_page','posts_per_rss','page_on_front','page_for_posts','show_on_front','permalink_structure','category_base','tag_base','upload_path','upload_url_path','uploads_'],
            'Тема и внешний вид' => ['template','stylesheet','current_theme','theme_mods_','sidebars_','widget_','site_icon','custom_logo','avatar_'],
            'SEO' => ['wpseo','yoast_','rank_math_','rank-math-options'],
            'Плагины' => ['active_plugins','recently_activated','uninstall_plugins','duplicator_','PMXI_','PMGI_','pmxi_','wp_all_import_','wp-all-import-pro_','wp_mail_smtp','cache_enabler','warp_','cookie_consent','tadv_','wbp_','tlap_','scporder','bsr_data','mtphr_post','wpcf7','flamingo_','wptools_','survey_','chat_messages','mailserver_','edd_sl_','bv'],
            'ACF' => ['acf_','ac_version','ac_sorting_','acp_'],
            'Комментарии' => ['comment_','comments_','close_comments','thread_comments','page_comments','moderation_','require_name_email','show_avatars','default_comment'],
            'Медиа' => ['thumbnail_','medium_','large_','image_default','wp_attachment_pages'],
            'Обновления' => ['auto_update_','auto_core_','auto_plugin_','recovery_'],
            'Разное' => ['cron','rewrite_rules','db_version','initial_db_version','recently_edited','sticky_posts','wp_user_roles','fresh_site','can_compress','finished_','link_manager','default_ping','default_link','default_category','default_post_format','nav_menu','wp_page_for','wp_notes','wp_force','hack_file','secret_key','adminhash','action_scheduler','schema-'],
        ];
        foreach ($groups as $label => $prefixes) {
            foreach ($prefixes as $prefix) {
                if ($name === $prefix || strpos($name, $prefix) === 0) return $label;
            }
        }
        return 'Прочее';
    }

    public static function humanize_meta($key) {
        $acf = self::get_acf_field_labels();
        if (isset($acf[$key])) return $acf[$key];

        $map = [
            'group_rf_' => 'Контакты РФ', 'group_rf' => 'Контакты РФ',
            'group_bel_' => 'Контакты Беларусь', 'group_bel' => 'Контакты Беларусь',
            'kz_' => 'Контакты Казахстан', 'kz' => 'Контакты Казахстан',
            'uzb_' => 'Контакты Узбекистан', 'uzb' => 'Контакты Узбекистан',
            'ship_rf' => 'Доставка РФ',
            'ship_bel_' => 'Доставка Беларусь', 'ship_bel' => 'Доставка Беларусь',
            'partner_rf_' => 'Партнёры РФ', 'partner_rf' => 'Партнёры РФ',
            'partner_bel_' => 'Партнёры Беларусь', 'partner_bel' => 'Партнёры Беларусь',
            'req_rf' => 'Реквизиты РФ', 'req_bel' => 'Реквизиты Беларусь',
            'content_' => 'Контент вкладок', 'content' => 'Контент вкладок',
            'advantages_title' => 'Блок «Преимущества»: заголовок',
            'advantages_' => 'Блок «Преимущества»', 'advantages' => 'Блок «Преимущества»',
            'about_title' => 'О компании: заголовок', 'about_text' => 'О компании: текст',
            'first_block_slides' => 'Слайдер главного блока',
            'slider_' => 'Слайдер', 'slider' => 'Слайдер',
            'vebinar_' => 'Вебинары', 'years' => 'История компании',
            'products_block' => 'Блок товаров',
            'stats_items' => 'Блок «Статистика»',
            'strengths_items' => 'Блок «Преимущества» (strengths)',
            'project_' => 'Проекты', 'timeline_' => 'Таймлайн',
            'today_block' => 'Блок «Сегодня»',
            'items_' => 'Пункты списка', 'tabs_' => 'Вкладки',
            'documentation_' => 'Документы', 'prog_files_' => 'Программное обеспечение',
            'cert_' => 'Сертификаты', 'field_' => 'Поле ACF',
            'l9p' => 'Таблица l9p', 'l10p' => 'Таблица l10p',
            'rank_math_title' => 'Rank Math: заголовок',
            'rank_math_description' => 'Rank Math: описание',
            'rank_math_' => 'Rank Math', '_yoast_wpseo_' => 'Yoast SEO',
            '_thumbnail_id' => 'Миниатюра записи',
            '_wp_attached_file' => 'Файл вложения',
            '_wp_attachment_metadata' => 'Метаданные вложения',
            '_edit_last' => 'Последний редактор',
            '_edit_lock' => 'Блокировка редактирования',
            '_acf_' => 'ACF',
            '_wp_page_template' => 'Шаблон страницы',
            '_menu_item_' => 'Пункт меню',
            '_wp_old_slug' => 'Старый slug', '_wp_old_date' => 'Старая дата',
            '_pingme' => 'Ping', '_encloseme' => 'Enclose',
            '_wp_http_referer' => 'HTTP Referer',
            '_customize_' => 'Customizer',
            '_transient_' => 'Транзиент', '_site_transient_' => 'Транзиент сайта',
            '_wc_' => 'WooCommerce', '_elementor_' => 'Elementor',
        ];
        foreach ($map as $prefix => $label) {
            if ($key === $prefix) return $label;
            if (strpos($key, $prefix) === 0) {
                $suffix = ltrim(substr($key, strlen($prefix)), '_-');
                return $suffix ? "$label — $suffix" : $label;
            }
        }
        return $key;
    }

    public static function meta_group($key) {
        $acf_groups = self::get_acf_field_to_group();
        if (isset($acf_groups[$key])) return $acf_groups[$key];

        $groups = [
            'Контакты РФ' => ['group_rf'],
            'Контакты Беларусь' => ['group_bel'],
            'Контакты Казахстан' => ['kz'],
            'Контакты Узбекистан' => ['uzb'],
            'Доставка' => ['ship_'],
            'Партнёры' => ['partner_'],
            'Реквизиты' => ['req_'],
            'Контент вкладок' => ['content'],
            'Преимущества' => ['advantages','strengths'],
            'О компании' => ['about_'],
            'Слайдеры' => ['slider','first_block_slides'],
            'Вебинары' => ['vebinar_'],
            'История' => ['years','timeline'],
            'Проекты' => ['project_'],
            'Товары' => ['products_block'],
            'Статистика' => ['stats_items'],
            'Документы' => ['documentation_','prog_files_','cert_'],
            'Таблицы' => ['l9p','l10p','field_'],
            'Списки и вкладки' => ['items_','tabs_'],
            'SEO' => ['rank_math','_yoast_wpseo_'],
            'Служебные' => ['_thumbnail_id','_wp_attached_file','_wp_attachment_metadata','_edit_','_wp_page_template','_menu_item_','_wp_old_','_transient_','_site_transient_'],
        ];
        foreach ($groups as $label => $prefixes) {
            foreach ($prefixes as $prefix) {
                if ($key === $prefix || strpos($key, $prefix) === 0) return $label;
            }
        }
        return 'Прочее';
    }

    public static function get_all_posts() {
        $types = ['post', 'page', 'catalog', 'news', 'blog', 'faq', 'projects'];
        $posts = get_posts([
            'post_type' => $types, 'numberposts' => -1, 'post_status' => 'any',
            'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => true,
        ]);
        $result = [];
        foreach ($posts as $p) {
            $result[] = [
                'id' => $p->ID,
                'title' => $p->post_title ?: '(без названия)',
                'type' => $p->post_type,
                'date' => $p->post_date,
            ];
        }
        return $result;
    }

    public static function get_all_options() {
        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name NOT LIKE '_transient_%'
               AND option_name NOT LIKE '_site_transient_%'
             ORDER BY option_name ASC"
        );
        $result = [];
        foreach ($rows as $name) {
            $result[] = [
                'name' => $name,
                'label' => self::humanize_option($name),
                'group' => self::option_group($name),
            ];
        }
        return $result;
    }

    public static function get_all_menus() {
        $menus = wp_get_nav_menus();
        $result = [];
        foreach ($menus as $m) {
            $result[] = ['slug' => $m->slug, 'name' => $m->name, 'count' => $m->count];
        }
        return $result;
    }

    public static function get_all_terms() {
        $taxonomies = [
            'category' => 'Рубрики записей',
            'post_tag' => 'Метки записей',
            'product-category' => 'Категории каталога',
            'blog-category' => 'Категории блога',
            'project-category' => 'Категории проектов',
            'faq_category' => 'Разделы FAQ',
            'product_faq' => 'Связанные товары FAQ',
        ];
        $result = [];
        foreach ($taxonomies as $tax => $label) {
            if (!taxonomy_exists($tax)) continue;
            $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
            if (is_wp_error($terms)) continue;
            foreach ($terms as $t) {
                $result[] = [
                    'term_id' => $t->term_id,
                    'name' => $t->name,
                    'slug' => $t->slug,
                    'taxonomy' => $tax,
                    'tax_label' => $label,
                    'parent' => $t->parent,
                    'count' => $t->count,
                ];
            }
        }
        return $result;
    }

    public static function get_all_meta_keys() {
        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_key
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key NOT LIKE '\_%'
               AND p.post_type != 'survey_result'
             ORDER BY pm.meta_key ASC"
        );
        $result = [];
        foreach ($rows as $key) {
            $result[] = [
                'key' => $key,
                'label' => self::humanize_meta($key),
                'group' => self::meta_group($key),
            ];
        }
        return $result;
    }

    public static function get_acf_groups() {
        if (!function_exists('acf_get_field_groups')) return [];
        $groups = acf_get_field_groups();
        $result = [];
        foreach ($groups as $g) {
            $result[] = ['key' => $g['key'], 'title' => $g['title']];
        }
        return $result;
    }

    // ============================================================
    //  Кэш
    // ============================================================

    public static function get_cached($key, $callback) {
        $cached = get_transient('wpcs_cache_' . $key);
        if ($cached !== false) return $cached;
        $data = call_user_func($callback);
        set_transient('wpcs_cache_' . $key, $data, HOUR_IN_SECONDS);
        return $data;
    }

    public static function clear_cache() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_wpcs_cache_%'
                OR option_name LIKE '_transient_timeout_wpcs_cache_%'"
        );
    }
}