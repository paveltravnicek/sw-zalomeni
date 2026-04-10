<?php
/**
 * Plugin Name: Správné zalamování řádků pro ČJ
 * Description: Nahrazuje běžné mezery za pevné mezery v typických českých případech, aby nedocházelo k nevhodnému zalomení řádku.
 * Version: 1.1
 * Author: Smart Websites
 * Author URI: https://smart-websites.cz/
 * Update URI: https://github.com/paveltravnicek/sw-zalomeni/
 * Text Domain: sw-zalomeni
 */

if (!defined('ABSPATH')) {
	exit;
}

require __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$swUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/paveltravnicek/sw-zalomeni/',
	__FILE__,
	'sw-zalomeni'
);

$swUpdateChecker->setBranch('main');
$swUpdateChecker->getVcsApi()->enableReleaseAssets('/\\.zip$/i');


final class SW_Zalomeni_Plugin {
	const OPTION_GROUP = 'sw_zalomeni_settings_group';
	const OPTION_NAME = 'sw_zalomeni_settings';
	const COMPILED_OPTION = 'sw_zalomeni_compiled_rules';
	const LEGACY_VERSION_OPTION = 'zalomeni_version';
	const LEGACY_CUSTOM_TERMS_EXAMPLES = "Formule 1\nWindows \\d\niPhone \\d\niPhone S\\d\niPad \\d\nWii U\nPlayStation \\d\nXBox 360";

	const LICENSE_OPTION = 'sw_zalomeni_plugin_license';
	const LICENSE_STATUS_OPTION = 'sw_zalomeni_plugin_license_status';
	const LICENSE_LAST_CHECK_OPTION = 'sw_zalomeni_plugin_license_last_check';
	const LICENSE_LAST_MESSAGE_OPTION = 'sw_zalomeni_plugin_license_message';
	const LICENSE_LAST_VALID_TO_OPTION = 'sw_zalomeni_plugin_license_valid_to';
	const LICENSE_HUB_BASE = 'https://smart-websites.cz';
	const LICENSE_CHECK_INTERVAL = 43200;

	private static $instance = null;

	private $default_settings = array(
		'prepositions'                    => 'on',
		'prepositions_list'               => 'k, s, v, z',
		'conjunctions'                    => '',
		'conjunctions_list'               => 'a, i, o, u',
		'abbreviations'                   => '',
		'abbreviations_list'              => 'cca., č., čís., čj., čp., fa, fě, fy, kupř., mj., např., p., pí, popř., př., přib., přibl., sl., str., sv., tj., tzn., tzv., zvl.',
		'between_number_and_unit'         => 'on',
		'between_number_and_unit_list'    => 'm, m², l, kg, h, °C, Kč, lidí, dní, %',
		'spaces_in_scales'                => 'on',
		'space_between_numbers'           => 'on',
		'space_after_ordered_number'      => 'on',
		'custom_terms'                    => '',
	);

	private $default_filters = array(
		'comment_author',
		'term_name',
		'link_name',
		'link_description',
		'link_notes',
		'bloginfo',
		'wp_title',
		'widget_title',
		'term_description',
		'the_title',
		'the_content',
		'the_excerpt',
		'comment_text',
		'single_post_title',
		'list_cats',
	);

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook(__FILE__, array($this, 'activate'));
		add_action('plugins_loaded', array($this, 'maybe_migrate_legacy_options'));
		add_action('admin_init', array($this, 'bootstrap_licence_runtime'));
		add_action('sw_zalomeni_licence_revalidate_event', array($this, 'maybe_revalidate_plugin_licence'));

		if (is_admin()) {
			add_action('admin_menu', array($this, 'register_admin_page'));
			add_action('admin_init', array($this, 'register_settings'));
			add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
			add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_action_links'));
		} else {
			add_action('init', array($this, 'register_frontend_filters'));
		}
	}

	public function activate() {
		if (version_compare(PHP_VERSION, '7.4', '<')) {
			deactivate_plugins(plugin_basename(__FILE__));
			wp_die(
				esc_html__('Plugin Smart Websites – Zalomení vyžaduje PHP 7.4 nebo vyšší.', 'sw-zalomeni'),
				esc_html__('Neaktivovaný plugin', 'sw-zalomeni'),
				array('back_link' => true)
			);
		}

		$settings = $this->get_settings();
		update_option(self::OPTION_NAME, $settings);
		$this->compile_and_store_rules($settings);
		update_option(self::LEGACY_VERSION_OPTION, $this->get_plugin_version());
		$this->schedule_licence_revalidation();
	}

	public function maybe_migrate_legacy_options() {
		$current = get_option(self::OPTION_NAME, null);
		if (is_array($current)) {
			return;
		}

		$legacy = array(
			'prepositions'                 => get_option('zalomeni_prepositions', null),
			'prepositions_list'            => get_option('zalomeni_prepositions_list', null),
			'conjunctions'                 => get_option('zalomeni_conjunctions', null),
			'conjunctions_list'            => get_option('zalomeni_conjunctions_list', null),
			'abbreviations'                => get_option('zalomeni_abbreviations', null),
			'abbreviations_list'           => get_option('zalomeni_abbreviations_list', null),
			'between_number_and_unit'      => get_option('zalomeni_between_number_and_unit', null),
			'between_number_and_unit_list' => get_option('zalomeni_between_number_and_unit_list', null),
			'spaces_in_scales'             => get_option('zalomeni_spaces_in_scales', null),
			'space_between_numbers'        => get_option('zalomeni_space_between_numbers', null),
			'space_after_ordered_number'   => get_option('zalomeni_space_after_ordered_number', null),
			'custom_terms'                 => get_option('zalomeni_custom_terms', null),
		);

		$has_legacy = false;
		foreach ($legacy as $value) {
			if (null !== $value) {
				$has_legacy = true;
				break;
			}
		}

		if (!$has_legacy) {
			return;
		}

		$settings = $this->default_settings;
		foreach ($legacy as $key => $value) {
			if (null !== $value && '' !== $value) {
				$settings[$key] = $value;
			}
		}

		update_option(self::OPTION_NAME, $this->sanitize_settings($settings));
		$this->compile_and_store_rules($this->get_settings());
		update_option(self::LEGACY_VERSION_OPTION, $this->get_plugin_version());
	}

	public function register_frontend_filters() {
		if (!$this->is_plugin_functionality_enabled()) {
			return;
		}

		$compiled = get_option(self::COMPILED_OPTION, array());
		if (empty($compiled['matches']) || empty($compiled['replacements'])) {
			$this->compile_and_store_rules($this->get_settings());
			$compiled = get_option(self::COMPILED_OPTION, array());
		}

		if (empty($compiled['matches']) || empty($compiled['replacements'])) {
			return;
		}

		$filters = array_combine($this->default_filters, $this->default_filters);
		$filters = apply_filters('zalomeni_filtry', $filters);

		foreach ($filters as $filter) {
			add_filter($filter, array($this, 'texturize'), 10, 1);
		}
	}

	public function add_plugin_action_links($links) {
		$url = admin_url('options-general.php?page=sw-zalomeni');
		array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Nastavení', 'sw-zalomeni') . '</a>');
		return $links;
	}

	public function register_admin_page() {
		add_options_page(
			esc_html__('Zalomení řádků', 'sw-zalomeni'),
			esc_html__('Zalomení řádků', 'sw-zalomeni'),
			'manage_options',
			'sw-zalomeni',
			array($this, 'render_admin_page')
		);
	}

	public function enqueue_admin_assets($hook) {
		if ('settings_page_sw-zalomeni' !== $hook) {
			return;
		}

		wp_enqueue_style(
			'sw-zalomeni-admin',
			plugin_dir_url(__FILE__) . 'assets/css/admin.css',
			array(),
			$this->get_plugin_version()
		);

		wp_enqueue_script(
			'sw-zalomeni-admin',
			plugin_dir_url(__FILE__) . 'assets/js/admin.js',
			array(),
			$this->get_plugin_version(),
			true
		);
	}

	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array($this, 'sanitize_settings'),
				'default'           => $this->default_settings,
			)
		);
	}

	public function sanitize_settings($input) {
		$input = is_array($input) ? $input : array();
		$output = $this->default_settings;

		$checkboxes = array(
			'prepositions',
			'conjunctions',
			'abbreviations',
			'between_number_and_unit',
			'spaces_in_scales',
			'space_between_numbers',
			'space_after_ordered_number',
		);

		foreach ($checkboxes as $key) {
			$output[$key] = (!empty($input[$key]) && 'on' === $input[$key]) ? 'on' : '';
		}

		$list_keys = array(
			'prepositions_list',
			'conjunctions_list',
			'abbreviations_list',
			'between_number_and_unit_list',
		);

		foreach ($list_keys as $key) {
			$value = isset($input[$key]) ? wp_unslash($input[$key]) : $this->default_settings[$key];
			$value = sanitize_text_field($value);
			$value = preg_replace('/\s*,\s*/u', ', ', trim($value));
			$output[$key] = $value;
		}

		$custom_terms = isset($input['custom_terms']) ? wp_unslash($input['custom_terms']) : $this->default_settings['custom_terms'];
		$custom_terms = preg_replace("/\r\n?/", "\n", $custom_terms);
		$custom_terms = implode("\n", array_map('trim', array_filter(explode("\n", $custom_terms), static function($line) {
			return '' !== trim($line);
		})));
		$output['custom_terms'] = $custom_terms;

		$this->compile_and_store_rules($output);

		return $output;
	}

	private function get_settings() {
		$settings = get_option(self::OPTION_NAME, array());
		if (!is_array($settings)) {
			$settings = array();
		}
		$settings = wp_parse_args($settings, $this->default_settings);

		if (($settings['custom_terms'] ?? '') === self::LEGACY_CUSTOM_TERMS_EXAMPLES) {
			$settings['custom_terms'] = '';
		}

		return $settings;
	}

	private function get_plugin_version() {
		static $version = null;

		if (null !== $version) {
			return $version;
		}

		$data = get_file_data(__FILE__, array('Version' => 'Version'), 'plugin');
		$version = !empty($data['Version']) ? (string) $data['Version'] : '1.0.0';

		return $version;
	}

	private function compile_and_store_rules($settings) {
		$compiled = array(
			'matches'      => array(),
			'replacements' => array(),
		);

		$word_groups = array();
		foreach (array('prepositions', 'conjunctions', 'abbreviations') as $group) {
			if ('on' !== ($settings[$group] ?? '')) {
				continue;
			}

			$items = array_filter(array_map('trim', explode(',', (string) ($settings[$group . '_list'] ?? ''))));
			foreach ($items as $item) {
				$word_groups[] = preg_quote(mb_strtolower($item), '@');
			}
		}

		if (!empty($word_groups)) {
			$compiled['matches']['words'] = '@(^|;| |&nbsp;|\(|\n)(' . implode('|', $word_groups) . ') @iu';
			$compiled['replacements']['words'] = '$1$2&nbsp;';
		}

		if ('on' === ($settings['between_number_and_unit'] ?? '')) {
			$units = array_filter(array_map('trim', explode(',', (string) ($settings['between_number_and_unit_list'] ?? ''))));
			$units = array_map(static function($item) {
				return preg_quote(mb_strtolower($item), '@');
			}, $units);

			if (!empty($units)) {
				$compiled['matches']['units'] = '@(\d) (' . implode('|', $units) . ')(^|[;\.!:]| |&nbsp;|\?|\n|\)|<|\x08|\x0B|$)@iu';
				$compiled['replacements']['units'] = '$1&nbsp;$2$3';
			}
		}

		if ('on' === ($settings['space_between_numbers'] ?? '')) {
			$compiled['matches']['numbers'] = '@(\d) (\d)@u';
			$compiled['replacements']['numbers'] = '$1&nbsp;$2';
		}

		if ('on' === ($settings['spaces_in_scales'] ?? '')) {
			$compiled['matches']['scales'] = '@(\d) : (\d)@u';
			$compiled['replacements']['scales'] = '$1&nbsp;:&nbsp;$2';
		}

		if ('on' === ($settings['space_after_ordered_number'] ?? '')) {
			$compiled['matches']['orders'] = '@(\d\.) ([0-9a-záčďéěíňóřšťúýž])@iu';
			$compiled['replacements']['orders'] = '$1&nbsp;$2';
		}

		$custom_terms = array_filter(array_map('trim', explode("\n", (string) ($settings['custom_terms'] ?? ''))));
		$counter = 1;

		foreach ($custom_terms as $term) {
			if (false === strpos($term, ' ')) {
				continue;
			}

			$words = preg_split('/\s+/u', $term);
			if (!$words) {
				continue;
			}

			$pattern_parts = array();
			$replacement_parts = array();

			foreach (array_values($words) as $index => $word) {
				$prepared = str_replace(array('/', '(', ')'), array('\/', '\(', '\)'), $word);
				$pattern_parts[] = '(' . $prepared . ')';
				$replacement_parts[] = '$' . ($index + 1);
			}

			$key = 'customterm' . $counter++;
			$compiled['matches'][$key] = '/' . implode(' ', $pattern_parts) . '/iu';
			$compiled['replacements'][$key] = implode('&nbsp;', $replacement_parts);
		}

		update_option(self::COMPILED_OPTION, $compiled, false);

		// Zachování starých interních voleb kvůli kompatibilitě.
		update_option('zalomeni_matches', $compiled['matches'], false);
		update_option('zalomeni_replacements', $compiled['replacements'], false);
		update_option(self::LEGACY_VERSION_OPTION, $this->get_plugin_version(), false);
	}


	public function bootstrap_licence_runtime() {
		$this->schedule_licence_revalidation();

		if (is_admin()) {
			$this->maybe_revalidate_plugin_licence();
		}

		add_filter('pre_update_option_' . self::OPTION_NAME, array($this, 'prevent_settings_update_when_locked'), 10, 2);
		add_action('admin_post_sw_zalomeni_save_licence', array($this, 'handle_licence_save'));
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'protect_deactivate_link'), 20, 1);
		add_action('admin_init', array($this, 'block_direct_deactivate'));
	}

	public function schedule_licence_revalidation() {
		if (!wp_next_scheduled('sw_zalomeni_licence_revalidate_event')) {
			wp_schedule_event(time() + 600, 'twicedaily', 'sw_zalomeni_licence_revalidate_event');
		}
	}

	public function maybe_revalidate_plugin_licence() {
		if ($this->is_managed_by_sw_guard()) {
			update_option(self::LICENSE_STATUS_OPTION, 'managed', false);
			update_option(self::LICENSE_LAST_MESSAGE_OPTION, 'Plugin je provozován v rámci správy webu. Samostatný licenční kód zde není potřeba.', false);
			update_option(self::LICENSE_LAST_CHECK_OPTION, time(), false);
			return;
		}

		$license_key = $this->get_plugin_licence_key();
		if ($license_key === '') {
			update_option(self::LICENSE_STATUS_OPTION, 'missing', false);
			update_option(self::LICENSE_LAST_MESSAGE_OPTION, 'Licenční kód zatím nebyl zadán.', false);
			return;
		}

		$last_check = (int) get_option(self::LICENSE_LAST_CHECK_OPTION, 0);
		if ($last_check > 0 && (time() - $last_check) < self::LICENSE_CHECK_INTERVAL && !isset($_POST['sw_zalomeni_verify_licence'])) {
			return;
		}

		$this->validate_plugin_licence($license_key, false);
	}

	public function handle_licence_save() {
		if (!current_user_can('manage_options')) {
			wp_die('Zakázáno.');
		}

		check_admin_referer('sw_zalomeni_save_licence');

		$license_key = isset($_POST['sw_zalomeni_plugin_license']) ? sanitize_text_field(wp_unslash($_POST['sw_zalomeni_plugin_license'])) : '';
		update_option(self::LICENSE_OPTION, $license_key, false);

		$this->validate_plugin_licence($license_key, true);

		wp_safe_redirect(admin_url('options-general.php?page=sw-zalomeni'));
		exit;
	}

	private function validate_plugin_licence($license_key, $force = false) {
		if ($this->is_managed_by_sw_guard()) {
			update_option(self::LICENSE_STATUS_OPTION, 'managed', false);
			update_option(self::LICENSE_LAST_MESSAGE_OPTION, 'Plugin je provozován v rámci správy webu. Samostatný licenční kód zde není potřeba.', false);
			update_option(self::LICENSE_LAST_CHECK_OPTION, time(), false);
			return true;
		}

		if ($license_key === '') {
			update_option(self::LICENSE_STATUS_OPTION, 'missing', false);
			update_option(self::LICENSE_LAST_MESSAGE_OPTION, 'Licenční kód zatím nebyl zadán.', false);
			update_option(self::LICENSE_LAST_CHECK_OPTION, time(), false);
			return false;
		}

		$payload = array(
			'license_key' => $license_key,
			'plugin_slug' => 'sw-zalomeni',
			'site_id'     => $this->get_guard_site_id(),
			'site_url'    => home_url('/'),
		);

		$response = wp_remote_post(rtrim(self::LICENSE_HUB_BASE, '/') . '/wp-json/swlic/v2/plugin-license', array(
			'timeout' => 15,
			'headers' => array('Content-Type' => 'application/json'),
			'body'    => wp_json_encode($payload, JSON_UNESCAPED_SLASHES),
		));

		update_option(self::LICENSE_LAST_CHECK_OPTION, time(), false);

		if (is_wp_error($response)) {
			update_option(self::LICENSE_STATUS_OPTION, 'error', false);
			update_option(self::LICENSE_LAST_MESSAGE_OPTION, $response->get_error_message(), false);
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		if ($code < 200 || $code >= 300 || !is_array($body)) {
			update_option(self::LICENSE_STATUS_OPTION, 'error', false);
			update_option(self::LICENSE_LAST_MESSAGE_OPTION, 'Licenci se nepodařilo ověřit.', false);
			return false;
		}

		update_option(self::LICENSE_STATUS_OPTION, sanitize_key((string) ($body['status'] ?? 'error')), false);
		update_option(self::LICENSE_LAST_MESSAGE_OPTION, sanitize_text_field((string) ($body['message'] ?? '')), false);
		update_option(self::LICENSE_LAST_VALID_TO_OPTION, sanitize_text_field((string) ($body['valid_to'] ?? '')), false);

		return !empty($body['ok']);
	}

	private function is_managed_by_sw_guard() {
		return function_exists('sw_guard_get_management_status') && sw_guard_get_management_status() === 'ACTIVE';
	}

	private function is_plugin_functionality_enabled() {
		if ($this->is_managed_by_sw_guard()) {
			return true;
		}

		return $this->get_plugin_licence_status() === 'active';
	}

	private function can_edit_plugin_settings() {
		return $this->is_plugin_functionality_enabled();
	}

	public function prevent_settings_update_when_locked($value, $old_value) {
		if ($this->can_edit_plugin_settings()) {
			return $value;
		}

		return $old_value;
	}

	public function protect_deactivate_link($links) {
		if ($this->is_managed_by_sw_guard()) {
			unset($links['deactivate']);
		}
		return $links;
	}

	public function block_direct_deactivate() {
		if (!$this->is_managed_by_sw_guard()) {
			return;
		}

		$action = isset($_GET['action']) ? sanitize_key((string) $_GET['action']) : '';
		$plugin = isset($_GET['plugin']) ? sanitize_text_field((string) $_GET['plugin']) : '';
		if ($action === 'deactivate' && $plugin === plugin_basename(__FILE__)) {
			wp_die('Tento plugin nelze deaktivovat při aktivní správě webu.', 'Chráněný plugin', array('response' => 403));
		}
	}

	private function get_guard_site_id() {
		if (defined('SW_Guard_V2::OPT_SITE_ID')) {
			return (string) get_option(SW_Guard_V2::OPT_SITE_ID, '');
		}
		return (string) get_option('swlic_site_id', '');
	}

	private function get_plugin_licence_key() {
		return trim((string) get_option(self::LICENSE_OPTION, ''));
	}

	private function get_plugin_licence_status() {
		$status = (string) get_option(self::LICENSE_STATUS_OPTION, 'missing');
		return in_array($status, array('active', 'managed', 'expired', 'disabled', 'inactive', 'missing', 'error', 'invalid_plugin', 'invalid_type', 'bound_elsewhere'), true) ? $status : 'missing';
	}

	private function get_licence_status_label($status) {
		$map = array(
			'managed' => 'Správa webu',
			'active' => 'Platná',
			'expired' => 'Vypršela',
			'disabled' => 'Pozastavená',
			'inactive' => 'Neaktivní',
			'missing' => 'Nezadána',
			'error' => 'Chyba ověření',
			'invalid_plugin' => 'Nesoulad pluginu',
			'invalid_type' => 'Neplatný typ',
			'bound_elsewhere' => 'Přiřazena jinam',
		);

		return $map[$status] ?? ucfirst($status);
	}

	private function render_licence_box() {
		$status = $this->get_plugin_licence_status();
		$message = (string) get_option(self::LICENSE_LAST_MESSAGE_OPTION, '');
		$last_check = (int) get_option(self::LICENSE_LAST_CHECK_OPTION, 0);
		$valid_to = (string) get_option(self::LICENSE_LAST_VALID_TO_OPTION, '');
		$license_key = $this->get_plugin_licence_key();
		$is_managed = $this->is_managed_by_sw_guard();
		$is_locked = !$this->can_edit_plugin_settings();
		?>
		<div class="swz-card swz-card--full">
			<h2><?php echo esc_html__('Licence pluginu', 'sw-zalomeni'); ?></h2>
			<div class="swg-status" style="margin-bottom:20px;">
				<strong><?php echo esc_html__('Stav licence:', 'sw-zalomeni'); ?></strong>
				<?php echo esc_html($this->get_licence_status_label($status)); ?>
			</div>
			<?php if ($message !== '') : ?>
				<p><?php echo esc_html($message); ?></p>
			<?php endif; ?>
			<?php if ($valid_to !== '' && !$is_managed) : ?>
				<p><strong><?php echo esc_html__('Platnost do:', 'sw-zalomeni'); ?></strong> <?php echo esc_html($valid_to); ?></p>
			<?php endif; ?>
			<?php if ($last_check > 0) : ?>
				<p><strong><?php echo esc_html__('Poslední ověření:', 'sw-zalomeni'); ?></strong> <?php echo esc_html(wp_date('j. n. Y H:i:s', $last_check)); ?></p>
			<?php endif; ?>

			<?php if ($is_managed) : ?>
				<p><?php echo esc_html__('Plugin je provozován v rámci správy webu. Samostatný licenční kód zde není potřeba.', 'sw-zalomeni'); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<?php wp_nonce_field('sw_zalomeni_save_licence'); ?>
					<input type="hidden" name="action" value="sw_zalomeni_save_licence">
					<p>
						<label for="sw_zalomeni_plugin_license"><strong><?php echo esc_html__('Licenční kód', 'sw-zalomeni'); ?></strong></label><br>
						<input type="text" id="sw_zalomeni_plugin_license" name="sw_zalomeni_plugin_license" value="<?php echo esc_attr($license_key); ?>" class="regular-text" style="min-width:320px;" />
					</p>
					<p>
						<button type="submit" name="sw_zalomeni_verify_licence" class="button button-primary"><?php echo esc_html__('Uložit a ověřit licenci', 'sw-zalomeni'); ?></button>
					</p>
				</form>
			<?php endif; ?>

			<?php if ($is_locked) : ?>
				<p class="description"><?php echo esc_html__('Nastavení pluginu je v tomto stavu pouze pro čtení a zalamování se neaplikuje.', 'sw-zalomeni'); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}


	public function render_admin_page() {
		if (!current_user_can('manage_options')) {
			return;
		}

		$settings = $this->get_settings();
		?>
		<div class="wrap sw-zalomeni-admin">
			<div class="swz-hero">
				<div class="swz-hero__content">
					<span class="swz-badge"><?php echo esc_html__('Smart Websites', 'sw-zalomeni'); ?></span>
					<h1><?php echo esc_html__('Správné zalamování řádků pro ČJ', 'sw-zalomeni'); ?></h1>
					<p><?php echo esc_html__('Nahrazuje běžné mezery za pevné mezery v typických českých případech, aby nedocházelo k nevhodnému zalomení řádku.', 'sw-zalomeni'); ?></p>
				</div>
				<div class="swz-hero__meta">
					<div class="swz-stat">
						<strong><?php echo esc_html($this->get_plugin_version()); ?></strong>
						<span><?php echo esc_html__('Verze pluginu', 'sw-zalomeni'); ?></span>
					</div>
				</div>
			</div>
			<form method="post" action="options.php" class="swz-form">
				<?php settings_fields(self::OPTION_GROUP); ?>
				<fieldset <?php disabled(!$this->can_edit_plugin_settings()); ?>>

				<div class="swz-grid">
					<div class="swz-card">
						<h2><?php echo esc_html__('Jednopísmenná slova a zkratky', 'sw-zalomeni'); ?></h2>
						<p class="swz-intro"><?php echo esc_html__('Typické české případy, kdy nechcete nechat krátké slovo viset na konci řádku.', 'sw-zalomeni'); ?></p>

						<?php $this->render_checkbox_with_list('prepositions', __('Předložky', 'sw-zalomeni'), __('Vkládat pevnou mezeru za vybrané předložky.', 'sw-zalomeni'), $settings); ?>
						<?php $this->render_checkbox_with_list('conjunctions', __('Spojky', 'sw-zalomeni'), __('Vkládat pevnou mezeru za vybrané spojky.', 'sw-zalomeni'), $settings); ?>
						<?php $this->render_checkbox_with_list('abbreviations', __('Zkratky', 'sw-zalomeni'), __('Vkládat pevnou mezeru za vybrané zkratky.', 'sw-zalomeni'), $settings); ?>
					</div>

					<div class="swz-card">
						<h2><?php echo esc_html__('Čísla, jednotky a měřítka', 'sw-zalomeni'); ?></h2>
						<p class="swz-intro"><?php echo esc_html__('Ošetření typografických situací v číslech, měrných jednotkách a poměrech.', 'sw-zalomeni'); ?></p>

						<?php $this->render_checkbox_with_list('between_number_and_unit', __('Jednotky a míry', 'sw-zalomeni'), __('Vkládat pevnou mezeru mezi číslo a jednotku.', 'sw-zalomeni'), $settings); ?>
						<?php $this->render_checkbox('space_between_numbers', __('Mezery uprostřed čísel', 'sw-zalomeni'), __('Nahradit mezery mezi čísly pevnou mezerou, například v telefonních číslech.', 'sw-zalomeni'), $settings); ?>
						<?php $this->render_checkbox('space_after_ordered_number', __('Řadové číslovky', 'sw-zalomeni'), __('Zabránit zalomení za řadovou číslovkou, například v datech.', 'sw-zalomeni'), $settings); ?>
						<?php $this->render_checkbox('spaces_in_scales', __('Měřítka a poměry', 'sw-zalomeni'), __('Vkládat pevné mezery v zápisu typu 1 : 50 000.', 'sw-zalomeni'), $settings); ?>
					</div>

					<div class="swz-card swz-card--full">
						<h2><?php echo esc_html__('Vlastní výrazy', 'sw-zalomeni'); ?></h2>
						<p class="swz-intro"><?php echo esc_html__('Každý výraz vložte na samostatný řádek. Pokud obsahuje více slov, mezery uvnitř budou nahrazeny pevnými mezerami.', 'sw-zalomeni'); ?></p>

						<label class="screen-reader-text" for="sw_zalomeni_custom_terms"><?php echo esc_html__('Vlastní výrazy', 'sw-zalomeni'); ?></label>
						<textarea
							name="<?php echo esc_attr(self::OPTION_NAME); ?>[custom_terms]"
							id="sw_zalomeni_custom_terms"
							rows="10"
							class="large-text code"
						placeholder="iPhone 17&#10;Windows 12&#10;Playstation 5"
						><?php echo esc_textarea($this->get_admin_custom_terms_value($settings)); ?></textarea>
						<p class="description"><?php echo esc_html__('Volitelné. Každý výraz pište na samostatný řádek. Pro pokročilejší použití lze zadat i jednoduchý regex vzor, například iPhone \d. Znak \d znamená jednu číslici 0–9.', 'sw-zalomeni'); ?></p>
					</div>

				</div>

				</fieldset>

				<div class="swz-actions">
					<?php submit_button(__('Uložit nastavení', 'sw-zalomeni'), 'primary', 'submit', false, array('disabled' => !$this->can_edit_plugin_settings())); ?>
				</div>
			</form>

			<?php $this->render_licence_box(); ?>
		</div>
		<?php
	}

	private function get_admin_custom_terms_value($settings) {
		$value = (string) ($settings['custom_terms'] ?? '');

		if ($value === self::LEGACY_CUSTOM_TERMS_EXAMPLES) {
			return '';
		}

		return $value;
	}

	private function render_checkbox($key, $label, $description, $settings) {
		$checked = ('on' === ($settings[$key] ?? ''));
		$name = self::OPTION_NAME . '[' . $key . ']';
		$id = 'sw_zalomeni_' . $key;
		?>
		<div class="swz-field">
			<label class="swz-toggle" for="<?php echo esc_attr($id); ?>">
				<input type="checkbox" name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($id); ?>" value="on" <?php checked($checked); ?> />
				<span class="swz-toggle__label"><?php echo esc_html($label); ?></span>
			</label>
			<p class="description"><?php echo esc_html($description); ?></p>
		</div>
		<?php
	}

	private function render_checkbox_with_list($key, $label, $description, $settings) {
		$this->render_checkbox($key, $label, $description, $settings);
		$list_key = $key . '_list';
		$name = self::OPTION_NAME . '[' . $list_key . ']';
		$id = 'sw_zalomeni_' . $list_key;
		$enabled = ('on' === ($settings[$key] ?? ''));
		?>
		<div class="swz-field swz-field--list">
			<label for="<?php echo esc_attr($id); ?>" class="screen-reader-text"><?php echo esc_html($label); ?></label>
			<input
				type="text"
				name="<?php echo esc_attr($name); ?>"
				id="<?php echo esc_attr($id); ?>"
				class="regular-text"
				value="<?php echo esc_attr($settings[$list_key] ?? ''); ?>"
				<?php disabled(!$enabled); ?>
			/>
			<p class="description"><?php echo esc_html__('Jednotlivé hodnoty oddělte čárkou.', 'sw-zalomeni'); ?></p>
		</div>
		<?php
	}

	public function texturize($text) {
		if (!is_string($text) || '' === $text) {
			return $text;
		}

		$compiled = get_option(self::COMPILED_OPTION, array());
		if (empty($compiled['matches']) || empty($compiled['replacements'])) {
			return $text;
		}

		$output = '';
		$segments = preg_split('/(<[^>]+>|\[[^\]]+\])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
		if (!is_array($segments)) {
			return $text;
		}

		$no_texturize_tags = apply_filters('no_texturize_tags', array('pre', 'code', 'kbd', 'style', 'script', 'tt'));
		$no_texturize_shortcodes = apply_filters('no_texturize_shortcodes', array('code'));
		$tag_stack = array();
		$shortcode_stack = array();

		foreach ($segments as $segment) {
			if ('' === $segment) {
				continue;
			}

			$is_tag_or_shortcode = ('<' === $segment[0] || '[' === $segment[0]);

			if (!$is_tag_or_shortcode && empty($tag_stack) && empty($shortcode_stack)) {
				$segment = preg_replace($compiled['matches'], $compiled['replacements'], $segment);
				$segment = preg_replace($compiled['matches'], $compiled['replacements'], $segment);
			} else {
				$this->pushpop_texturize_element($segment, $tag_stack, $no_texturize_tags, '<', '>');
				$this->pushpop_texturize_element($segment, $shortcode_stack, $no_texturize_shortcodes, '[', ']');
			}

			$output .= $segment;
		}

		return $output;
	}

	private function pushpop_texturize_element($text, &$stack, $disabled_elements, $opening, $closing) {
		if (!is_string($text) || '' === $text) {
			return;
		}

		if ($opening !== $text[0]) {
			return;
		}

		if (!preg_match('/^' . preg_quote($opening, '/') . '\/?([a-z0-9_-]+)/iu', $text, $matches)) {
			return;
		}

		$tag = strtolower($matches[1]);
		if (!in_array($tag, $disabled_elements, true)) {
			return;
		}

		$is_closing = isset($text[1]) && '/' === $text[1];
		$is_self_closing = (strlen($text) >= 2 && substr($text, -2, 1) === '/');

		if ($is_closing) {
			if (!empty($stack) && end($stack) === $tag) {
				array_pop($stack);
			}
			return;
		}

		if (!$is_self_closing) {
			$stack[] = $tag;
		}
	}
}

SW_Zalomeni_Plugin::instance();
