<?php
/**
 * Plugin Name: WPMU DEV Doplňky
 * Description: Smart Websites utility plugin pro WPMU DEV: přepis hlášky při chybném přihlášení, whitelabel OTP e-mail pro Defender, Base64 patch pro Branda Pro a nastavení e-mailu pro CSV exporty z Forminatoru.
 * Version: 1.1
 * Author: Smart Websites
 * Author URI: https://smart-websites.cz
 * Update URI: https://github.com/paveltravnicek/sw-wpmudev-utilities/
 * Text Domain: sw-wpmudev-utilities
 * SW Plugin: yes
 * SW Service Type: passive
 * SW License Group: both
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$swUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/paveltravnicek/sw-wpmudev-utilities/',
	__FILE__,
	'sw-wpmudev-utilities'
);

$swUpdateChecker->setBranch( 'main' );
$swUpdateChecker->getVcsApi()->enableReleaseAssets( '/\.zip$/i' );

final class SW_WPMUDEV_Utilities {
	private const OPTION_KEY        = 'sw_forminator_csv_mail';
	private const MENU_SLUG         = 'sw-forminator-csv';
	private const LICENSE_OPTION    = 'sw_wpmudev_utilities_license';
	private const LICENSE_CRON_HOOK = 'sw_wpmudev_utilities_license_daily_check';
	private const HUB_BASE          = 'https://smart-websites.cz';
	private const PLUGIN_SLUG       = 'sw-wpmudev-utilities';

	public static function init(): void {
		register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );
		register_deactivation_hook( __FILE__, [ __CLASS__, 'deactivate' ] );

		add_action( 'login_enqueue_scripts', [ __CLASS__, 'print_login_error_override_script' ] );
		add_action( 'admin_menu', [ __CLASS__, 'register_admin_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
		add_filter( 'pre_update_option_ub_email_template', [ __CLASS__, 'allow_branda_base64_images' ], 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ __CLASS__, 'add_plugin_action_links' ] );
		add_action( 'plugins_loaded', [ __CLASS__, 'register_defender_otp_whitelabel' ], -9999 );
		add_filter( 'wp_mail', [ __CLASS__, 'filter_forminator_csv_mail' ], 20 );
		add_action( self::LICENSE_CRON_HOOK, [ __CLASS__, 'cron_refresh_plugin_license' ] );

		if ( is_admin() ) {
			add_action( 'admin_post_sw_wpmudev_utilities_verify_license', [ __CLASS__, 'handle_verify_license' ] );
			add_action( 'admin_post_sw_wpmudev_utilities_remove_license', [ __CLASS__, 'handle_remove_license' ] );
			add_action( 'admin_init', [ __CLASS__, 'maybe_refresh_plugin_license' ] );
			add_action( 'admin_init', [ __CLASS__, 'block_direct_deactivate' ] );
		}
	}

	public static function activate(): void {
		if ( ! wp_next_scheduled( self::LICENSE_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::LICENSE_CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::LICENSE_CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::LICENSE_CRON_HOOK );
		}
	}

	public static function cron_refresh_plugin_license(): void {
		self::refresh_plugin_license( 'cron' );
	}

	private static function get_allowed_admin_identities(): array {
		return [
			'usernames' => [
				'paveltravnicek',
			],
			'emails'    => [
				'pavel.travnicek@outlook.com',
				'pavel@travnicek.online',
				'info@smart-websites.cz',
			],
		];
	}

	private static function is_allowed_admin_user(): bool {
		$user = wp_get_current_user();

		if ( ! ( $user instanceof WP_User ) || empty( $user->ID ) ) {
			return false;
		}

		$identities = self::get_allowed_admin_identities();
		$username   = strtolower( (string) $user->user_login );
		$email      = strtolower( (string) $user->user_email );

		if ( in_array( $username, array_map( 'strtolower', $identities['usernames'] ), true ) ) {
			return true;
		}

		return in_array( $email, array_map( 'strtolower', $identities['emails'] ), true );
	}

	private static function default_license_state(): array {
		return [
			'key'          => '',
			'status'       => 'missing',
			'type'         => '',
			'valid_to'     => '',
			'domain'       => '',
			'message'      => '',
			'last_check'   => 0,
			'last_success' => 0,
		];
	}

	private static function get_license_state(): array {
		$state = get_option( self::LICENSE_OPTION, [] );
		if ( ! is_array( $state ) ) {
			$state = [];
		}

		return wp_parse_args( $state, self::default_license_state() );
	}

	private static function update_license_state( array $data ): void {
		$current = self::get_license_state();
		$new     = array_merge( $current, $data );

		$new['key']          = sanitize_text_field( (string) ( $new['key'] ?? '' ) );
		$new['status']       = sanitize_key( (string) ( $new['status'] ?? 'missing' ) );
		$new['type']         = sanitize_key( (string) ( $new['type'] ?? '' ) );
		$new['valid_to']     = sanitize_text_field( (string) ( $new['valid_to'] ?? '' ) );
		$new['domain']       = sanitize_text_field( (string) ( $new['domain'] ?? '' ) );
		$new['message']      = sanitize_text_field( (string) ( $new['message'] ?? '' ) );
		$new['last_check']   = (int) ( $new['last_check'] ?? 0 );
		$new['last_success'] = (int) ( $new['last_success'] ?? 0 );

		update_option( self::LICENSE_OPTION, $new, false );
	}

	private static function get_management_context(): array {
		$guard_present       = function_exists( 'sw_guard_get_service_state' );
		$management_status   = $guard_present ? (string) get_option( 'swg_management_status', 'NONE' ) : 'NONE';
		$service_state       = $guard_present ? (string) sw_guard_get_service_state( self::PLUGIN_SLUG ) : 'off';
		$guard_last_success  = $guard_present ? (int) get_option( 'swg_last_success_ts', 0 ) : 0;
		$connected_recently  = $guard_last_success > 0 && ( time() - $guard_last_success ) <= ( 8 * DAY_IN_SECONDS );

		return [
			'guard_present'       => $guard_present,
			'management_status'   => $management_status,
			'service_state'       => in_array( $service_state, [ 'active', 'passive', 'off' ], true ) ? $service_state : 'off',
			'guard_last_success'  => $guard_last_success,
			'connected_recently'  => $connected_recently,
			'is_active'           => $guard_present && $connected_recently && 'ACTIVE' === $management_status && 'active' === $service_state,
		];
	}

	private static function has_active_standalone_license(): bool {
		$license = self::get_license_state();
		return '' !== $license['key'] && 'active' === $license['status'] && 'plugin_single' === $license['type'];
	}

	private static function plugin_is_operational(): bool {
		$management = self::get_management_context();
		if ( $management['is_active'] ) {
			return true;
		}

		return self::has_active_standalone_license();
	}

	public static function add_plugin_action_links( array $links ): array {
		if ( self::is_allowed_admin_user() ) {
			array_unshift(
				$links,
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ),
					esc_html__( 'Nastavení', 'sw-wpmudev-utilities' )
				)
			);
		}

		$management = self::get_management_context();
		if ( $management['is_active'] ) {
			unset( $links['deactivate'] );
		}

		return $links;
	}

	public static function print_login_error_override_script(): void {
		if ( ! self::plugin_is_operational() ) {
			return;
		}
		?>
		<script>
			document.addEventListener('DOMContentLoaded', function () {
				const el = document.getElementById('login_error');
				if (!el) return;

				if (el.textContent.includes('Stop guessing!')) {
					el.innerHTML = '<strong>CHYBA</strong>: Přihlášení se bohužel nezdařilo. Zkuste to prosím ještě jednou, příp. si <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">obnovte heslo</a>.';
				}
			});
		</script>
		<?php
	}

	public static function register_defender_otp_whitelabel(): void {
		if ( ! self::plugin_is_operational() ) {
			return;
		}

		if ( ! defined( 'DEFENDER_VERSION' ) ) {
			return;
		}

		add_filter(
			'random_password',
			function ( $password, $length, $special_chars, $extra_special_chars ) {
				$GLOBALS['_wds_last_random_pwd'] = $password;
				return $password;
			},
			10,
			4
		);

		add_filter(
			'wp_mail',
			function ( $mail ) {
				if ( ! self::plugin_is_operational() || ! is_array( $mail ) ) {
					return $mail;
				}

				if ( __( 'Váš kód OTP (One-Time Password)', 'wpdef' ) === $mail['subject'] || 'Váš kód OTP (One-Time Password)' === $mail['subject'] ) {
					$data = [
						'signature' => 'Cheers,<br />The ACME Company.',
						'address'   => '1273 Rockefeller St. USA. <a href="https://google.com">Visit our site</a>',
					];

					if ( function_exists( 'wpmudev_defender_get_otp_email_body' ) ) {
						$message         = wpmudev_defender_get_otp_email_body( (string) $mail['message'], $data );
						$mail['message'] = $message;
					}
				}

				return $mail;
			}
		);
	}

	public static function allow_branda_base64_images( $value, $old_value, $option ) {
		if ( ! self::plugin_is_operational() ) {
			return $value;
		}

		if (
			current_user_can( 'unfiltered_html' ) &&
			! empty( $_POST['module'] ) &&
			'email-template' === $_POST['module'] &&
			! empty( $_POST['simple_options']['content']['email'] )
		) {
			if ( ! empty( $value['content']['email'] ) ) {
				$value['content']['email'] = wp_unslash( $_POST['simple_options']['content']['email'] );
			}
		}

		return $value;
	}

	private static function get_csv_defaults(): array {
		return [
			'to'      => get_option( 'admin_email' ),
			'subject' => 'Denní přehled vyplněných formulářů (posledních 24 hodin)',
			'message' => "<p>Dobrý den,</p>\n\n<p>v příloze tohoto e-mailu Vám zasíláme přehled odpovědí z formulářů vyplněných během <strong>posledních 24 hodin</strong>. CSV soubor je odesílán pouze v případě, že byl alespoň jeden formulář skutečně odeslán.</p>\n\n<p>Tento automatický přehled slouží jako <strong>praktický bonus</strong> ke správě webových stránek – umožňuje mít data k dispozici i pro případ, že by se zpráva z formuláře z jakéhokoliv důvodu nedoručila, zapadla mezi ostatní e-maily nebo jste potřebovali kompletní CSV přehled.</p>\n\n<p>CSV soubor můžete kdykoliv využít pro archivaci, import do jiných systémů nebo další zpracování dle Vašich potřeb.</p>\n\n<p>V případě dotazů nebo požadavku na úpravu rozsahu exportu nás neváhejte kontaktovat.</p>\n\n<p>S přáním hezkého dne<br><strong>Smart Websites</strong><br><a href=\"https://smart-websites.cz\">https://smart-websites.cz</a></p>",
		];
	}

	private static function get_plugin_version(): string {
		static $version = null;

		if ( null !== $version ) {
			return $version;
		}

		$data    = get_file_data( __FILE__, [ 'Version' => 'Version' ], 'plugin' );
		$version = ! empty( $data['Version'] ) ? (string) $data['Version'] : '1.0.0';
		return $version;
	}

	public static function register_admin_page(): void {
		if ( ! self::is_allowed_admin_user() ) {
			return;
		}

		add_management_page(
			'CSV export formulářů',
			'CSV export formulářů',
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	public static function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'tools_page_' . self::MENU_SLUG !== $hook_suffix || ! self::is_allowed_admin_user() ) {
			return;
		}

		$base_url = plugin_dir_url( __FILE__ ) . 'assets/';
		$base_dir = plugin_dir_path( __FILE__ ) . 'assets/';

		wp_enqueue_style(
			'sw-wpmudev-utilities-admin',
			$base_url . 'admin.css',
			[],
			file_exists( $base_dir . 'admin.css' ) ? (string) filemtime( $base_dir . 'admin.css' ) : self::get_plugin_version()
		);

		wp_enqueue_script(
			'sw-wpmudev-utilities-admin',
			$base_url . 'admin.js',
			[],
			file_exists( $base_dir . 'admin.js' ) ? (string) filemtime( $base_dir . 'admin.js' ) : self::get_plugin_version(),
			true
		);
	}

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_allowed_admin_user() ) {
			wp_die( esc_html__( 'Na tuto stránku nemáte oprávnění.', 'sw-wpmudev-utilities' ) );
		}

		$defaults          = self::get_csv_defaults();
		$options           = wp_parse_args( get_option( self::OPTION_KEY, [] ), $defaults );
		$license           = self::get_license_state();
		$management        = self::get_management_context();
		$is_operational    = self::plugin_is_operational();
		$can_edit_settings = $is_operational;
		$status_payload    = self::get_license_panel_data( $license, $management, $is_operational );

		if ( isset( $_POST['sw_forminator_csv_save'] ) ) {
			check_admin_referer( 'sw_forminator_csv_save' );

			if ( ! $can_edit_settings ) {
				$_saved_notice = '<div class="notice notice-warning"><p>' . esc_html__( 'Nastavení nelze uložit – plugin momentálně nemá platnou licenci.', 'sw-wpmudev-utilities' ) . '</p></div>';
			} else {
				$options['to']      = sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) );
				$options['subject'] = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
				$options['message'] = wp_kses_post( wp_unslash( $_POST['message'] ?? '' ) );

				update_option( self::OPTION_KEY, $options );
				$_saved_notice = '<div class="notice notice-success"><p>' . esc_html__( 'Nastavení bylo uloženo.', 'sw-wpmudev-utilities' ) . '</p></div>';
			}
		}
		?>
		<div class="wrap swu-wrap">
			<div class="swu-hero">
				<div class="swu-hero__content">
					<span class="swu-badge">Smart Websites</span>
					<h1>CSV export formulářů</h1>
					<p class="swu-intro">Nastavení e-mailu pro denní CSV exporty z Forminatoru. E-mail se odesílá jako HTML, aby se mohla použít šablona z pluginů typu Branda Pro.</p>
				</div>
				<div class="swu-hero__meta">
					<div class="swu-stat">
						<strong><?php echo esc_html( self::get_plugin_version() ); ?></strong>
						<span><?php echo esc_html__( 'Verze pluginu', 'sw-wpmudev-utilities' ); ?></span>
					</div>
					<span class="swu-hero-status swu-hero-status--<?php echo $is_operational ? 'active' : 'inactive'; ?>">
						<span class="swu-hero-status__dot"></span>
						<?php echo $is_operational ? esc_html__( 'Platná licence', 'sw-wpmudev-utilities' ) : esc_html__( 'Licence chybí', 'sw-wpmudev-utilities' ); ?>
					</span>
				</div>
			</div>

			<div class="swu-page-notices">
				<?php if ( ! empty( $_saved_notice ) ) echo wp_kses_post( $_saved_notice ); ?>
				<?php if ( ! empty( $_GET['swu_license_message'] ) ) : ?>
					<div class="notice notice-success"><p><?php echo esc_html( sanitize_text_field( (string) $_GET['swu_license_message'] ) ); ?></p></div>
				<?php endif; ?>
				<?php if ( ! $can_edit_settings ) : ?>
					<div class="notice notice-warning"><p><?php echo esc_html__( 'Plugin momentálně nemá platnou licenci. Nastavení zůstává pouze pro čtení a funkce pluginu se neprovádějí.', 'sw-wpmudev-utilities' ); ?></p></div>
				<?php endif; ?>
			</div>

			<div class="swu-main-content">
			<div class="swu-card swu-card--licence">
				<div class="swu-card__head">
					<div>
						<h2><?php echo esc_html__( 'Licence pluginu', 'sw-wpmudev-utilities' ); ?></h2>
						<p class="swu-intro"><?php echo esc_html__( 'Plugin může běžet buď v rámci platné správy webu, nebo přes samostatnou licenci.', 'sw-wpmudev-utilities' ); ?></p>
					</div>
					<span class="swu-licence-badge swu-licence-badge--<?php echo esc_attr( $status_payload['badge_class'] ); ?>"><?php echo esc_html( $status_payload['badge_label'] ); ?></span>
				</div>

				<div class="swu-licence-grid">
					<div class="swu-licence-item">
						<span class="swu-licence-label"><?php echo esc_html__( 'Režim', 'sw-wpmudev-utilities' ); ?></span>
						<strong><?php echo esc_html( $status_payload['mode'] ); ?></strong>
						<?php if ( $status_payload['subline'] ) : ?><span><?php echo esc_html( $status_payload['subline'] ); ?></span><?php endif; ?>
					</div>
					<div class="swu-licence-item">
						<span class="swu-licence-label"><?php echo esc_html__( 'Platnost do', 'sw-wpmudev-utilities' ); ?></span>
						<strong><?php echo esc_html( $status_payload['valid_to'] ); ?></strong>
						<?php if ( $status_payload['domain'] ) : ?><span><?php echo esc_html( $status_payload['domain'] ); ?></span><?php endif; ?>
					</div>
					<div class="swu-licence-item">
						<span class="swu-licence-label"><?php echo esc_html__( 'Poslední ověření', 'sw-wpmudev-utilities' ); ?></span>
						<strong><?php echo esc_html( $status_payload['last_check'] ); ?></strong>
						<?php if ( $status_payload['message'] ) : ?><span><?php echo esc_html( $status_payload['message'] ); ?></span><?php endif; ?>
					</div>
				</div>

				<?php if ( ! $management['is_active'] ) : ?>
					<div class="swu-license-form-wrap">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swu-license-form">
							<?php wp_nonce_field( 'sw_wpmudev_utilities_verify_license' ); ?>
							<input type="hidden" name="action" value="sw_wpmudev_utilities_verify_license">
							<label for="sw_wpmudev_utilities_license_key"><strong><?php echo esc_html__( 'Licenční kód pluginu', 'sw-wpmudev-utilities' ); ?></strong></label>
							<input type="text" id="sw_wpmudev_utilities_license_key" name="license_key" value="<?php echo esc_attr( $license['key'] ); ?>" class="regular-text" placeholder="SWLIC-..." />
							<p class="description"><?php echo esc_html__( 'Použijte pouze pro samostatnou licenci pluginu. Pokud máte Správu webu, kód vyplňovat nemusíte.', 'sw-wpmudev-utilities' ); ?></p>
							<div class="swu-license-actions">
								<button type="submit" class="button button-primary"><?php echo esc_html__( 'Ověřit a uložit licenci', 'sw-wpmudev-utilities' ); ?></button>
								<?php if ( '' !== $license['key'] ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sw_wpmudev_utilities_remove_license' ), 'sw_wpmudev_utilities_remove_license' ) ); ?>" class="button button-secondary"><?php echo esc_html__( 'Odebrat licenční kód', 'sw-wpmudev-utilities' ); ?></a>
								<?php endif; ?>
							</div>
						</form>
					</div>
				<?php else : ?>
					<div class="swu-note"><?php echo esc_html__( 'Plugin je provozován v rámci Správy webu. Samostatný licenční kód není potřeba.', 'sw-wpmudev-utilities' ); ?></div>
				<?php endif; ?>
			</div>

			<div class="swu-card">
				<form method="post" class="<?php echo $can_edit_settings ? '' : 'is-readonly'; ?>">
					<?php wp_nonce_field( 'sw_forminator_csv_save' ); ?>

					<fieldset <?php disabled( ! $can_edit_settings ); ?>>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="to">Příjemce e-mailu</label></th>
								<td>
									<input type="text" name="to" id="to" class="regular-text" value="<?php echo esc_attr( (string) $options['to'] ); ?>">
									<p class="description">Jedna nebo více adres oddělených čárkou.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="subject">Předmět e-mailu</label></th>
								<td>
									<input type="text" name="subject" id="subject" class="large-text" value="<?php echo esc_attr( (string) $options['subject'] ); ?>">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="message">Text e-mailu (HTML)</label></th>
								<td>
									<textarea name="message" id="message" rows="16" class="large-text code"><?php echo esc_textarea( (string) $options['message'] ); ?></textarea>
									<p class="description">Můžete použít HTML, např. <code>&lt;p&gt;</code>, <code>&lt;br&gt;</code>, <code>&lt;strong&gt;</code>, <code>&lt;a&gt;</code>.</p>
								</td>
							</tr>
						</table>
					</fieldset>

					<?php submit_button( 'Uložit nastavení', 'primary', 'sw_forminator_csv_save', false, $can_edit_settings ? [] : [ 'disabled' => 'disabled' ] ); ?>
				</form>
			</div>
			</div><!-- /.swu-main-content -->
		</div>
		<?php
	}

	private static function get_license_panel_data( array $license, array $management, bool $is_operational ): array {
		$format_dt = static function ( int $ts ): string {
			return $ts > 0 ? wp_date( 'j. n. Y H:i', $ts ) : '—';
		};
		$format_date = static function ( string $ymd ): string {
			if ( '' === $ymd ) {
				return '—';
			}
			$ts = strtotime( $ymd . ' 12:00:00' );
			return $ts ? wp_date( 'j. n. Y', $ts ) : $ymd;
		};

		$base = [
			'badge_class' => 'inactive',
			'badge_label' => 'Licence chybí',
			'mode'        => 'Samostatná licence pluginu',
			'subline'     => '',
			'valid_to'    => '—',
			'domain'      => '',
			'last_check'  => '—',
			'message'     => '',
		];

		if ( $management['guard_present'] ) {
			if ( $management['is_active'] ) {
				return array_merge(
					$base,
					[
						'badge_class' => 'active',
						'badge_label' => 'Platná licence',
						'mode'        => 'Správa webu',
						'valid_to'    => $format_date( (string) get_option( 'swg_managed_until', '' ) ),
						'domain'      => (string) get_option( 'swg_licence_domain', '' ),
						'last_check'  => $format_dt( (int) $management['guard_last_success'] ),
					]
				);
			}
			if ( 'NONE' !== $management['management_status'] ) {
				return array_merge(
					$base,
					[
						'badge_class' => 'inactive',
						'badge_label' => 'Licence neplatná',
						'mode'        => 'Správa webu',
						'subline'     => 'Správa webu je po expiraci nebo omezená. Funkce pluginu se neprovádějí.',
						'valid_to'    => $format_date( (string) get_option( 'swg_managed_until', '' ) ),
						'domain'      => (string) get_option( 'swg_licence_domain', '' ),
						'last_check'  => $format_dt( (int) $management['guard_last_success'] ),
						'message'     => 'Po expiraci lze plugin deaktivovat nebo smazat.',
					]
				);
			}
		}

		if ( 'active' === $license['status'] ) {
			return array_merge(
				$base,
				[
					'badge_class' => 'active',
					'badge_label' => 'Platná licence',
					'mode'        => 'Samostatná licence pluginu',
					'subline'     => '' !== $license['key'] ? 'Licenční kód: ' . $license['key'] : '',
					'valid_to'    => $format_date( (string) $license['valid_to'] ),
					'domain'      => (string) $license['domain'],
					'last_check'  => $format_dt( (int) $license['last_success'] ),
					'message'     => '' !== $license['message'] ? $license['message'] : 'Plugin běží přes samostatnou licenci.',
				]
			);
		}

		$badge = $is_operational ? 'active' : 'inactive';
		$label = $is_operational ? 'Platná licence' : 'Licence chybí';

		return array_merge(
			$base,
			[
				'badge_class' => $badge,
				'badge_label' => $label,
				'mode'        => 'Samostatná licence pluginu',
				'subline'     => '' !== $license['key'] ? 'Licenční kód: ' . $license['key'] : 'Zatím nebyl uložen žádný licenční kód.',
				'valid_to'    => $format_date( (string) $license['valid_to'] ),
				'domain'      => (string) $license['domain'],
				'last_check'  => $format_dt( (int) $license['last_check'] ),
				'message'     => '' !== $license['message'] ? $license['message'] : 'Bez platné licence se funkce pluginu neprovádějí.',
			]
		);
	}

	public static function maybe_refresh_plugin_license(): void {
		$management = self::get_management_context();
		if ( $management['is_active'] ) {
			return;
		}

		$license = self::get_license_state();
		if ( '' === $license['key'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! empty( $_POST['license_key'] ) ) {
			return;
		}
		if ( $license['last_check'] > 0 && ( time() - (int) $license['last_check'] ) < ( 12 * HOUR_IN_SECONDS ) ) {
			return;
		}

		self::refresh_plugin_license( 'admin-auto' );
	}

	private static function refresh_plugin_license( string $reason = 'manual', string $override_key = '' ): array {
		$key = '' !== $override_key ? sanitize_text_field( $override_key ) : (string) self::get_license_state()['key'];
		if ( '' === $key ) {
			self::update_license_state(
				[
					'key'        => '',
					'status'     => 'missing',
					'type'       => '',
					'valid_to'   => '',
					'domain'     => '',
					'message'    => 'Licenční kód zatím není uložený.',
					'last_check' => time(),
				]
			);
			return [ 'ok' => false, 'error' => 'missing_key' ];
		}

		$site_id = (string) get_option( 'swg_site_id', '' );
		$payload = [
			'license_key'    => $key,
			'plugin_slug'    => self::PLUGIN_SLUG,
			'site_id'        => $site_id,
			'site_url'       => home_url( '/' ),
			'reason'         => $reason,
			'plugin_version' => self::get_plugin_version(),
		];

		$res = wp_remote_post(
			rtrim( self::HUB_BASE, '/' ) . '/wp-json/swlic/v2/plugin-license',
			[
				'timeout' => 20,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ),
			]
		);

		if ( is_wp_error( $res ) ) {
			self::update_license_state(
				[
					'key'        => $key,
					'status'     => 'error',
					'message'    => $res->get_error_message(),
					'last_check' => time(),
				]
			);
			return [ 'ok' => false, 'error' => $res->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$api_message = 'Nepodařilo se ověřit licenci.';
			if ( is_array( $data ) && ! empty( $data['message'] ) ) {
				$api_message = sanitize_text_field( (string) $data['message'] );
			} elseif ( $code > 0 ) {
				$api_message = 'Hub vrátil neočekávanou odpověď (HTTP ' . $code . ').';
			}

			self::update_license_state(
				[
					'key'        => $key,
					'status'     => 'error',
					'message'    => $api_message,
					'last_check' => time(),
				]
			);

			return [
				'ok'        => false,
				'error'     => 'bad_response',
				'message'   => $api_message,
				'http_code' => $code,
			];
		}

		self::update_license_state(
			[
				'key'          => $key,
				'status'       => sanitize_key( (string) ( $data['status'] ?? 'missing' ) ),
				'type'         => sanitize_key( (string) ( $data['licence_type'] ?? 'plugin_single' ) ),
				'valid_to'     => sanitize_text_field( (string) ( $data['valid_to'] ?? '' ) ),
				'domain'       => sanitize_text_field( (string) ( $data['assigned_domain'] ?? '' ) ),
				'message'      => sanitize_text_field( (string) ( $data['message'] ?? '' ) ),
				'last_check'   => time(),
				'last_success' => ! empty( $data['ok'] ) ? time() : 0,
			]
		);

		return $data;
	}

	public static function handle_verify_license(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Zakázáno.', 'Zakázáno', [ 'response' => 403 ] );
		}
		check_admin_referer( 'sw_wpmudev_utilities_verify_license' );
		$key     = sanitize_text_field( (string) ( $_POST['license_key'] ?? '' ) );
		$result  = self::refresh_plugin_license( 'manual', $key );
		$message = ! empty( $result['message'] ) ? (string) $result['message'] : ( ! empty( $result['ok'] ) ? 'Licence byla ověřena.' : 'Licenci se nepodařilo ověřit.' );
		wp_safe_redirect( add_query_arg( 'swu_license_message', rawurlencode( $message ), admin_url( 'tools.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	public static function handle_remove_license(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Zakázáno.', 'Zakázáno', [ 'response' => 403 ] );
		}
		check_admin_referer( 'sw_wpmudev_utilities_remove_license' );
		delete_option( self::LICENSE_OPTION );
		wp_safe_redirect( add_query_arg( 'swu_license_message', rawurlencode( 'Licenční kód byl odebrán.' ), admin_url( 'tools.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	public static function block_direct_deactivate(): void {
		$management = self::get_management_context();
		if ( ! $management['is_active'] ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
		$plugin = isset( $_GET['plugin'] ) ? sanitize_text_field( (string) $_GET['plugin'] ) : '';
		if ( 'deactivate' === $action && $plugin === plugin_basename( __FILE__ ) ) {
			wp_die( 'Tento plugin nelze deaktivovat při aktivní správě webu.', 'Chráněný plugin', [ 'response' => 403 ] );
		}
	}

	public static function filter_forminator_csv_mail( $args ) {
		if ( ! self::plugin_is_operational() ) {
			return $args;
		}

		if ( ! is_array( $args ) || empty( $args['attachments'] ) ) {
			return $args;
		}

		$attachments = $args['attachments'];
		if ( is_string( $attachments ) ) {
			$attachments = [ $attachments ];
		}

		if ( ! is_array( $attachments ) ) {
			return $args;
		}

		$has_csv = false;
		foreach ( $attachments as $att ) {
			if ( ! is_string( $att ) || '' === $att ) {
				continue;
			}

			$path = parse_url( $att, PHP_URL_PATH );
			$ext  = strtolower( pathinfo( $path ?: $att, PATHINFO_EXTENSION ) );

			if ( 'csv' === $ext ) {
				$has_csv = true;
				break;
			}
		}

		if ( ! $has_csv ) {
			return $args;
		}

		$options = wp_parse_args( get_option( self::OPTION_KEY, [] ), self::get_csv_defaults() );

		if ( ! empty( $options['to'] ) ) {
			$args['to'] = $options['to'];
		}

		$args['subject'] = $options['subject'] ?? self::get_csv_defaults()['subject'];
		$args['message'] = $options['message'] ?? self::get_csv_defaults()['message'];
		$args['headers'] = self::force_html_headers( $args['headers'] ?? [] );

		return $args;
	}

	private static function force_html_headers( $headers ) {
		if ( empty( $headers ) ) {
			return [ 'Content-Type: text/html; charset=UTF-8' ];
		}

		if ( is_string( $headers ) ) {
			$headers = [ $headers ];
		} elseif ( ! is_array( $headers ) ) {
			$headers = [];
		}

		$new = [];
		foreach ( $headers as $h ) {
			if ( ! is_string( $h ) ) {
				continue;
			}
			if ( 0 === stripos( $h, 'content-type:' ) ) {
				continue;
			}
			$new[] = $h;
		}

		$new[] = 'Content-Type: text/html; charset=UTF-8';
		return $new;
	}
}

SW_WPMUDEV_Utilities::init();

if ( ! function_exists( 'wpmudev_defender_get_otp_email_body' ) ) {
	function wpmudev_defender_get_otp_email_body( $message, $data = [] ) {
		$data['message'] = trim( $message );
		$data['code']    = '(Invalid code)';

		if ( ! empty( $GLOBALS['_wds_last_random_pwd'] ) ) {
			$data['code'] = $GLOBALS['_wds_last_random_pwd'];
		}

		if ( ! isset( $data['logo_img'] ) ) {
			$data['img'] = '';
		}

		$template = '<p>Dobrý den,</p><p>zasíláme Vám jednorázový kód pro přístup do administrace webových stránek:<br /><strong>{{ code }}</strong></p><p>Pokud jste o kód nežádali, s největší pravděpodobností se pokoušel ke správě vašeho webu přihlásit někdo cizí. Zvažte tedy změnu primárního hesla.</p><p>S pozdravem<br />Smart Websites tým</p>';

		foreach ( $data as $name => $value ) {
			$value    = 'message' !== $name ? nl2br( (string) $value ) : (string) $value;
			$template = str_ireplace( '{{ ' . $name . ' }}', $value, $template );
		}

		return $template;
	}
}
