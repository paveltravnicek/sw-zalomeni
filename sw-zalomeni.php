<?php
/**
 * Plugin Name: Správné zalamování řádků pro ČJ
 * Description: Nahrazuje běžné mezery za pevné mezery v typických českých případech, aby nedocházelo k nevhodnému zalomení řádku.
 * Version: 1.0
 * Author: Smart Websites
 * Author URI: https://smart-websites.cz/
 * Update URI: https://github.com/paveltravnicek/sw-zalomeni/
 * Text Domain: sw-zalomeni
 * SW Plugin: yes
 * SW Service Type: passive
 * SW License Group: both
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
	const LICENSE_OPTION = 'sw_zalomeni_license';
	const LICENSE_CRON_HOOK = 'sw_zalomeni_license_daily_check';
	const HUB_BASE = 'https://smart-websites.cz';
	const PLUGIN_SLUG = 'sw-zalomeni';
	const LEGACY_CUSTOM_TERMS_EXAMPLES = "Formule 1
Windows \d
iPhone \d
iPhone S\d
iPad \d
Wii U
PlayStation \d
XBox 360";

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
		register_deactivation_hook(__FILE__, array($this, 'deactivate'));
		add_action('plugins_loaded', array($this, 'maybe_migrate_legacy_options'));
		add_action(self::LICENSE_CRON_HOOK, array($this, 'cron_refresh_plugin_license'));

		if (is_admin()) {
			add_action('admin_menu', array($this, 'register_admin_page'));
			add_action('admin_init', array($this, 'register_settings'));
			add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
			add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_action_links'));
			add_action('admin_post_sw_zalomeni_verify_license', array($this, 'handle_verify_license'));
			add_action('admin_post_sw_zalomeni_remove_license', array($this, 'handle_remove_license'));
			add_action('admin_init', array($this, 'maybe_refresh_plugin_license'));
			add_action('admin_init', array($this, 'block_direct_deactivate'));
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

		if (!wp_next_scheduled(self::LICENSE_CRON_HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', self::LICENSE_CRON_HOOK);
		}
	}

	public function deactivate() {
		$timestamp = wp_next_scheduled(self::LICENSE_CRON_HOOK);
		if ($timestamp) {
			wp_unschedule_event($timestamp, self::LICENSE_CRON_HOOK);
		}
	}

	public function cron_refresh_plugin_license() {
		$this->refresh_plugin_license('cron');
	}

	private function default_license_state(): array {
		return array(
			'key' => '',
			'status' => 'missing',
			'type' => '',
			'valid_to' => '',
			'domain' => '',
			'message' => '',
			'last_check' => 0,
			'last_success' => 0,
		);
	}

	private function get_license_state(): array {
		$state = get_option(self::LICENSE_OPTION, array());
		if (!is_array($state)) {
			$state = array();
		}
		return wp_parse_args($state, $this->default_license_state());
	}

	private function update_license_state(array $data): void {
		$current = $this->get_license_state();
		$new = array_merge($current, $data);
		$new['key'] = sanitize_text_field((string) ($new['key'] ?? ''));
		$new['status'] = sanitize_key((string) ($new['status'] ?? 'missing'));
		$new['type'] = sanitize_key((string) ($new['type'] ?? ''));
		$new['valid_to'] = sanitize_text_field((string) ($new['valid_to'] ?? ''));
		$new['domain'] = sanitize_text_field((string) ($new['domain'] ?? ''));
		$new['message'] = sanitize_text_field((string) ($new['message'] ?? ''));
		$new['last_check'] = (int) ($new['last_check'] ?? 0);
		$new['last_success'] = (int) ($new['last_success'] ?? 0);
		update_option(self::LICENSE_OPTION, $new, false);
	}

	private function get_management_context(): array {
		$guard_present = function_exists('sw_guard_get_service_state');
		$management_status = $guard_present ? (string) get_option('swg_management_status', 'NONE') : 'NONE';
		$service_state = $guard_present ? (string) sw_guard_get_service_state(self::PLUGIN_SLUG) : 'off';
		$guard_last_success = $guard_present ? (int) get_option('swg_last_success_ts', 0) : 0;
		$connected_recently = $guard_last_success > 0 && (time() - $guard_last_success) <= (8 * DAY_IN_SECONDS);

		return array(
			'guard_present' => $guard_present,
			'management_status' => $management_status,
			'service_state' => in_array($service_state, array('active', 'passive', 'off'), true) ? $service_state : 'off',
			'guard_last_success' => $guard_last_success,
			'connected_recently' => $connected_recently,
			'is_active' => $guard_present && $connected_recently && $management_status === 'ACTIVE' && $service_state === 'active',
		);
	}

	private function has_active_standalone_license(): bool {
		$license = $this->get_license_state();
		return $license['key'] !== '' && $license['status'] === 'active' && $license['type'] === 'plugin_single';
	}

	private function plugin_is_operational(): bool {
		$management = $this->get_management_context();
		if ($management['is_active']) {
			return true;
		}
		return $this->has_active_standalone_license();
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
		if (!$this->plugin_is_operational()) {
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
		$custom_terms = preg_replace("/
?/", "
", $custom_terms);
		$custom_terms = implode("
", array_map('trim', array_filter(explode("
", $custom_terms), static function($line) {
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
			$compiled['matches']['words'] = '@(^|;| |&nbsp;|\(|
)(' . implode('|', $word_groups) . ') @iu';
			$compiled['replacements']['words'] = '$1$2&nbsp;';
		}

		if ('on' === ($settings['between_number_and_unit'] ?? '')) {
			$units = array_filter(array_map('trim', explode(',', (string) ($settings['between_number_and_unit_list'] ?? ''))));
			$units = array_map(static function($item) {
				return preg_quote(mb_strtolower($item), '@');
			}, $units);

			if (!empty($units)) {
				$compiled['matches']['units'] = '@(\d) (' . implode('|', $units) . ')(^|[;\.!:]| |&nbsp;|\?|
|\)|<|||$)@iu';
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

		$custom_terms = array_filter(array_map('trim', explode("
", (string) ($settings['custom_terms'] ?? ''))));
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

		update_option('zalomeni_matches', $compiled['matches'], false);
		update_option('zalomeni_replacements', $compiled['replacements'], false);
		update_option(self::LEGACY_VERSION_OPTION, $this->get_plugin_version(), false);
	}

	public function render_admin_page() {
		if (!current_user_can('manage_options')) {
			return;
		}

		$settings = $this->get_settings();
		$license = $this->get_license_state();
		$management = $this->get_management_context();
		$is_operational = $this->plugin_is_operational();
		$can_edit_settings = $is_operational;
		$status_payload = $this->get_license_panel_data($license, $management, $is_operational);
		?>
		<div class="wrap sw-zalomeni-admin">
			<div class="swz-hero">
				<div class="swz-hero__content">
					<span class="swz-badge"><?php echo esc_html__('Smart Websites', 'sw-zalomeni'); ?></span>
					<h1><?php echo esc_html__('Správné zalamování řádků pro ČJ', 'sw-zalomeni'); ?></h1>
					<p><?php echo esc_html__('Nahrazuje běžné mezery za pevné mezery v&nbsp;typických českých případech, aby nedocházelo k&nbsp;nevhodnému zalomení řádku.', 'sw-zalomeni'); ?></p>
				</div>
				<div class="swz-hero__meta">
					<div class="swz-stat">
						<strong><?php echo esc_html($this->get_plugin_version()); ?></strong>
						<span><?php echo esc_html__('Verze pluginu', 'sw-zalomeni'); ?></span>
					</div>
				</div>
			</div>

			<?php if (!empty($_GET['swz_license_message'])) : ?>
				<div class="notice notice-success"><p><?php echo esc_html(sanitize_text_field((string) $_GET['swz_license_message'])); ?></p></div>
			<?php endif; ?>

			<div class="swz-card swz-card--licence">
				<div class="swz-card__head">
					<div>
						<h2><?php echo esc_html__('Licence pluginu', 'sw-zalomeni'); ?></h2>
						<p class="swz-intro"><?php echo esc_html__('Plugin může běžet buď v rámci platné správy webu, nebo přes samostatnou licenci.', 'sw-zalomeni'); ?></p>
					</div>
					<span class="swz-licence-badge swz-licence-badge--<?php echo esc_attr($status_payload['badge_class']); ?>"><?php echo esc_html($status_payload['badge_label']); ?></span>
				</div>

				<div class="swz-licence-grid">
					<div class="swz-licence-item">
						<span class="swz-licence-label"><?php echo esc_html__('Režim', 'sw-zalomeni'); ?></span>
						<strong><?php echo esc_html($status_payload['mode']); ?></strong>
						<?php if ($status_payload['subline']) : ?><span><?php echo esc_html($status_payload['subline']); ?></span><?php endif; ?>
					</div>
					<div class="swz-licence-item">
						<span class="swz-licence-label"><?php echo esc_html__('Platnost do', 'sw-zalomeni'); ?></span>
						<strong><?php echo esc_html($status_payload['valid_to']); ?></strong>
						<?php if ($status_payload['domain']) : ?><span><?php echo esc_html($status_payload['domain']); ?></span><?php endif; ?>
					</div>
					<div class="swz-licence-item">
						<span class="swz-licence-label"><?php echo esc_html__('Poslední ověření', 'sw-zalomeni'); ?></span>
						<strong><?php echo esc_html($status_payload['last_check']); ?></strong>
						<?php if ($status_payload['message']) : ?><span><?php echo esc_html($status_payload['message']); ?></span><?php endif; ?>
					</div>
				</div>

				<?php if (!$management['is_active']) : ?>
					<div class="swz-license-form-wrap">
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="swz-license-form">
							<?php wp_nonce_field('sw_zalomeni_verify_license'); ?>
							<input type="hidden" name="action" value="sw_zalomeni_verify_license">
							<label for="sw_zalomeni_license_key"><strong><?php echo esc_html__('Licenční kód pluginu', 'sw-zalomeni'); ?></strong></label>
							<input type="text" id="sw_zalomeni_license_key" name="license_key" value="<?php echo esc_attr($license['key']); ?>" class="regular-text" placeholder="SWLIC-..." />
							<p class="description"><?php echo esc_html__('Použijte pouze pro samostatnou licenci pluginu. Pokud máte Správu webu, kód vyplňovat nemusíte.', 'sw-zalomeni'); ?></p>
							<div class="swz-license-actions">
								<button type="submit" class="button button-primary"><?php echo esc_html__('Ověřit a uložit licenci', 'sw-zalomeni'); ?></button>
								<?php if ($license['key'] !== '') : ?>
									<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sw_zalomeni_remove_license'), 'sw_zalomeni_remove_license')); ?>" class="button button-secondary"><?php echo esc_html__('Odebrat licenční kód', 'sw-zalomeni'); ?></a>
								<?php endif; ?>
							</div>
						</form>
					</div>
				<?php else : ?>
					<div class="swz-note"><?php echo esc_html__('Plugin je provozován v rámci Správy webu. Samostatný licenční kód není potřeba.', 'sw-zalomeni'); ?></div>
				<?php endif; ?>
			</div>

			<?php if (!$can_edit_settings) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html__('Plugin momentálně nemá platnou licenci. Nastavení zůstává pouze pro čtení a zalamování řádků se na webu neprovádí.', 'sw-zalomeni'); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php" class="swz-form <?php echo $can_edit_settings ? '' : 'is-readonly'; ?>">
				<?php settings_fields(self::OPTION_GROUP); ?>

				<fieldset <?php disabled(!$can_edit_settings); ?>>
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
					<?php submit_button(__('Uložit nastavení', 'sw-zalomeni'), 'primary', 'submit', false, $can_edit_settings ? array() : array('disabled' => 'disabled')); ?>
				</div>
			</form>
		</div>
		<?php
	}

	private function get_license_panel_data(array $license, array $management, bool $is_operational): array {
		$format_dt = static function(int $ts): string {
			return $ts > 0 ? wp_date('j. n. Y H:i', $ts) : '—';
		};
		$format_date = static function(string $ymd): string {
			if ($ymd === '') {
				return '—';
			}
			$ts = strtotime($ymd . ' 12:00:00');
			return $ts ? wp_date('j. n. Y', $ts) : $ymd;
		};

		$base = array(
			'badge_class' => 'inactive',
			'badge_label' => 'Licence chybí',
			'mode'        => 'Samostatná licence pluginu',
			'subline'     => '',
			'valid_to'    => '—',
			'domain'      => '',
			'last_check'  => '—',
			'message'     => '',
		);

		if ($management['guard_present']) {
			if ($management['is_active']) {
				return array_merge($base, array(
					'badge_class' => 'active',
					'badge_label' => 'Platná licence',
					'mode'        => 'Správa webu',
					'valid_to'    => $format_date((string) get_option('swg_managed_until', '')),
					'domain'      => (string) get_option('swg_licence_domain', ''),
					'last_check'  => $format_dt((int) $management['guard_last_success']),
				));
			}
			if ($management['management_status'] !== 'NONE') {
				return array_merge($base, array(
					'badge_class' => 'inactive',
					'badge_label' => 'Licence neplatná',
					'mode'        => 'Správa webu',
					'subline'     => 'Správa webu je po expiraci nebo omezená. Zalamování se neprovádí.',
					'valid_to'    => $format_date((string) get_option('swg_managed_until', '')),
					'domain'      => (string) get_option('swg_licence_domain', ''),
					'last_check'  => $format_dt((int) $management['guard_last_success']),
					'message'     => 'Po expiraci lze plugin deaktivovat nebo smazat.',
				));
			}
		}

		if ($license['status'] === 'active') {
			return array_merge($base, array(
				'badge_class' => 'active',
				'badge_label' => 'Platná licence',
				'mode'        => 'Samostatná licence pluginu',
				'subline'     => $license['key'] !== '' ? 'Licenční kód: ' . $license['key'] : '',
				'valid_to'    => $format_date((string) $license['valid_to']),
				'domain'      => (string) $license['domain'],
				'last_check'  => $format_dt((int) $license['last_success']),
				'message'     => $license['message'] !== '' ? $license['message'] : 'Plugin běží přes samostatnou licenci.',
			));
		}

		$badge = $is_operational ? 'active' : 'inactive';
		$label = $is_operational ? 'Platná licence' : 'Licence chybí';

		return array_merge($base, array(
			'badge_class' => $badge,
			'badge_label' => $label,
			'mode'        => 'Samostatná licence pluginu',
			'subline'     => $license['key'] !== '' ? 'Licenční kód: ' . $license['key'] : 'Zatím nebyl uložen žádný licenční kód.',
			'valid_to'    => $format_date((string) $license['valid_to']),
			'domain'      => (string) $license['domain'],
			'last_check'  => $format_dt((int) $license['last_check']),
			'message'     => $license['message'] !== '' ? $license['message'] : 'Bez platné licence plugin přestává provádět zalamování.',
		));
	}

	public function maybe_refresh_plugin_license() {
		$management = $this->get_management_context();
		if ($management['is_active']) {
			return;
		}

		$license = $this->get_license_state();
		if ($license['key'] === '') {
			return;
		}
		if (!current_user_can('manage_options')) {
			return;
		}
		if (!empty($_POST['license_key'])) {
			return;
		}
		if ($license['last_check'] > 0 && (time() - (int) $license['last_check']) < (12 * HOUR_IN_SECONDS)) {
			return;
		}
		$this->refresh_plugin_license('admin-auto');
	}

	private function refresh_plugin_license(string $reason = 'manual', string $override_key = ''): array {
		$key = $override_key !== '' ? sanitize_text_field($override_key) : (string) $this->get_license_state()['key'];
		if ($key === '') {
			$this->update_license_state(array(
				'key' => '',
				'status' => 'missing',
				'type' => '',
				'valid_to' => '',
				'domain' => '',
				'message' => 'Licenční kód zatím není uložený.',
				'last_check' => time(),
			));
			return array('ok' => false, 'error' => 'missing_key');
		}

		$site_id = (string) get_option('swg_site_id', '');
		$payload = array(
			'license_key' => $key,
			'plugin_slug' => self::PLUGIN_SLUG,
			'site_id' => $site_id,
			'site_url' => home_url('/'),
			'reason' => $reason,
			'plugin_version' => $this->get_plugin_version(),
		);

		$res = wp_remote_post(rtrim(self::HUB_BASE, '/') . '/wp-json/swlic/v2/plugin-license', array(
			'timeout' => 20,
			'headers' => array('Content-Type' => 'application/json'),
			'body' => wp_json_encode($payload, JSON_UNESCAPED_SLASHES),
		));

		if (is_wp_error($res)) {
			$this->update_license_state(array(
				'key' => $key,
				'status' => 'error',
				'message' => $res->get_error_message(),
				'last_check' => time(),
			));
			return array('ok' => false, 'error' => $res->get_error_message());
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		$body = (string) wp_remote_retrieve_body($res);
		$data = json_decode($body, true);
		if ($code < 200 || $code >= 300 || !is_array($data)) {
			$api_message = 'Nepodařilo se ověřit licenci.';
			if (is_array($data) && !empty($data['message'])) {
				$api_message = sanitize_text_field((string) $data['message']);
			} elseif ($code > 0) {
				$api_message = 'Hub vrátil neočekávanou odpověď (HTTP ' . $code . ').';
			}

			$this->update_license_state(array(
				'key' => $key,
				'status' => 'error',
				'message' => $api_message,
				'last_check' => time(),
			));
			return array(
				'ok' => false,
				'error' => 'bad_response',
				'message' => $api_message,
				'http_code' => $code,
			);
		}

		$this->update_license_state(array(
			'key' => $key,
			'status' => sanitize_key((string) ($data['status'] ?? 'missing')),
			'type' => sanitize_key((string) ($data['licence_type'] ?? 'plugin_single')),
			'valid_to' => sanitize_text_field((string) ($data['valid_to'] ?? '')),
			'domain' => sanitize_text_field((string) ($data['assigned_domain'] ?? '')),
			'message' => sanitize_text_field((string) ($data['message'] ?? '')), 
			'last_check' => time(),
			'last_success' => !empty($data['ok']) ? time() : 0,
		));

		return $data;
	}

	public function handle_verify_license() {
		if (!current_user_can('manage_options')) {
			wp_die('Zakázáno.', 'Zakázáno', array('response' => 403));
		}
		check_admin_referer('sw_zalomeni_verify_license');
		$key = sanitize_text_field((string) ($_POST['license_key'] ?? ''));
		$result = $this->refresh_plugin_license('manual', $key);
		$message = !empty($result['message']) ? (string) $result['message'] : (!empty($result['ok']) ? 'Licence byla ověřena.' : 'Licenci se nepodařilo ověřit.');
		wp_safe_redirect(add_query_arg('swz_license_message', rawurlencode($message), admin_url('options-general.php?page=sw-zalomeni')));
		exit;
	}

	public function handle_remove_license() {
		if (!current_user_can('manage_options')) {
			wp_die('Zakázáno.', 'Zakázáno', array('response' => 403));
		}
		check_admin_referer('sw_zalomeni_remove_license');
		delete_option(self::LICENSE_OPTION);
		wp_safe_redirect(add_query_arg('swz_license_message', rawurlencode('Licenční kód byl odebrán.'), admin_url('options-general.php?page=sw-zalomeni')));
		exit;
	}

	public function block_direct_deactivate() {
		$management = $this->get_management_context();
		if (!$management['is_active']) {
			return;
		}

		$action = isset($_GET['action']) ? sanitize_key((string) $_GET['action']) : '';
		$plugin = isset($_GET['plugin']) ? sanitize_text_field((string) $_GET['plugin']) : '';
		if ($action === 'deactivate' && $plugin === plugin_basename(__FILE__)) {
			wp_die('Tento plugin nelze deaktivovat při aktivní správě webu.', 'Chráněný plugin', array('response' => 403));
		}
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

add_filter('plugin_action_links', function($actions, $plugin_file) {

    // název tohoto pluginu (automaticky)
    if ($plugin_file !== plugin_basename(__FILE__)) {
        return $actions;
    }

    // pokud běží správa webu
    if (function_exists('sw_guard_get_management_status')) {
        $status = sw_guard_get_management_status();

        if ($status === 'ACTIVE') {
            unset($actions['deactivate']);
        }
    }

    return $actions;

}, 10, 2);

SW_Zalomeni_Plugin::instance();
