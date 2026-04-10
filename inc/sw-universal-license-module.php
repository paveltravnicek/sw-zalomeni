<?php
/**
 * SW Universal License Module
 * Reusable licensing helper for Smart Websites plugins.
 *
 * Drop this file into a plugin (e.g. /inc/sw-universal-license-module.php)
 * and include it from the main plugin file.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('SW_Universal_License_Module')) {
    final class SW_Universal_License_Module {
        private static array $config = [];
        private static ?array $state_cache = null;

        public static function bootstrap(array $config): void {
            $defaults = [
                'plugin_slug'           => '',
                'plugin_name'           => '',
                'plugin_file'           => '',
                'hub_base'              => 'https://smart-websites.cz',
                'verify_endpoint'       => '/wp-json/swlic/v2/plugin-license/verify',
                'settings_capability'   => 'manage_options',
                'recheck_interval'      => 12 * HOUR_IN_SECONDS,
                'invalid_state'         => 'passive', // passive|off
                'option_license_key'    => '',
                'option_license_cache'  => '',
                'option_license_last'   => '',
                'option_license_error'  => '',
                'settings_page_slug'    => '',
                'menu_page_hook'        => '',
            ];

            self::$config = array_merge($defaults, $config);

            if (self::$config['plugin_slug'] === '' || self::$config['plugin_file'] === '') {
                return;
            }

            if (self::$config['plugin_name'] === '') {
                self::$config['plugin_name'] = self::$config['plugin_slug'];
            }
            if (self::$config['option_license_key'] === '') {
                self::$config['option_license_key'] = 'swlic_' . self::$config['plugin_slug'] . '_license_key';
            }
            if (self::$config['option_license_cache'] === '') {
                self::$config['option_license_cache'] = 'swlic_' . self::$config['plugin_slug'] . '_license_cache';
            }
            if (self::$config['option_license_last'] === '') {
                self::$config['option_license_last'] = 'swlic_' . self::$config['plugin_slug'] . '_license_last_check';
            }
            if (self::$config['option_license_error'] === '') {
                self::$config['option_license_error'] = 'swlic_' . self::$config['plugin_slug'] . '_license_last_error';
            }

            add_action('admin_init', [__CLASS__, 'handle_admin_post']);
            add_action('admin_init', [__CLASS__, 'maybe_block_direct_deactivation']);
            add_action('admin_init', [__CLASS__, 'maybe_revalidate_on_admin']);
            add_filter('plugin_action_links', [__CLASS__, 'filter_plugin_action_links'], 10, 2);
            add_filter('network_admin_plugin_action_links', [__CLASS__, 'filter_plugin_action_links'], 10, 2);

            $cron_hook = self::cron_hook();
            add_action($cron_hook, [__CLASS__, 'cron_revalidate']);

            register_activation_hook(self::$config['plugin_file'], [__CLASS__, 'on_activation']);
            register_deactivation_hook(self::$config['plugin_file'], [__CLASS__, 'on_deactivation']);
        }

        public static function on_activation(): void {
            self::schedule_cron();
        }

        public static function on_deactivation(): void {
            wp_clear_scheduled_hook(self::cron_hook());
        }

        private static function cron_hook(): string {
            return 'swlic_revalidate_' . sanitize_key(self::$config['plugin_slug']);
        }

        private static function schedule_cron(): void {
            $hook = self::cron_hook();
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time() + 300, 'twicedaily', $hook);
            }
        }

        public static function get_license_key(): string {
            return trim((string) get_option(self::$config['option_license_key'], ''));
        }

        public static function get_license_cache(): array {
            $cache = get_option(self::$config['option_license_cache'], []);
            return is_array($cache) ? $cache : [];
        }

        public static function get_last_check(): int {
            return (int) get_option(self::$config['option_license_last'], 0);
        }

        public static function get_last_error(): string {
            return (string) get_option(self::$config['option_license_error'], '');
        }

        public static function get_management_status(): string {
            if (function_exists('sw_guard_get_management_status')) {
                $status = (string) sw_guard_get_management_status();
                return $status !== '' ? $status : 'UNKNOWN';
            }
            return 'MISSING';
        }

        public static function get_guard_service_state(): ?string {
            if (function_exists('sw_guard_get_service_state')) {
                $state = (string) sw_guard_get_service_state(self::$config['plugin_slug']);
                if (in_array($state, ['active', 'passive', 'off'], true)) {
                    return $state;
                }
            }
            return null;
        }

        public static function get_effective_state(bool $force_refresh = false): array {
            if (!$force_refresh && self::$state_cache !== null) {
                return self::$state_cache;
            }

            $management_status = self::get_management_status();
            $guard_state = self::get_guard_service_state();

            if ($management_status === 'ACTIVE') {
                $state = [
                    'source'            => 'management',
                    'state'             => $guard_state ?: 'active',
                    'status_label'      => 'Správa webu',
                    'license_valid'     => true,
                    'license_key'       => '',
                    'license_number'    => '',
                    'valid_to'          => '',
                    'domain'            => '',
                    'message'           => 'Plugin je provozován v rámci správy webu. Samostatný licenční kód zde není potřeba.',
                ];
                self::$state_cache = $state;
                return $state;
            }

            // Když správa webu není aktivní, plugin se řídí samostatnou licencí.
            $cache = self::get_license_cache();
            $valid = !empty($cache['valid']);
            $state = [
                'source'            => 'standalone',
                'state'             => $valid ? 'active' : self::$config['invalid_state'],
                'status_label'      => $valid ? 'Samostatná licence' : 'Bez platné licence',
                'license_valid'     => $valid,
                'license_key'       => self::get_license_key(),
                'license_number'    => (string) ($cache['license_number'] ?? ''),
                'valid_to'          => (string) ($cache['valid_to'] ?? ''),
                'domain'            => (string) ($cache['domain'] ?? ''),
                'message'           => $valid
                    ? 'Plugin je aktivní na základě samostatné licence.'
                    : 'Plugin nemá platnou samostatnou licenci.',
            ];

            self::$state_cache = $state;
            return $state;
        }

        public static function is_feature_enabled(): bool {
            return self::get_effective_state()['state'] === 'active';
        }

        public static function can_edit_settings(): bool {
            return self::get_effective_state()['state'] === 'active';
        }

        public static function is_management_active(): bool {
            return self::get_management_status() === 'ACTIVE';
        }

        public static function filter_plugin_action_links(array $actions, string $plugin_file): array {
            if ($plugin_file !== plugin_basename(self::$config['plugin_file'])) {
                return $actions;
            }

            if (self::is_management_active()) {
                unset($actions['deactivate']);
            }

            return $actions;
        }

        public static function maybe_block_direct_deactivation(): void {
            if (!is_admin() || !self::is_management_active()) return;

            $target = plugin_basename(self::$config['plugin_file']);
            $action = isset($_REQUEST['action']) ? sanitize_text_field((string) $_REQUEST['action']) : '';

            if ($action === 'deactivate' && isset($_GET['plugin'])) {
                $plugin = sanitize_text_field(wp_unslash((string) $_GET['plugin']));
                if ($plugin === $target) {
                    wp_die(
                        esc_html(self::$config['plugin_name']) . ' nelze při aktivní správě webu deaktivovat.',
                        'Plugin je chráněn',
                        ['response' => 403]
                    );
                }
            }

            if (($action === 'deactivate-selected' || $action === 'deactivate-selected-network') && !empty($_REQUEST['checked'])) {
                $checked = array_map('sanitize_text_field', (array) wp_unslash($_REQUEST['checked']));
                if (in_array($target, $checked, true)) {
                    wp_die(
                        esc_html(self::$config['plugin_name']) . ' nelze při aktivní správě webu deaktivovat.',
                        'Plugin je chráněn',
                        ['response' => 403]
                    );
                }
            }
        }

        public static function maybe_revalidate_on_admin(): void {
            if (!is_admin()) return;

            self::schedule_cron();

            // Při aktivní správě webu se standalone licence neřeší.
            if (self::is_management_active()) return;

            $license_key = self::get_license_key();
            if ($license_key === '') return;

            $last = self::get_last_check();
            $interval = (int) self::$config['recheck_interval'];
            if ($last > 0 && (time() - $last) < $interval) return;

            self::verify_and_store($license_key);
        }

        public static function cron_revalidate(): void {
            if (self::is_management_active()) return;

            $license_key = self::get_license_key();
            if ($license_key === '') return;

            self::verify_and_store($license_key);
        }

        public static function handle_admin_post(): void {
            if (!is_admin()) return;
            if (!current_user_can(self::$config['settings_capability'])) return;
            if (empty($_POST['swlic_module_action'])) return;

            $action = sanitize_text_field((string) wp_unslash($_POST['swlic_module_action']));
            $nonce  = (string) ($_POST['_swlic_nonce'] ?? '');

            if (!wp_verify_nonce($nonce, 'swlic_module_' . self::$config['plugin_slug'])) {
                return;
            }

            if ($action === 'save_license_key') {
                $license_key = trim((string) wp_unslash($_POST['swlic_license_key'] ?? ''));
                update_option(self::$config['option_license_key'], $license_key, false);
                self::verify_and_store($license_key);
                self::redirect_back('updated=1');
            }

            if ($action === 'recheck_license') {
                self::verify_and_store(self::get_license_key());
                self::redirect_back('rechecked=1');
            }
        }

        private static function redirect_back(string $qs = ''): void {
            $base = wp_get_referer();
            if (!$base) {
                $base = admin_url();
            }
            if ($qs !== '') {
                $base = add_query_arg(wp_parse_args($qs), $base);
            }
            wp_safe_redirect($base);
            exit;
        }

        private static function build_payload(string $license_key): array {
            $site_id = (string) get_option('swlic_site_id', '');
            $site_url = home_url('/');
            $domain = self::normalize_domain(parse_url($site_url, PHP_URL_HOST) ?: '');

            return [
                'license_key' => $license_key,
                'plugin_slug' => self::$config['plugin_slug'],
                'site_id'     => $site_id,
                'site_url'    => $site_url,
                'domain'      => $domain,
            ];
        }

        private static function normalize_domain(string $domain): string {
            $domain = strtolower(trim($domain));
            $domain = preg_replace('~^https?://~', '', $domain);
            $domain = preg_replace('~^www\.~', '', $domain);
            return rtrim($domain, '/');
        }

        private static function verify_and_store(string $license_key): array {
            $license_key = trim($license_key);

            if ($license_key === '') {
                update_option(self::$config['option_license_cache'], [], false);
                update_option(self::$config['option_license_last'], time(), false);
                update_option(self::$config['option_license_error'], 'Licenční kód není vyplněn.', false);
                self::$state_cache = null;
                return ['ok' => false, 'error' => 'missing_license_key'];
            }

            $payload = self::build_payload($license_key);
            $url = rtrim((string) self::$config['hub_base'], '/') . self::$config['verify_endpoint'];

            $response = wp_remote_post($url, [
                'timeout' => 20,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode($payload, JSON_UNESCAPED_SLASHES),
            ]);

            update_option(self::$config['option_license_last'], time(), false);

            if (is_wp_error($response)) {
                update_option(self::$config['option_license_error'], $response->get_error_message(), false);
                self::$state_cache = null;
                return ['ok' => false, 'error' => $response->get_error_message()];
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($code < 200 || $code >= 300 || !is_array($data)) {
                update_option(self::$config['option_license_error'], 'Licenci se nepodařilo ověřit.', false);
                self::$state_cache = null;
                return ['ok' => false, 'error' => 'bad_response', 'code' => $code, 'body' => $body];
            }

            $cache = [
                'valid'          => !empty($data['valid']),
                'license_number' => (string) ($data['license_number'] ?? ''),
                'valid_to'       => (string) ($data['valid_to'] ?? ''),
                'domain'         => (string) ($data['domain'] ?? ''),
                'message'        => (string) ($data['message'] ?? ''),
                'status'         => (string) ($data['status'] ?? ''),
                'checked_at'     => time(),
            ];

            update_option(self::$config['option_license_cache'], $cache, false);
            update_option(self::$config['option_license_error'], (string) ($data['message'] ?? ''), false);
            self::$state_cache = null;

            return ['ok' => true, 'data' => $cache];
        }

        public static function render_license_box(): void {
            $state = self::get_effective_state();
            $cache = self::get_license_cache();
            $last_check = self::get_last_check();
            $last_error = self::get_last_error();
            $license_key = self::get_license_key();

            $status_badge = self::render_status_badge($state['state']);

            echo '<div class="swlic-card" style="background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:20px;margin:24px 0;box-shadow:0 1px 2px rgba(16,24,40,.04);">';
            echo '<h2 style="margin:0 0 14px;font-size:20px;">Licence pluginu</h2>';
            echo '<div class="swg-status" style="margin-bottom:20px;">' . $status_badge . '</div>';

            echo '<table class="widefat" style="border:0;box-shadow:none;">';
            echo '<tbody>';
            echo '<tr><td style="width:220px;"><strong>Režim</strong></td><td>' . esc_html($state['status_label']) . '</td></tr>';
            if ($state['license_number'] !== '') {
                echo '<tr><td><strong>Číslo licence</strong></td><td>' . esc_html($state['license_number']) . '</td></tr>';
            }
            if ($state['domain'] !== '') {
                echo '<tr><td><strong>Platnost pro doménu</strong></td><td>' . esc_html($state['domain']) . '</td></tr>';
            }
            if ($state['valid_to'] !== '') {
                echo '<tr><td><strong>Platnost do</strong></td><td>' . esc_html($state['valid_to']) . '</td></tr>';
            }
            echo '<tr><td><strong>Poslední ověření</strong></td><td>' . ($last_check ? esc_html(wp_date('j. n. Y H:i:s', $last_check)) : '—') . '</td></tr>';
            echo '</tbody>';
            echo '</table>';

            if (!empty($state['message'])) {
                echo '<p style="margin:16px 0 0;color:#50575e;">' . esc_html($state['message']) . '</p>';
            }
            if (!empty($last_error) && $state['source'] !== 'management') {
                echo '<p style="margin:10px 0 0;color:#b42318;">' . esc_html($last_error) . '</p>';
            }

            if ($state['source'] !== 'management') {
                echo '<hr style="margin:18px 0;border:none;border-top:1px solid #eee;">';
                echo '<form method="post">';
                wp_nonce_field('swlic_module_' . self::$config['plugin_slug'], '_swlic_nonce');
                echo '<input type="hidden" name="swlic_module_action" value="save_license_key">';
                echo '<label for="swlic-license-key" style="display:block;font-weight:600;margin-bottom:8px;">Licenční kód</label>';
                echo '<input id="swlic-license-key" type="text" name="swlic_license_key" value="' . esc_attr($license_key) . '" style="width:100%;max-width:560px;">';
                echo '<p style="margin:8px 0 0;color:#646970;">Použij licenční kód vygenerovaný v SW Licence Hubu.</p>';
                echo '<p style="margin:14px 0 0;"><button class="button button-primary">Uložit a ověřit licenci</button></p>';
                echo '</form>';

                if ($license_key !== '') {
                    echo '<form method="post" style="margin-top:10px;">';
                    wp_nonce_field('swlic_module_' . self::$config['plugin_slug'], '_swlic_nonce');
                    echo '<input type="hidden" name="swlic_module_action" value="recheck_license">';
                    echo '<p style="margin:0;"><button class="button">Ověřit licenci nyní</button></p>';
                    echo '</form>';
                }
            }

            echo '</div>';
        }

        private static function render_status_badge(string $state): string {
            $map = [
                'active'  => ['text' => 'Aktivní', 'bg' => '#1f8f3a', 'color' => '#fff'],
                'passive' => ['text' => 'Omezený režim', 'bg' => '#d97706', 'color' => '#fff'],
                'off'     => ['text' => 'Vypnuto', 'bg' => '#dc2626', 'color' => '#fff'],
            ];
            $cfg = $map[$state] ?? ['text' => $state, 'bg' => '#6b7280', 'color' => '#fff'];
            return '<span style="display:inline-block;padding:6px 12px;border-radius:999px;background:' . esc_attr($cfg['bg']) . ';color:' . esc_attr($cfg['color']) . ';font-weight:700;">' . esc_html($cfg['text']) . '</span>';
        }
    }
}
