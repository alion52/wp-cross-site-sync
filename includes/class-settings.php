<?php
if (!defined('ABSPATH')) exit;

class WPCS_Settings {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_wpcs_save', [$this, 'handle_save']);
        add_action('admin_post_wpcs_clear_cache', [$this, 'handle_clear_cache']);
    }

    public function add_menu() {
        add_menu_page(
            'WP Cross-Site Sync',
            'WP Cross-Site Sync',
            'manage_options',
            'wpcs',
            [$this, 'render_page'],
            'dashicons-update',
            30
        );
    }

    public function handle_save() {
        if (!current_user_can('manage_options')) wp_die('Access denied');
        check_admin_referer('wpcs_save');

        update_option('wpcs_mode', sanitize_text_field($_POST['mode'] ?? 'source'));
        update_option('wpcs_receiver_url', esc_url_raw($_POST['receiver_url'] ?? ''));
        update_option('wpcs_receiver_user', sanitize_text_field($_POST['receiver_user'] ?? ''));
        update_option('wpcs_receiver_pass', sanitize_text_field($_POST['receiver_pass'] ?? ''));

        $pages = isset($_POST['excluded_pages']) && is_array($_POST['excluded_pages'])
            ? array_map('intval', $_POST['excluded_pages']) : [];
        $opts = isset($_POST['excluded_options']) && is_array($_POST['excluded_options'])
            ? array_map('sanitize_text_field', $_POST['excluded_options']) : [];
        $menus = isset($_POST['excluded_menus']) && is_array($_POST['excluded_menus'])
            ? array_map('sanitize_text_field', $_POST['excluded_menus']) : [];
        $meta = isset($_POST['excluded_meta']) && is_array($_POST['excluded_meta'])
            ? array_map('sanitize_text_field', $_POST['excluded_meta']) : [];
        $acf = isset($_POST['excluded_acf_groups']) && is_array($_POST['excluded_acf_groups'])
            ? array_map('sanitize_text_field', $_POST['excluded_acf_groups']) : [];
        $terms = isset($_POST['excluded_terms']) && is_array($_POST['excluded_terms'])
            ? array_map('intval', $_POST['excluded_terms']) : [];

        update_option('wpcs_excluded_pages', $pages);
        update_option('wpcs_excluded_options', $opts);
        update_option('wpcs_excluded_menus', $menus);
        update_option('wpcs_excluded_meta', $meta);
        update_option('wpcs_excluded_acf_groups', $acf);
        update_option('wpcs_excluded_terms', $terms);

        wp_redirect(admin_url('admin.php?page=wpcs&saved=1'));
        exit;
    }

    public function handle_clear_cache() {
        if (!current_user_can('manage_options')) wp_die('Access denied');
        check_admin_referer('wpcs_clear_cache');

        WPCS_Collector::clear_cache();

        wp_redirect(admin_url('admin.php?page=wpcs&cache_cleared=1'));
        exit;
    }

    public function render_page() {
        $mode = get_option('wpcs_mode', 'source');

        if ($mode === 'receiver') {
            $this->render_receiver_page();
        } else {
            $this->render_source_page();
        }
    }

    // ============================================================
    //  ПРИЁМНИК
    // ============================================================

    private function render_receiver_page() {
        $last_received = get_option('wpcs_last_received', '');
        $receive_log = get_option('wpcs_receive_log', []);
        ?>
        <div class="wrap">
            <h1>WP Cross-Site Sync — Приёмник</h1>

            <?php if (isset($_GET['saved'])): ?>
                <div class="notice notice-success is-dismissible"><p>Настройки сохранены.</p></div>
            <?php endif; ?>

            <div class="notice notice-info">
                <p><strong>Этот сайт работает в режиме приёмника.</strong></p>
                <p>Он принимает данные от источника (<code>WPCS.by</code>). Настройки исключений здесь не требуются.</p>
            </div>

            <h2>Режим работы</h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('wpcs_save'); ?>
                <input type="hidden" name="action" value="wpcs_save">
                <p>
                    <label><input type="radio" name="mode" value="source"> <strong>Источник</strong> (WPCS.by)</label><br>
                    <label><input type="radio" name="mode" value="receiver" checked> <strong>Приёмник</strong> (WPCSrussia.ru)</label>
                </p>
                <p class="submit">
                    <button type="submit" class="button button-primary">Сохранить</button>
                </p>
            </form>

            <hr>
            <h2>Статус</h2>
            <table class="widefat striped" style="max-width:800px;">
                <tr>
                    <td width="30%">REST API доступен по адресу:</td>
                    <td>
                        <?php
                        $rest_url = rest_url('wpcs/v1/');
                        echo '<code>' . esc_html($rest_url) . '</code>';
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>Проверка эндпоинта:</td>
                    <td>
                        <a href="<?php echo esc_url(rest_url('wpcs/v1/ping')); ?>" target="_blank" class="button">
                            Открыть /ping
                        </a>
                    </td>
                </tr>
                <tr>
                    <td>Последняя синхронизация:</td>
                    <td><?php echo $last_received ? esc_html($last_received) : '<em>Ещё не было</em>'; ?></td>
                </tr>
            </table>

            <hr>
            <h2>Лог приёма</h2>
            <pre style="background:#f1f1f1;padding:10px;max-height:400px;overflow:auto;">
<?php
if (empty($receive_log)) {
    echo 'Лог пуст.';
} else {
    foreach (array_slice(array_reverse($receive_log), 0, 100) as $line) {
        echo esc_html($line) . "\n";
    }
}
?>
            </pre>
        </div>
        <?php
    }

    // ============================================================
    //  ИСТОЧНИК
    // ============================================================

    private function render_source_page() {
        $receiver_url  = get_option('wpcs_receiver_url', '');
        $receiver_user = get_option('wpcs_receiver_user', '');
        $receiver_pass = get_option('wpcs_receiver_pass', '');

        $excluded_pages = get_option('wpcs_excluded_pages', []);
        $excluded_opts  = get_option('wpcs_excluded_options', []);
        $excluded_menus = get_option('wpcs_excluded_menus', []);
        $excluded_meta  = get_option('wpcs_excluded_meta', []);
        $excluded_acf   = get_option('wpcs_excluded_acf_groups', []);
        $excluded_terms = get_option('wpcs_excluded_terms', []);

        $all_posts = WPCS_Collector::get_cached('posts', ['WPCS_Collector', 'get_all_posts']);
        $all_opts  = WPCS_Collector::get_cached('options', ['WPCS_Collector', 'get_all_options']);
        $all_menus = WPCS_Collector::get_cached('menus', ['WPCS_Collector', 'get_all_menus']);
        $all_meta  = WPCS_Collector::get_cached('meta', ['WPCS_Collector', 'get_all_meta_keys']);
        $all_acf   = WPCS_Collector::get_cached('acf', ['WPCS_Collector', 'get_acf_groups']);
        $all_terms = WPCS_Collector::get_cached('terms', ['WPCS_Collector', 'get_all_terms']);

        $opts_by_group = [];
        foreach ($all_opts as $o) {
            if (!is_array($o) || !isset($o['group'], $o['name'], $o['label'])) continue;
            $opts_by_group[$o['group']][] = $o;
        }
        ksort($opts_by_group);

        $meta_by_group = [];
        foreach ($all_meta as $m) {
            if (!is_array($m) || !isset($m['group'], $m['key'], $m['label'])) continue;
            $meta_by_group[$m['group']][] = $m;
        }
        ksort($meta_by_group);

        $posts_by_type = [];
        foreach ($all_posts as $p) {
            if (!is_array($p) || !isset($p['type'])) continue;
            $posts_by_type[$p['type']][] = $p;
        }
        ksort($posts_by_type);

        $terms_by_tax = [];
        foreach ($all_terms as $t) {
            if (!is_array($t) || !isset($t['tax_label'])) continue;
            $terms_by_tax[$t['tax_label']][] = $t;
        }
        ksort($terms_by_tax);

        $last_run = get_option('wpcs_last_run', '');
        $log = get_option('wpcs_log', []);
        ?>
        <div class="wrap">
            <h1>WP Cross-Site Sync — Источник</h1>

            <?php if (isset($_GET['saved'])): ?>
                <div class="notice notice-success is-dismissible"><p>Настройки сохранены.</p></div>
            <?php endif; ?>

            <?php if (isset($_GET['cache_cleared'])): ?>
                <div class="notice notice-success is-dismissible"><p>Кэш списков очищен.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('wpcs_save'); ?>
                <input type="hidden" name="action" value="wpcs_save">

                <h2>Режим работы</h2>
                <p>
                    <label><input type="radio" name="mode" value="source" checked> <strong>Источник</strong> (WPCS.by)</label><br>
                    <label><input type="radio" name="mode" value="receiver"> <strong>Приёмник</strong> (WPCSrussia.ru)</label>
                </p>

                <h2>Подключение к приёмнику</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="receiver_url">URL приёмника</label></th>
                        <td><input type="url" id="receiver_url" name="receiver_url" value="<?php echo esc_attr($receiver_url); ?>" class="regular-text" placeholder="https://WPCSrussia.ru"></td>
                    </tr>
                    <tr>
                        <th><label for="receiver_user">Логин</label></th>
                        <td><input type="text" id="receiver_user" name="receiver_user" value="<?php echo esc_attr($receiver_user); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="receiver_pass">Application Password</label></th>
                        <td>
                            <input type="password" id="receiver_pass" name="receiver_pass" value="<?php echo esc_attr($receiver_pass); ?>" class="regular-text">
                            <p class="description">Создаётся в профиле пользователя на сайте-приёмнике.</p>
                        </td>
                    </tr>
                </table>

                <hr>

                <h2>Ручной запуск</h2>
                <p>
                    <button type="button" id="wpcs-all" class="button button-primary button-hero">
                        🚀 Синхронизировать всё
                    </button>
                    <button type="button" id="wpcs-media" class="button button-secondary button-hero">
                        🖼 Медиа (rsync)
                    </button>
                    <button type="button" id="wpcs-run" class="button button-secondary">
                        🔌 Проверить связь
                    </button>
                </p>
                <p>
                    <button type="button" id="wpcs-test-terms" class="button button-secondary">
                        🧪 Термины
                    </button>
                    <button type="button" id="wpcs-test-posts" class="button button-secondary">
                        🧪 Посты
                    </button>
                    <button type="button" id="wpcs-test-options" class="button button-secondary">
                        🧪 Опции
                    </button>
                    <button type="button" id="wpcs-clear-log" class="button">
                        🗑 Очистить лог
                    </button>
                </p>
                <p><span id="wpcs-status" style="font-style:italic;"></span></p>
                <p><em>Последний запуск: <?php echo $last_run ? esc_html($last_run) : 'никогда'; ?></em></p>
                <p><em>Автосинхронизация: <strong>01:00 и 13:00</strong> (контент), <strong>каждый час</strong> (медиа)</em></p>

                <div id="wpcs-log" style="background:#f1f1f1;padding:10px;max-height:300px;overflow:auto;display:none;margin-top:10px;">
                    <pre id="wpcs-log-content" style="margin:0;"></pre>
                </div>

                <hr>

                <h2>Исключения</h2>
                <p>
                    <input type="text" id="WPCS-search" placeholder="🔍 Поиск по всем спискам..." style="width:400px;padding:6px;">
                    <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=wpcs_clear_cache'), 'wpcs_clear_cache'); ?>"
                       class="button" style="margin-left:10px;"
                       onclick="return confirm('Обновить все списки?');">
                        🔄 Обновить списки
                    </a>
                </p>

                <!-- СТРАНИЦЫ -->
                <h3>Страницы и записи (<?php echo count($all_posts); ?>)</h3>
                <?php foreach ($posts_by_type as $type => $items): ?>
                    <details style="margin:8px 0;border:1px solid #ccc;background:#fff;" <?php echo ($type === 'page') ? 'open' : ''; ?>>
                        <summary style="padding:8px;cursor:pointer;font-weight:bold;">
                            <?php echo esc_html($type); ?> (<?php echo count($items); ?>)
                        </summary>
                        <div style="max-height:300px;overflow:auto;padding:10px;">
                            <?php foreach ($items as $p): ?>
                                <label class="WPCS-item" style="display:block;padding:3px 0;">
                                    <input type="checkbox" name="excluded_pages[]" value="<?php echo (int)$p['id']; ?>"
                                        <?php checked(in_array($p['id'], $excluded_pages)); ?>>
                                    <strong><?php echo esc_html($p['title']); ?></strong>
                                    <span style="color:#999;font-size:11px;">(ID <?php echo (int)$p['id']; ?>)</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>

                <!-- ТАКСОНОМИИ -->
                <h3>Категории и метки (<?php echo count($all_terms); ?>)</h3>
                <?php foreach ($terms_by_tax as $tax_label => $items): ?>
                    <details style="margin:8px 0;border:1px solid #ccc;background:#fff;">
                        <summary style="padding:8px;cursor:pointer;font-weight:bold;">
                            <?php echo esc_html($tax_label); ?> (<?php echo count($items); ?>)
                        </summary>
                        <div style="max-height:300px;overflow:auto;padding:10px;">
                            <?php foreach ($items as $t): ?>
                                <label class="WPCS-item" style="display:block;padding:3px 0;">
                                    <input type="checkbox" name="excluded_terms[]" value="<?php echo (int)$t['term_id']; ?>"
                                        <?php checked(in_array($t['term_id'], $excluded_terms)); ?>>
                                    <strong><?php echo esc_html($t['name']); ?></strong>
                                    <span style="color:#999;font-size:11px;">(ID <?php echo (int)$t['term_id']; ?>, <?php echo esc_html($t['slug']); ?>)</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>

                <!-- ОПЦИИ -->
                <h3>Опции (<?php echo count($all_opts); ?>)</h3>
                <?php foreach ($opts_by_group as $group => $items): ?>
                    <details style="margin:8px 0;border:1px solid #ccc;background:#fff;">
                        <summary style="padding:8px;cursor:pointer;font-weight:bold;">
                            <?php echo esc_html($group); ?> (<?php echo count($items); ?>)
                        </summary>
                        <div style="max-height:300px;overflow:auto;padding:10px;">
                            <?php foreach ($items as $o): ?>
                                <label class="WPCS-item" style="display:block;padding:3px 0;">
                                    <input type="checkbox" name="excluded_options[]" value="<?php echo esc_attr($o['name']); ?>"
                                        <?php checked(in_array($o['name'], $excluded_opts)); ?>>
                                    <strong><?php echo esc_html($o['label']); ?></strong>
                                    <?php if ($o['label'] !== $o['name']): ?>
                                        <span style="color:#999;font-size:11px;">(<?php echo esc_html($o['name']); ?>)</span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>

                <!-- МЕНЮ -->
                <h3>Меню (<?php echo count($all_menus); ?>)</h3>
                <div style="max-height:200px;overflow:auto;border:1px solid #ccc;padding:10px;background:#fff;">
                    <?php foreach ($all_menus as $m): ?>
                        <label class="WPCS-item" style="display:block;padding:3px 0;">
                            <input type="checkbox" name="excluded_menus[]" value="<?php echo esc_attr($m['slug']); ?>"
                                <?php checked(in_array($m['slug'], $excluded_menus)); ?>>
                            <strong><?php echo esc_html($m['name']); ?></strong>
                            <span style="color:#999;font-size:11px;">(<?php echo esc_html($m['slug']); ?>)</span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <!-- META-ПОЛЯ -->
                <h3>Meta-поля (<?php echo count($all_meta); ?>)</h3>
                <?php foreach ($meta_by_group as $group => $items): ?>
                    <details style="margin:8px 0;border:1px solid #ccc;background:#fff;">
                        <summary style="padding:8px;cursor:pointer;font-weight:bold;">
                            <?php echo esc_html($group); ?> (<?php echo count($items); ?>)
                        </summary>
                        <div style="max-height:300px;overflow:auto;padding:10px;">
                            <?php foreach ($items as $m): ?>
                                <label class="WPCS-item" style="display:block;padding:3px 0;">
                                    <input type="checkbox" name="excluded_meta[]" value="<?php echo esc_attr($m['key']); ?>"
                                        <?php checked(in_array($m['key'], $excluded_meta)); ?>>
                                    <strong><?php echo esc_html($m['label']); ?></strong>
                                    <?php if ($m['label'] !== $m['key']): ?>
                                        <span style="color:#999;font-size:11px;">(<?php echo esc_html($m['key']); ?>)</span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>

                <!-- ACF-ГРУППЫ -->
                <?php if (!empty($all_acf)): ?>
                    <h3>ACF-группы (<?php echo count($all_acf); ?>)</h3>
                    <div style="max-height:300px;overflow:auto;border:1px solid #ccc;padding:10px;background:#fff;">
                        <?php foreach ($all_acf as $g): ?>
                            <label class="WPCS-item" style="display:block;padding:3px 0;">
                                <input type="checkbox" name="excluded_acf_groups[]" value="<?php echo esc_attr($g['key']); ?>"
                                    <?php checked(in_array($g['key'], $excluded_acf)); ?>>
                                <strong><?php echo esc_html($g['title']); ?></strong>
                                <span style="color:#999;font-size:11px;">(<?php echo esc_html($g['key']); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <p class="submit">
                    <button type="submit" class="button button-primary">Сохранить настройки</button>
                </p>
            </form>

            <hr>
            <h2>Лог синхронизации</h2>
            <pre style="background:#f1f1f1;padding:10px;max-height:400px;overflow:auto;">
            <?php
            if (empty($log)) {
                echo 'Лог пуст.';
            } else {
                foreach (array_slice(array_reverse($log), 0, 100) as $line) {
                    echo esc_html($line) . "\n";
                }
            }
            ?>
            </pre>
        </div>

        <script>
            document.getElementById('WPCS-search')?.addEventListener('input', function(e) {
                const q = e.target.value.toLowerCase();
                document.querySelectorAll('.WPCS-item').forEach(function(item) {
                    item.style.display = item.textContent.toLowerCase().includes(q) ? 'block' : 'none';
                });
            });

            function WPCSAjax(action, btn, busyText, idleText, extraData) {
                const status = document.getElementById('wpcs-status');
                const logBox = document.getElementById('wpcs-log');
                const logContent = document.getElementById('wpcs-log-content');

                btn.disabled = true;
                btn.textContent = busyText;
                status.textContent = 'Выполняется...';
                logBox.style.display = 'block';
                logContent.textContent = '';

                const data = new FormData();
                data.append('action', action);
                data.append('nonce', '<?php echo wp_create_nonce("wpcs_run"); ?>');

                if (extraData) {
                    for (const [key, value] of Object.entries(extraData)) {
                        data.append(key, value);
                    }
                }

                fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(response => {
                        if (response.success) {
                            status.innerHTML = '<span style="color:green;">✓ ' + response.data.message + '</span>';
                        } else {
                            status.innerHTML = '<span style="color:red;">✗ ' + (response.data.message || 'Ошибка') + '</span>';
                        }
                        logContent.textContent = JSON.stringify(response.data, null, 2);
                    })
                    .catch(err => {
                        status.innerHTML = '<span style="color:red;">✗ ' + err.message + '</span>';
                        logContent.textContent = err.stack || err.message;
                    })
                    .finally(() => {
                        btn.disabled = false;
                        btn.textContent = idleText;
                    });
            }

            document.getElementById('wpcs-run')?.addEventListener('click', function() {
                WPCSAjax('wpcs_run', this, '⏳ Проверка...', '🔌 Проверить связь');
            });

            document.getElementById('wpcs-all')?.addEventListener('click', function() {
                if (!confirm('Запустить полную синхронизацию? Это может занять несколько минут.')) return;
                WPCSAjax('wpcs_all', this, '⏳ Синхронизация...', '🚀 Синхронизировать всё');
            });

            document.getElementById('wpcs-media')?.addEventListener('click', function() {
                if (!confirm('Синхронизировать медиа через rsync? Это может занять время.')) return;
                WPCSAjax('wpcs_media', this, '⏳ Синхронизация...', '🖼 Медиа (rsync)');
            });

            document.getElementById('wpcs-test-terms')?.addEventListener('click', function() {
                WPCSAjax('wpcs_test_terms', this, '⏳ Отправка...', '🧪 Термины');
            });

            document.getElementById('wpcs-test-posts')?.addEventListener('click', function() {
                const type = prompt('Тип постов:\npage, catalog, news, blog, faq, projects, post', 'page');
                if (!type) return;
                const batch = prompt('Размер батча:', '20');
                if (!batch) return;
                WPCSAjax('wpcs_test_posts', this, '⏳ Отправка...', '🧪 Посты',
                    { type: type, batch_size: batch });
            });

            document.getElementById('wpcs-test-options')?.addEventListener('click', function() {
                const batch = prompt('Размер батча:', '100');
                if (!batch) return;
                WPCSAjax('wpcs_test_options', this, '⏳ Отправка...', '🧪 Опции',
                    { batch_size: batch });
            });

            document.getElementById('wpcs-clear-log')?.addEventListener('click', function() {
                const data = new FormData();
                data.append('action', 'wpcs_clear_log');
                data.append('nonce', '<?php echo wp_create_nonce("wpcs_run"); ?>');
                fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(() => location.reload());
            });
        </script>
        <?php
    }
}