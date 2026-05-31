<?php
/**
 * WP Inventory Manager — Free Plugin Registration
 *
 * Shows a one-time opt-in notice after activation, pre-filled with the
 * current admin's display name and email. On submit, POSTs to the
 * wpinventory.com registration endpoint which creates an EDD customer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPIMRegistration {

	// Endpoint on wpinventory.com that receives the registration.
	const ENDPOINT = 'https://wpinventory.com/wp-json/wpim/v1/register';

	// Shared secret — must match WPIM_REGISTRATION_SECRET on wpinventory.com.
	const SECRET = 'wpim-free-r3g1str4t10n-k3y-2024';

	// How long (seconds) to keep showing the notice if not dismissed (30 days).
	const NOTICE_TTL = 2592000;

	public static function init() {
		// On activation: set redirect flag and notice flag.
		register_activation_hook(
			WPIM_PLUGIN_FILE,
			[ __CLASS__, 'on_activate' ]
		);

		// Redirect to Items page on first load after activation.
		add_action( 'admin_init', [ __CLASS__, 'maybe_redirect' ] );

		// Show the notice.
		add_action( 'admin_notices', [ __CLASS__, 'maybe_show_notice' ] );

		// Enqueue inline JS for the notice.
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );

		// AJAX handlers (logged-in users only).
		add_action( 'wp_ajax_wpim_register',  [ __CLASS__, 'ajax_register' ] );
		add_action( 'wp_ajax_wpim_dismiss_registration', [ __CLASS__, 'ajax_dismiss' ] );

		// Daily cron to retry pending submissions.
		add_action( 'wpim_retry_registration', [ __CLASS__, 'retry_pending' ] );
		if ( ! wp_next_scheduled( 'wpim_retry_registration' ) ) {
			wp_schedule_event( time(), 'daily', 'wpim_retry_registration' );
		}
	}

	// -------------------------------------------------------------------------
	// Activation
	// -------------------------------------------------------------------------

	public static function on_activate() {
		// Only show the notice if the user has not already registered.
		if ( ! get_option( 'wpim_registered' ) ) {
			set_transient( 'wpim_show_register_notice', true, self::NOTICE_TTL );
		}
		set_transient( 'wpim_activation_redirect', true, 30 );
	}

	// -------------------------------------------------------------------------
	// Redirect to Items page on first admin load after activation
	// -------------------------------------------------------------------------

	public static function maybe_redirect() {
		if ( get_transient( 'wpim_activation_redirect' ) ) {
			delete_transient( 'wpim_activation_redirect' );
			wp_safe_redirect( admin_url( 'admin.php?page=wpinventory_main' ) );
			exit;
		}
	}

	// -------------------------------------------------------------------------
	// Admin notice
	// -------------------------------------------------------------------------

	public static function maybe_show_notice() {
		if ( ! get_transient( 'wpim_show_register_notice' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user         = wp_get_current_user();
		$display_name = esc_attr( $user->display_name );
		$email        = esc_attr( $user->user_email );

		?>
		<div class="notice wpim-register-notice" id="wpim-register-notice" style="border-left-color:#2271b1;padding:0;">
			<div style="display:flex;gap:14px;align-items:flex-start;padding:16px 44px 16px 18px;position:relative;">

				<!-- Icon -->
				<svg width="38" height="38" viewBox="0 0 38 38" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;margin-top:2px;">
					<rect width="38" height="38" rx="4" fill="#2271b1"/>
					<path d="M9 13h20v17H9z" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/>
					<path d="M9 13l4-4h12l4 4" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/>
					<path d="M15 13v5h8v-5" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/>
				</svg>

				<div>
					<strong style="display:block;font-size:14px;color:#1d2327;margin-bottom:10px;">
						<?php esc_html_e( 'Get security updates &amp; release notes', 'wpinventory' ); ?>
					</strong>

					<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;" id="wpim-register-form">
						<input
							type="text"
							id="wpim-reg-name"
							value="<?php echo $display_name; ?>"
							placeholder="<?php esc_attr_e( 'Your name', 'wpinventory' ); ?>"
							style="padding:5px 10px;font-size:13px;border:1px solid #8c8f94;border-radius:3px;width:175px;"
						/>
						<input
							type="email"
							id="wpim-reg-email"
							value="<?php echo $email; ?>"
							placeholder="<?php esc_attr_e( 'Email', 'wpinventory' ); ?>"
							style="padding:5px 10px;font-size:13px;border:1px solid #8c8f94;border-radius:3px;width:215px;"
						/>
						<button
							type="button"
							id="wpim-reg-submit"
							class="button button-primary"
							style="font-size:13px;"
						>
							<?php esc_html_e( 'Yes, keep me updated', 'wpinventory' ); ?>
						</button>
						<span style="font-size:12px;color:#646970;">
							<a href="#" id="wpim-reg-dismiss" style="color:#646970;text-decoration:none;">
								<?php esc_html_e( 'No thanks', 'wpinventory' ); ?>
							</a>
							&middot;
							<a href="https://wpinventory.com/privacy-policy/" target="_blank" rel="noopener" style="color:#646970;text-decoration:none;">
								<?php esc_html_e( 'Privacy Policy', 'wpinventory' ); ?>
							</a>
						</span>
					</div>

					<!-- Success message (hidden until submit) -->
					<p id="wpim-reg-success" style="display:none;margin:0;font-size:13px;color:#1a7a37;">
						<?php esc_html_e( 'You\'re in! We\'ll keep you posted.', 'wpinventory' ); ?>
					</p>
				</div>

				<!-- Dismiss X -->
				<button
					type="button"
					id="wpim-reg-close"
					style="position:absolute;top:10px;right:12px;background:none;border:none;color:#787c82;font-size:18px;cursor:pointer;line-height:1;padding:2px 4px;"
					aria-label="<?php esc_attr_e( 'Dismiss', 'wpinventory' ); ?>"
				>&times;</button>

			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Scripts
	// -------------------------------------------------------------------------

	public static function enqueue_scripts() {
		if ( ! get_transient( 'wpim_show_register_notice' ) ) {
			return;
		}

		wp_add_inline_script( 'jquery', self::inline_js() );
	}

	private static function inline_js() {
		$nonce = wp_create_nonce( 'wpim_registration' );
		$ajax  = admin_url( 'admin-ajax.php' );

		return <<<JS
jQuery(function($) {

	function dismissNotice() {
		$('#wpim-register-notice').fadeOut(200);
		$.post('{$ajax}', {
			action: 'wpim_dismiss_registration',
			nonce:  '{$nonce}'
		});
	}

	$('#wpim-reg-close, #wpim-reg-dismiss').on('click', function(e) {
		e.preventDefault();
		dismissNotice();
	});

	$('#wpim-reg-submit').on('click', function() {
		var name  = $.trim($('#wpim-reg-name').val());
		var email = $.trim($('#wpim-reg-email').val());

		if ( ! name || ! email ) {
			alert('Please enter your name and email.');
			return;
		}

		$(this).prop('disabled', true).text('Saving...');

		$.post('{$ajax}', {
			action: 'wpim_register',
			nonce:  '{$nonce}',
			name:   name,
			email:  email
		}, function(response) {
			if ( response.success ) {
				$('#wpim-register-form').hide();
				$('#wpim-reg-success').fadeIn();
				setTimeout(dismissNotice, 2500);
			} else {
				$('#wpim-reg-submit').prop('disabled', false).text('Yes, keep me updated');
				alert(response.data || 'Something went wrong. Please try again.');
			}
		});
	});

});
JS;
	}

	// -------------------------------------------------------------------------
	// AJAX: submit registration
	// -------------------------------------------------------------------------

	public static function ajax_register() {
		check_ajax_referer( 'wpim_registration', 'nonce' );

		$name  = sanitize_text_field( wp_unslash( $_POST['name']  ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );

		if ( ! $name || ! is_email( $email ) ) {
			wp_send_json_error( 'Invalid name or email.' );
		}

		// 1. Save locally immediately — never fails, consent is recorded regardless.
		update_option( 'wpim_registered', [
			'email'     => $email,
			'name'      => $name,
			'timestamp' => time(),
			'synced'    => false,
		] );
		delete_transient( 'wpim_show_register_notice' );

		// 2. Attempt to sync to wpinventory.com.
		$synced = self::sync_to_endpoint( $name, $email );

		if ( $synced ) {
			update_option( 'wpim_registered', [
				'email'     => $email,
				'name'      => $name,
				'timestamp' => time(),
				'synced'    => true,
			] );
		}
		// If not synced, cron will retry — no error shown to the user.

		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// Shared: POST to wpinventory.com endpoint
	// -------------------------------------------------------------------------

	private static function sync_to_endpoint( $name, $email ) {
		$endpoint = defined( 'WPIM_TEST_BROKEN_ENDPOINT' ) && WPIM_TEST_BROKEN_ENDPOINT
			? 'https://thisdomaindoesnotexist.wpinventory.com/broken'
			: self::ENDPOINT;

		$response = wp_remote_post( $endpoint, [
			'timeout' => 10,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'name'   => $name,
				'email'  => $email,
				'secret' => self::SECRET,
				'source' => 'wpim-core-' . WPIMConstants::VERSION,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	// -------------------------------------------------------------------------
	// Cron: retry pending (unsynced) registrations
	// -------------------------------------------------------------------------

	public static function retry_pending() {
		$registered = get_option( 'wpim_registered' );

		if ( empty( $registered ) || ! empty( $registered['synced'] ) ) {
			return; // Nothing pending.
		}

		$synced = self::sync_to_endpoint( $registered['name'], $registered['email'] );

		if ( $synced ) {
			$registered['synced'] = true;
			update_option( 'wpim_registered', $registered );
		}
	}

	// -------------------------------------------------------------------------
	// AJAX: dismiss without registering
	// -------------------------------------------------------------------------

	public static function ajax_dismiss() {
		check_ajax_referer( 'wpim_registration', 'nonce' );
		delete_transient( 'wpim_show_register_notice' );
		wp_send_json_success();
	}
}

WPIMRegistration::init();
