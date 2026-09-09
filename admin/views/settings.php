<?php
/**
 * Admin settings view.
 *
 * @package CTA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notice = sanitize_text_field( wp_unslash( $_GET['cta_notice'] ?? '' ) );
?>
<div class="wrap cta-admin-wrap">
	<h1><?php esc_html_e( 'CTA LMS Settings', 'cta-lms' ); ?></h1>

	<?php if ( 'settings_saved' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved successfully.', 'cta-lms' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cta-admin-form">
		<?php wp_nonce_field( 'cta_save_settings' ); ?>
		<input type="hidden" name="action" value="cta_save_settings">

		<div class="cta-admin-panel cta-stripe-settings">
			<h2><?php esc_html_e( 'Stripe Integration', 'cta-lms' ); ?></h2>

			<?php
			$active_mode     = class_exists( 'CTA_Stripe' ) ? CTA_Stripe::get_mode() : (string) get_option( 'cta_stripe_mode', 'test' );
			$test_creds      = class_exists( 'CTA_Stripe' ) ? CTA_Stripe::get_credentials( 'test' ) : array( 'publishable_key' => '', 'secret_key' => '', 'webhook_secret' => '' );
			$live_creds      = class_exists( 'CTA_Stripe' ) ? CTA_Stripe::get_credentials( 'live' ) : array( 'publishable_key' => '', 'secret_key' => '', 'webhook_secret' => '' );
			$webhook_test    = class_exists( 'CTA_Stripe' ) ? CTA_Stripe::get_webhook_url( 'test' ) : add_query_arg( 'env', 'test', $webhook_url );
			$webhook_live    = class_exists( 'CTA_Stripe' ) ? CTA_Stripe::get_webhook_url( 'live' ) : add_query_arg( 'env', 'live', $webhook_url );
			$portal_test     = (string) get_option( 'cta_stripe_test_portal_configuration_id', '' );
			$portal_live     = (string) get_option( 'cta_stripe_live_portal_configuration_id', '' );
			$portal_config   = ( 'live' === $active_mode ) ? $portal_live : $portal_test;
			if ( '' === $portal_config ) {
				$portal_config = (string) get_option( 'cta_stripe_portal_configuration_id', '' );
			}
			$required_events = 'checkout.session.completed, customer.created, customer.updated, customer.subscription.created, customer.subscription.updated, customer.subscription.deleted, customer.subscription.trial_will_end, invoice.paid, invoice.payment_failed, payment_intent.succeeded, payment_intent.payment_failed, charge.refunded';
			?>

			<div class="cta-stripe-mode-switch" role="group" aria-label="<?php esc_attr_e( 'Active Stripe mode', 'cta-lms' ); ?>">
				<div class="cta-stripe-mode-switch__label">
					<strong><?php esc_html_e( 'Active Mode', 'cta-lms' ); ?></strong>
					<span class="description"><?php esc_html_e( 'Only one mode drives checkout, subscriptions, and webhook verification at a time. Test fully in Sandbox, then flip to Live.', 'cta-lms' ); ?></span>
				</div>
				<div class="cta-stripe-mode-switch__controls">
					<label class="cta-stripe-mode-option <?php echo 'test' === $active_mode ? 'is-active' : ''; ?>">
						<input type="radio" name="cta_stripe_mode" value="test" <?php checked( $active_mode, 'test' ); ?>>
						<span><?php esc_html_e( 'Sandbox / Test', 'cta-lms' ); ?></span>
					</label>
					<label class="cta-stripe-mode-option <?php echo 'live' === $active_mode ? 'is-active' : ''; ?>">
						<input type="radio" name="cta_stripe_mode" value="live" <?php checked( $active_mode, 'live' ); ?>>
						<span><?php esc_html_e( 'Live', 'cta-lms' ); ?></span>
					</label>
				</div>
				<p class="cta-stripe-mode-badge" data-mode-badge>
					<?php
					echo 'live' === $active_mode
						? esc_html__( 'Currently using Live credentials for all payments.', 'cta-lms' )
						: esc_html__( 'Currently using Sandbox / Test credentials for all payments.', 'cta-lms' );
					?>
				</p>
			</div>

			<div class="cta-stripe-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Stripe environments', 'cta-lms' ); ?>">
				<button type="button" class="cta-stripe-tab cta-stripe-tab--active" data-stripe-tab="test" role="tab" aria-selected="true">
					<?php esc_html_e( 'Sandbox / Test', 'cta-lms' ); ?>
				</button>
				<button type="button" class="cta-stripe-tab" data-stripe-tab="live" role="tab" aria-selected="false">
					<?php esc_html_e( 'Live', 'cta-lms' ); ?>
				</button>
			</div>

			<section class="cta-stripe-panel" data-stripe-panel="test" role="tabpanel">
				<table class="form-table">
					<tr>
						<th><label for="cta_stripe_test_publishable_key"><?php esc_html_e( 'Test Publishable Key', 'cta-lms' ); ?></label></th>
						<td>
							<input type="text" class="regular-text cta-stripe-pk" id="cta_stripe_test_publishable_key" name="cta_stripe_test_publishable_key" value="<?php echo esc_attr( $test_creds['publishable_key'] ); ?>" placeholder="pk_test_..." autocomplete="off" data-stripe-env="test">
							<p class="description"><?php esc_html_e( 'Starts with pk_test_ — safe to expose client-side.', 'cta-lms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="cta_stripe_test_secret_key"><?php esc_html_e( 'Test Secret Key', 'cta-lms' ); ?></label></th>
						<td>
							<div class="cta-secret-field">
								<input type="password" class="regular-text cta-stripe-sk" id="cta_stripe_test_secret_key" name="cta_stripe_test_secret_key" value="<?php echo esc_attr( $test_creds['secret_key'] ); ?>" placeholder="sk_test_..." autocomplete="new-password" data-stripe-env="test">
								<button type="button" class="button cta-toggle-secret" aria-label="<?php esc_attr_e( 'Show or hide secret', 'cta-lms' ); ?>"><?php esc_html_e( 'Show', 'cta-lms' ); ?></button>
							</div>
							<p class="description"><?php esc_html_e( 'Starts with sk_test_ — never expose client-side.', 'cta-lms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="cta_stripe_test_webhook_secret"><?php esc_html_e( 'Webhook Signing Secret (Test)', 'cta-lms' ); ?></label></th>
						<td>
							<div class="cta-secret-field">
								<input type="password" class="regular-text" id="cta_stripe_test_webhook_secret" name="cta_stripe_test_webhook_secret" value="<?php echo esc_attr( $test_creds['webhook_secret'] ); ?>" placeholder="whsec_..." autocomplete="new-password">
								<button type="button" class="button cta-toggle-secret" aria-label="<?php esc_attr_e( 'Show or hide secret', 'cta-lms' ); ?>"><?php esc_html_e( 'Show', 'cta-lms' ); ?></button>
							</div>
							<p class="description"><?php esc_html_e( 'Starts with whsec_ — from the Test webhook endpoint in Stripe Dashboard.', 'cta-lms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Webhook URL (Test)', 'cta-lms' ); ?></th>
						<td>
							<div class="cta-webhook-url-row">
								<input type="text" class="large-text" id="cta_stripe_test_webhook_url" readonly value="<?php echo esc_attr( $webhook_test ); ?>">
								<button type="button" class="button cta-copy-shortcode" data-shortcode="<?php echo esc_attr( $webhook_test ); ?>"><?php esc_html_e( 'Copy', 'cta-lms' ); ?></button>
							</div>
							<p class="description"><?php esc_html_e( 'Paste this into Stripe Dashboard → Developers → Webhooks (Test mode).', 'cta-lms' ); ?></p>
						</td>
					</tr>
				</table>
				<p>
					<button type="button" class="button cta-test-stripe-btn" data-stripe-env="test" disabled><?php esc_html_e( 'Test Connection', 'cta-lms' ); ?></button>
					<span class="cta-inline-result cta-stripe-test-result" data-stripe-env="test" role="status"></span>
				</p>
			</section>

			<section class="cta-stripe-panel" data-stripe-panel="live" role="tabpanel" hidden>
				<table class="form-table">
					<tr>
						<th><label for="cta_stripe_live_publishable_key"><?php esc_html_e( 'Live Publishable Key', 'cta-lms' ); ?></label></th>
						<td>
							<input type="text" class="regular-text cta-stripe-pk" id="cta_stripe_live_publishable_key" name="cta_stripe_live_publishable_key" value="<?php echo esc_attr( $live_creds['publishable_key'] ); ?>" placeholder="pk_live_..." autocomplete="off" data-stripe-env="live">
							<p class="description"><?php esc_html_e( 'Starts with pk_live_ — safe to expose client-side.', 'cta-lms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="cta_stripe_live_secret_key"><?php esc_html_e( 'Live Secret Key', 'cta-lms' ); ?></label></th>
						<td>
							<div class="cta-secret-field">
								<input type="password" class="regular-text cta-stripe-sk" id="cta_stripe_live_secret_key" name="cta_stripe_live_secret_key" value="<?php echo esc_attr( $live_creds['secret_key'] ); ?>" placeholder="sk_live_..." autocomplete="new-password" data-stripe-env="live">
								<button type="button" class="button cta-toggle-secret" aria-label="<?php esc_attr_e( 'Show or hide secret', 'cta-lms' ); ?>"><?php esc_html_e( 'Show', 'cta-lms' ); ?></button>
							</div>
							<p class="description"><?php esc_html_e( 'Starts with sk_live_ — never expose client-side.', 'cta-lms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="cta_stripe_live_webhook_secret"><?php esc_html_e( 'Webhook Signing Secret (Live)', 'cta-lms' ); ?></label></th>
						<td>
							<div class="cta-secret-field">
								<input type="password" class="regular-text" id="cta_stripe_live_webhook_secret" name="cta_stripe_live_webhook_secret" value="<?php echo esc_attr( $live_creds['webhook_secret'] ); ?>" placeholder="whsec_..." autocomplete="new-password">
								<button type="button" class="button cta-toggle-secret" aria-label="<?php esc_attr_e( 'Show or hide secret', 'cta-lms' ); ?>"><?php esc_html_e( 'Show', 'cta-lms' ); ?></button>
							</div>
							<p class="description"><?php esc_html_e( 'Starts with whsec_ — from the Live webhook endpoint in Stripe Dashboard.', 'cta-lms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Webhook URL (Live)', 'cta-lms' ); ?></th>
						<td>
							<div class="cta-webhook-url-row">
								<input type="text" class="large-text" id="cta_stripe_live_webhook_url" readonly value="<?php echo esc_attr( $webhook_live ); ?>">
								<button type="button" class="button cta-copy-shortcode" data-shortcode="<?php echo esc_attr( $webhook_live ); ?>"><?php esc_html_e( 'Copy', 'cta-lms' ); ?></button>
							</div>
							<p class="description"><?php esc_html_e( 'Paste this into Stripe Dashboard → Developers → Webhooks (Live mode).', 'cta-lms' ); ?></p>
						</td>
					</tr>
				</table>
				<p>
					<button type="button" class="button cta-test-stripe-btn" data-stripe-env="live" disabled><?php esc_html_e( 'Test Connection', 'cta-lms' ); ?></button>
					<span class="cta-inline-result cta-stripe-test-result" data-stripe-env="live" role="status"></span>
				</p>
			</section>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Customer Billing Portal', 'cta-lms' ); ?></th>
					<td>
						<p class="description" style="margin-top:0;">
							<?php esc_html_e( "Students use Manage Subscription to open Stripe's Customer Portal. The portal configuration is created automatically for the Active Mode account on first use.", 'cta-lms' ); ?>
						</p>
						<?php if ( $portal_config ) : ?>
							<p><code><?php echo esc_html( $portal_config ); ?></code></p>
						<?php endif; ?>
						<p>
							<button type="button" class="button" id="cta-ensure-portal"><?php esc_html_e( 'Ensure Portal Configuration', 'cta-lms' ); ?></button>
							<span id="cta-portal-test-result" class="cta-inline-result"></span>
						</p>
						<p class="description">
							<?php
							printf(
								/* translators: 1: event list, 2: Stripe dashboard URL */
								esc_html__( 'Register these webhook events: %1$s. Portal dashboard: %2$s', 'cta-lms' ),
								esc_html( $required_events ),
								'https://dashboard.stripe.com/' . ( 'live' === $active_mode ? '' : 'test/' ) . 'settings/billing/portal'
							);
							?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<div class="cta-admin-panel">
			<h2><?php esc_html_e( 'Page Assignments', 'cta-lms' ); ?></h2>
			<table class="form-table">
				<?php foreach ( $page_options as $option_key => $label ) : ?>
					<tr>
						<th><label for="<?php echo esc_attr( $option_key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<select id="<?php echo esc_attr( $option_key ); ?>" name="<?php echo esc_attr( $option_key ); ?>">
								<option value="0"><?php esc_html_e( '— Select Page —', 'cta-lms' ); ?></option>
								<?php foreach ( $pages as $page ) : ?>
									<option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( get_option( $option_key, 0 ), $page->ID ); ?>><?php echo esc_html( $page->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>

		<div class="cta-admin-panel">
			<h2><?php esc_html_e( 'CTA Configuration', 'cta-lms' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="cta_timezone"><?php esc_html_e( 'Display Timezone', 'cta-lms' ); ?></label></th>
					<td>
						<select id="cta_timezone" name="cta_timezone">
							<?php
							$current_tz = (string) get_option( 'cta_timezone', 'America/Los_Angeles' );
							$zones      = timezone_identifiers_list();
							foreach ( $zones as $zone ) :
								?>
								<option value="<?php echo esc_attr( $zone ); ?>" <?php selected( $current_tz, $zone ); ?>><?php echo esc_html( $zone ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'All booking times, certificates, dashboards, emails, and admin timestamps display in this timezone. Default: America/Los_Angeles (Pacific Time — PST/PDT). Do not use Asia/Karachi or other server-local zones.', 'cta-lms' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="cta_camft_provider_number"><?php esc_html_e( 'CAMFT CEPA Provider Number', 'cta-lms' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="cta_camft_provider_number" name="cta_camft_provider_number" value="#122418" readonly>
						<p class="description"><?php esc_html_e( 'Official provider number used only on CE completion certificates.', 'cta-lms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="cta_admin_name"><?php esc_html_e( 'Program Administrator Name', 'cta-lms' ); ?></label></th>
					<td><input type="text" class="regular-text" id="cta_admin_name" name="cta_admin_name" value="<?php echo esc_attr( get_option( 'cta_admin_name', 'Candice Fuimaono, MS, LMFT' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="cta_support_email"><?php esc_html_e( 'Support Email', 'cta-lms' ); ?></label></th>
					<td><input type="email" class="regular-text" id="cta_support_email" name="cta_support_email" value="<?php echo esc_attr( get_option( 'cta_support_email', 'support@clinicaltrainingacademy.com' ) ); ?>"></td>
				</tr>
			</table>
		</div>

		<div class="cta-admin-panel">
			<h2><?php esc_html_e( 'Certificate Settings', 'cta-lms' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="cta_certificate_provider_address"><?php esc_html_e( 'Provider Mailing Address', 'cta-lms' ); ?></label></th>
					<td>
						<?php
						$default_provider_address = class_exists( 'CTA_Certificates' )
							? CTA_Certificates::get_default_provider_address()
							: "6296 Magnolia Ave #1077\nRiverside, CA 92506";
						$stored_provider_address = (string) get_option( 'cta_certificate_provider_address', '' );
						?>
						<textarea class="large-text" rows="3" id="cta_certificate_provider_address" name="cta_certificate_provider_address" placeholder="<?php echo esc_attr( $default_provider_address ); ?>"><?php echo esc_textarea( $stored_provider_address ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Printed on CE certificates only, below the provider name and CAMFT approval line. Use the business mailing address (street + city/state/ZIP). The organization name is already shown above this block.', 'cta-lms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="cta_certificate_header_text"><?php esc_html_e( 'Certificate Header Text', 'cta-lms' ); ?></label></th>
					<td><input type="text" class="regular-text" id="cta_certificate_header_text" name="cta_certificate_header_text" value="<?php echo esc_attr( get_option( 'cta_certificate_header_text', 'Certificate of Completion' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="cta_certificate_footer_text"><?php esc_html_e( 'Certificate Footer Text', 'cta-lms' ); ?></label></th>
					<td><input type="text" class="regular-text" id="cta_certificate_footer_text" name="cta_certificate_footer_text" value="<?php echo esc_attr( get_option( 'cta_certificate_footer_text', 'clinicaltrainingacademy.com' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="cta_certificate_signature_name"><?php esc_html_e( 'Administrator Signature Name', 'cta-lms' ); ?></label></th>
					<td><input type="text" class="regular-text" id="cta_certificate_signature_name" name="cta_certificate_signature_name" value="<?php echo esc_attr( get_option( 'cta_certificate_signature_name', 'Candice Fuimaono, MS, LMFT' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="cta_certificate_signature_image_url"><?php esc_html_e( 'Administrator Signature Image', 'cta-lms' ); ?></label></th>
					<td>
						<?php
						$sig_img_url = (string) get_option( 'cta_certificate_signature_image_url', '' );
						$bundled_sig = '';
						if ( class_exists( 'CTA_Certificates' ) ) {
							foreach ( CTA_Certificates::get_bundled_signature_paths() as $sig_path ) {
								if ( is_readable( $sig_path ) ) {
									$bundled_sig = CTA_PLUGIN_URL . 'assets/img/' . basename( $sig_path );
									break;
								}
							}
						}
						$preview_src = $sig_img_url ? $sig_img_url : $bundled_sig;
						?>
						<input type="url" class="regular-text" id="cta_certificate_signature_image_url" name="cta_certificate_signature_image_url" value="<?php echo esc_attr( $sig_img_url ); ?>" placeholder="https://…/signature.png">
						<p>
							<button type="button" class="button" id="cta-select-signature-image"><?php esc_html_e( 'Select from Media Library', 'cta-lms' ); ?></button>
							<button type="button" class="button" id="cta-clear-signature-image"><?php esc_html_e( 'Clear', 'cta-lms' ); ?></button>
						</p>
						<?php if ( $preview_src ) : ?>
							<p><img src="<?php echo esc_url( $preview_src ); ?>" alt="" style="max-width:220px;max-height:72px;height:auto;border:1px solid #d0d5dd;padding:6px;background:#fff;"></p>
						<?php endif; ?>
						<p class="description">
							<?php esc_html_e( 'Appears above the typed name on every CE certificate. Prefer a transparent PNG. Bundled fallback path: assets/img/certificate-signature.png', 'cta-lms' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button" id="cta-preview-certificate"><?php esc_html_e( 'Preview Certificate', 'cta-lms' ); ?></button>
			</p>
		</div>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'cta-lms' ); ?></button>
		</p>
	</form>
</div>
<script>
(function ($) {
	$(function () {
		var frame;
		$('#cta-select-signature-image').on('click', function (e) {
			e.preventDefault();
			if (frame) {
				frame.open();
				return;
			}
			frame = wp.media({
				title: '<?php echo esc_js( __( 'Select signature image', 'cta-lms' ) ); ?>',
				button: { text: '<?php echo esc_js( __( 'Use this signature', 'cta-lms' ) ); ?>' },
				library: { type: 'image' },
				multiple: false
			});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				if (attachment && attachment.url) {
					$('#cta_certificate_signature_image_url').val(attachment.url);
				}
			});
			frame.open();
		});
		$('#cta-clear-signature-image').on('click', function (e) {
			e.preventDefault();
			$('#cta_certificate_signature_image_url').val('');
		});
	});
})(jQuery);
</script>
