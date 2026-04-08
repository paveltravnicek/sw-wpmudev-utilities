<?php
/**
 * Plugin Name: WPMU DEV Doplňky
 * Description: Smart Websites utility plugin pro WPMU DEV: přepis hlášky při chybném přihlášení, whitelabel OTP e-mail pro Defender, Base64 patch pro Branda Pro a nastavení e-mailu pro CSV exporty z Forminatoru.
 * Version: 1.0
 * Author: Smart Websites
 * Author URI: https://smart-websites.cz
 * Update URI: https://github.com/paveltravnicek/sw-wpmudev-utilities/
 * Text Domain: sw-wpmudev-utilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

$swUpdateChecker->setBranch('main');
$swUpdateChecker->getVcsApi()->enableReleaseAssets('/\.zip$/i');

final class SW_WPMUDEV_Utilities {
	private const OPTION_KEY = 'sw_forminator_csv_mail';
	private const MENU_SLUG  = 'sw-forminator-csv';

	public static function init(): void {
		add_action( 'login_enqueue_scripts', [ __CLASS__, 'print_login_error_override_script' ] );
		add_action( 'admin_menu', [ __CLASS__, 'register_admin_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
		add_filter( 'pre_update_option_ub_email_template', [ __CLASS__, 'allow_branda_base64_images' ], 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ __CLASS__, 'add_plugin_action_links' ] );
		add_action( 'plugins_loaded', [ __CLASS__, 'register_defender_otp_whitelabel' ], -9999 );
		add_filter( 'wp_mail', [ __CLASS__, 'filter_forminator_csv_mail' ], 20 );
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

	public static function add_plugin_action_links( array $links ): array {
		if ( ! self::is_allowed_admin_user() ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ),
				esc_html__( 'Nastavení', 'sw-wpmudev-utilities' )
			)
		);

		return $links;
	}

	public static function print_login_error_override_script(): void {
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
		if ( ! defined( 'DEFENDER_VERSION' ) ) {
			return;
		}

		add_filter(
			'random_password',
			function( $password, $length, $special_chars, $extra_special_chars ) {
				$GLOBALS['_wds_last_random_pwd'] = $password;
				return $password;
			},
			10,
			4
		);

		add_filter(
			'wp_mail',
			function( $mail ) {
				if ( ! is_array( $mail ) ) {
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
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( __FILE__, false, false );
		return ! empty( $data['Version'] ) ? (string) $data['Version'] : '1.3.1';
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
			file_exists( $base_dir . 'admin.css' ) ? (string) filemtime( $base_dir . 'admin.css' ) : '1.3.1'
		);
	}

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_allowed_admin_user() ) {
			wp_die( esc_html__( 'Na tuto stránku nemáte oprávnění.', 'sw-wpmudev-utilities' ) );
		}

		$defaults = self::get_csv_defaults();
		$options  = wp_parse_args( get_option( self::OPTION_KEY, [] ), $defaults );

		if ( isset( $_POST['sw_forminator_csv_save'] ) ) {
			check_admin_referer( 'sw_forminator_csv_save' );

			$options['to']      = sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) );
			$options['subject'] = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
			$options['message'] = wp_kses_post( wp_unslash( $_POST['message'] ?? '' ) );

			update_option( self::OPTION_KEY, $options );

			echo '<div class="notice notice-success is-dismissible"><p><strong>Nastavení bylo uloženo.</strong></p></div>';
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
				</div>
			</div>

			<div class="swu-card">
				<form method="post">
					<?php wp_nonce_field( 'sw_forminator_csv_save' ); ?>

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

					<?php submit_button( 'Uložit nastavení', 'primary', 'sw_forminator_csv_save' ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	public static function filter_forminator_csv_mail( $args ) {
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
