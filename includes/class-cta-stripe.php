<?php
/**
 * Stripe payment integration for courses and supervision subscriptions.
 *
 * @package CTA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CTA_Stripe
 */
if ( ! class_exists( 'CTA_Stripe' ) ) {

class CTA_Stripe {

	/**
	 * Stripe secret key.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Stripe publishable key.
	 *
	 * @var string
	 */
	private $publishable_key;

	/**
	 * Stripe webhook signing secret.
	 *
	 * @var string
	 */
	private $webhook_secret;

	/**
	 * Stripe mode (test|live).
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Whether legacy credential migration is in progress.
	 *
	 * @var bool
	 */
	private static $migrating_credentials = false;

	/**
	 * Normalize environment to test|live.
	 *
	 * @param string $env Environment label.
	 * @return string
	 */
	public static function normalize_env( $env ) {
		return ( 'live' === (string) $env ) ? 'live' : 'test';
	}

	/**
	 * Active payment mode (Sandbox/Test or Live).
	 *
	 * @return string test|live
	 */
	public static function get_mode() {
		return self::normalize_env( get_option( 'cta_stripe_mode', 'test' ) );
	}

	/**
	 * Option key prefix for an environment.
	 *
	 * @param string $env test|live
	 * @return string
	 */
	public static function credential_option_prefix( $env ) {
		return ( 'live' === self::normalize_env( $env ) ) ? 'cta_stripe_live_' : 'cta_stripe_test_';
	}

	/**
	 * Credential set for an environment (does not change Active Mode).
	 *
	 * @param string|null $env test|live; null = active mode.
	 * @return array{mode:string,publishable_key:string,secret_key:string,webhook_secret:string}
	 */
	public static function get_credentials( $env = null ) {
		// Ensure legacy single-key installs are mapped before any read.
		if ( ! self::$migrating_credentials && '1' !== (string) get_option( 'cta_stripe_dual_creds_migrated', '' ) ) {
			self::migrate_legacy_credentials();
		}

		$env    = self::normalize_env( null === $env ? self::get_mode() : $env );
		$prefix = self::credential_option_prefix( $env );

		return array(
			'mode'            => $env,
			'publishable_key' => (string) get_option( $prefix . 'publishable_key', '' ),
			'secret_key'      => (string) get_option( $prefix . 'secret_key', '' ),
			'webhook_secret'  => (string) get_option( $prefix . 'webhook_secret', '' ),
		);
	}

	/**
	 * Credentials for the Active Mode switch.
	 *
	 * @return array{mode:string,publishable_key:string,secret_key:string,webhook_secret:string}
	 */
	public static function get_active_credentials() {
		return self::get_credentials( self::get_mode() );
	}

	/**
	 * Auto-generated webhook endpoint URL for an environment.
	 *
	 * @param string $env test|live
	 * @return string
	 */
	public static function get_webhook_url( $env ) {
		$env = self::normalize_env( $env );
		return add_query_arg( 'env', $env, rest_url( 'cta-lms/v1/stripe-webhook' ) );
	}

	/**
	 * Portal configuration option key for the given mode.
	 *
	 * @param string|null $env test|live
	 * @return string
	 */
	public static function portal_config_option_key( $env = null ) {
		$env = self::normalize_env( null === $env ? self::get_mode() : $env );
		return ( 'live' === $env ) ? 'cta_stripe_live_portal_configuration_id' : 'cta_stripe_test_portal_configuration_id';
	}

	/**
	 * One-time migration from legacy single-key options into test/live sets.
	 *
	 * @return void
	 */
	public static function migrate_legacy_credentials() {
		if ( self::$migrating_credentials ) {
			return;
		}

		if ( '1' === (string) get_option( 'cta_stripe_dual_creds_migrated', '' ) ) {
			return;
		}

		self::$migrating_credentials = true;

		$legacy_secret = (string) get_option( 'cta_stripe_secret_key', '' );
		$legacy_pub    = (string) get_option( 'cta_stripe_publishable_key', '' );
		$legacy_whsec  = (string) get_option( 'cta_stripe_webhook_secret', '' );
		$legacy_portal = (string) get_option( 'cta_stripe_portal_configuration_id', '' );
		$mode          = self::get_mode();

		$target = 'test';
		if ( 0 === strpos( $legacy_secret, 'sk_live_' ) || 0 === strpos( $legacy_pub, 'pk_live_' ) ) {
			$target = 'live';
		} elseif ( 0 === strpos( $legacy_secret, 'sk_test_' ) || 0 === strpos( $legacy_pub, 'pk_test_' ) ) {
			$target = 'test';
		} else {
			$target = $mode;
		}

		$prefix = self::credential_option_prefix( $target );

		if ( $legacy_secret && '' === (string) get_option( $prefix . 'secret_key', '' ) ) {
			update_option( $prefix . 'secret_key', $legacy_secret, false );
		}
		if ( $legacy_pub && '' === (string) get_option( $prefix . 'publishable_key', '' ) ) {
			update_option( $prefix . 'publishable_key', $legacy_pub, false );
		}
		if ( $legacy_whsec && '' === (string) get_option( $prefix . 'webhook_secret', '' ) ) {
			update_option( $prefix . 'webhook_secret', $legacy_whsec, false );
		}

		$portal_key = self::portal_config_option_key( $target );
		if ( $legacy_portal && '' === (string) get_option( $portal_key, '' ) ) {
			update_option( $portal_key, $legacy_portal, false );
		}

		update_option( 'cta_stripe_dual_creds_migrated', '1', false );
		self::$migrating_credentials = false;

		// Keep legacy options mirrored to Active Mode for any leftover readers.
		self::sync_legacy_mirror_options();
	}

	/**
	 * Mirror Active Mode credentials into legacy option names.
	 *
	 * @return void
	 */
	public static function sync_legacy_mirror_options() {
		$creds = self::get_active_credentials();
		update_option( 'cta_stripe_secret_key', $creds['secret_key'], false );
		update_option( 'cta_stripe_publishable_key', $creds['publishable_key'], false );
		update_option( 'cta_stripe_webhook_secret', $creds['webhook_secret'], false );

		$portal = (string) get_option( self::portal_config_option_key(), '' );
		if ( $portal ) {
			update_option( 'cta_stripe_portal_configuration_id', $portal, false );
		}
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		self::migrate_legacy_credentials();

		$creds                 = self::get_active_credentials();
		$this->secret_key      = $creds['secret_key'];
		$this->publishable_key = $creds['publishable_key'];
		$this->webhook_secret  = $creds['webhook_secret'];
		$this->mode            = $creds['mode'];

		if ( ! empty( $this->secret_key ) && class_exists( '\Stripe\Stripe' ) ) {
			\Stripe\Stripe::setApiKey( $this->secret_key );
		}

		add_action( 'wp_ajax_cta_create_checkout', array( $this, 'create_checkout_session' ) );
		add_action( 'wp_ajax_nopriv_cta_create_checkout', array( $this, 'create_checkout_session' ) );

		add_action( 'wp_ajax_cta_create_subscription', array( $this, 'create_subscription_session' ) );
		add_action( 'wp_ajax_nopriv_cta_create_subscription', array( $this, 'create_subscription_session' ) );
		add_action( 'wp_ajax_cta_create_individual_session_checkout', array( $this, 'create_individual_session_checkout' ) );
		add_action( 'wp_ajax_nopriv_cta_create_individual_session_checkout', array( $this, 'create_individual_session_checkout' ) );

		add_action( 'rest_api_init', array( $this, 'register_webhook_route' ) );
	}

	/**
	 * Whether Stripe secret key is configured for Active Mode.
	 *
	 * @return bool
	 */
	private function is_stripe_configured() {
		$creds = self::get_active_credentials();
		return ! empty( $creds['secret_key'] );
	}

	/**
	 * Whether Stripe keys are configured for Active Mode.
	 *
	 * @return bool
	 */
	public function is_configured() {
		$creds = self::get_active_credentials();
		return ! empty( $creds['secret_key'] ) && ! empty( $creds['publishable_key'] ) && class_exists( '\Stripe\Stripe' );
	}

	/**
	 * Reload Active Mode credentials onto this instance and the Stripe SDK.
	 *
	 * @return bool True when a secret key is available.
	 */
	public function refresh_active_credentials() {
		$creds                 = self::get_active_credentials();
		$this->secret_key      = $creds['secret_key'];
		$this->publishable_key = $creds['publishable_key'];
		$this->webhook_secret  = $creds['webhook_secret'];
		$this->mode            = $creds['mode'];

		if ( ! empty( $this->secret_key ) && class_exists( '\Stripe\Stripe' ) ) {
			\Stripe\Stripe::setApiKey( $this->secret_key );
			return true;
		}

		return false;
	}

	/**
	 * Whether admin payment bypass is enabled.
	 *
	 * Permanently disabled — paid checkout must always use real Stripe.
	 *
	 * @return bool
	 */
	public static function is_payments_bypass_enabled() {
		return false;
	}

	/**
	 * @deprecated Bypass logging retained for compatibility; no-op while bypass is disabled.
	 *
	 * @param string              $context Context key.
	 * @param array<string,mixed> $meta    Meta.
	 * @return void
	 */
	public static function log_payment_bypass( $context, $meta = array() ) {
		unset( $context, $meta );
	}

	/**
	 * Resolve Stripe customer ID for a WordPress user (meta, then payments table).
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public function resolve_stripe_customer_id( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return '';
		}

		$customer_id = (string) get_user_meta( $user_id, 'cta_stripe_customer_id', true );

		if ( $customer_id ) {
			return $customer_id;
		}

		global $wpdb;

		$customer_id = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT stripe_customer_id FROM {$wpdb->prefix}cta_payments
				WHERE user_id = %d
				AND stripe_customer_id IS NOT NULL
				AND stripe_customer_id != ''
				ORDER BY created_at DESC
				LIMIT 1",
				$user_id
			)
		);

		if ( $customer_id ) {
			update_user_meta( $user_id, 'cta_stripe_customer_id', $customer_id );
		}

		return $customer_id;
	}

	/**
	 * Feature flags for the Stripe Customer Billing Portal.
	 *
	 * Enables: invoice history, payment method update, cancel at period end
	 * (student keeps access until paid period ends; portal also offers reactivate
	 * while cancel_at_period_end is pending).
	 *
	 * @return array<string,mixed>
	 */
	private function get_billing_portal_features() {
		return array(
			'customer_update'       => array(
				'enabled'         => true,
				'allowed_updates' => array( 'email', 'address', 'phone', 'tax_id' ),
			),
			'invoice_history'       => array(
				'enabled' => true,
			),
			'payment_method_update' => array(
				'enabled' => true,
			),
			'subscription_cancel'   => array(
				'enabled'             => true,
				'mode'                => 'at_period_end',
				'proration_behavior'  => 'none',
				'cancellation_reason' => array(
					'enabled' => true,
					'options' => array(
						'too_expensive',
						'missing_features',
						'switched_service',
						'unused',
						'other',
					),
				),
			),
			'subscription_pause'    => array(
				'enabled' => false,
			),
			'subscription_update'   => array(
				'enabled' => false,
			),
		);
	}

	/**
	 * Ensure a Stripe Customer Portal configuration exists with self-service features.
	 *
	 * Creates or updates a dedicated CTA portal configuration (not a random
	 * Dashboard default that may lack cancel / invoice features).
	 *
	 * @return string|WP_Error Configuration ID or error.
	 */
	public function ensure_billing_portal_configuration() {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'stripe_not_configured',
				__( 'Stripe is not configured.', 'cta-lms' )
			);
		}

		if ( ! class_exists( '\Stripe\BillingPortal\Configuration' ) ) {
			return '';
		}

		$features   = $this->get_billing_portal_features();
		$portal_key = self::portal_config_option_key();
		$existing   = (string) get_option( $portal_key, '' );
		if ( '' === $existing ) {
			$existing = (string) get_option( 'cta_stripe_portal_configuration_id', '' );
		}

		if ( $existing ) {
			try {
				$config = \Stripe\BillingPortal\Configuration::update(
					$existing,
					array(
						'business_profile' => array(
							'headline' => __( 'Manage your CTA subscription', 'cta-lms' ),
						),
						'features'         => $features,
					)
				);

				if ( ! empty( $config->id ) ) {
					update_option( $portal_key, (string) $config->id, false );
					update_option( 'cta_stripe_portal_configuration_id', (string) $config->id, false );
					return (string) $config->id;
				}
			} catch ( Exception $e ) {
				// Create a fresh configuration below.
			}
		}

		try {
			$config = \Stripe\BillingPortal\Configuration::create(
				array(
					'business_profile' => array(
						'headline' => __( 'Manage your CTA subscription', 'cta-lms' ),
					),
					'features'         => $features,
				)
			);

			if ( empty( $config->id ) ) {
				return new WP_Error(
					'portal_config_failed',
					__( 'Unable to create Stripe Customer Portal configuration.', 'cta-lms' )
				);
			}

			update_option( $portal_key, (string) $config->id, false );
			update_option( 'cta_stripe_portal_configuration_id', (string) $config->id, false );

			return (string) $config->id;
		} catch ( Exception $e ) {
			return new WP_Error(
				'portal_config_failed',
				sprintf(
					/* translators: %s: Stripe error message */
					__( 'Unable to configure Stripe Customer Portal: %s', 'cta-lms' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Create a Stripe Customer Billing Portal session URL for a user.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $return_url URL to return to after portal.
	 * @return string|WP_Error Portal URL or error.
	 */
	public function create_billing_portal_session( $user_id, $return_url = '' ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user.', 'cta-lms' ) );
		}

		if ( self::is_payments_bypass_enabled() ) {
			return new WP_Error(
				'payments_bypass',
				__( 'Stripe billing portal is unavailable while payment bypass mode is enabled.', 'cta-lms' )
			);
		}

		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'stripe_not_configured',
				__( 'Stripe is not configured. Add your Stripe API keys in CTA LMS settings.', 'cta-lms' )
			);
		}

		if ( ! class_exists( '\Stripe\BillingPortal\Session' ) ) {
			return new WP_Error(
				'stripe_sdk',
				__( 'Stripe SDK is missing Billing Portal support. Run composer install.', 'cta-lms' )
			);
		}

		$customer_id = $this->resolve_stripe_customer_id( $user_id );

		if ( ! $customer_id ) {
			return new WP_Error(
				'no_customer',
				__( 'No Stripe customer is linked to this account yet. Complete a subscription purchase first.', 'cta-lms' )
			);
		}

		if ( '' === $return_url ) {
			$return_url = home_url( '/' );
		}

		$params = array(
			'customer'   => $customer_id,
			'return_url' => esc_url_raw( $return_url ),
		);

		$config_id = $this->ensure_billing_portal_configuration();

		if ( is_wp_error( $config_id ) ) {
			return $config_id;
		}

		if ( is_string( $config_id ) && '' !== $config_id ) {
			$params['configuration'] = $config_id;
		}

		try {
			$session = \Stripe\BillingPortal\Session::create( $params );

			if ( empty( $session->url ) ) {
				return new WP_Error(
					'portal_failed',
					__( 'Stripe did not return a billing portal URL.', 'cta-lms' )
				);
			}

			return (string) $session->url;
		} catch ( Exception $e ) {
			return new WP_Error(
				'portal_failed',
				sprintf(
					/* translators: %s: Stripe error message */
					__( 'Unable to open billing portal: %s', 'cta-lms' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Resolve the Stripe subscription ID stored for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public function resolve_supervision_subscription_id( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return '';
		}

		$subscription_id = (string) get_user_meta( $user_id, 'cta_supervision_subscription_id', true );

		if ( $subscription_id && 0 !== strpos( $subscription_id, 'bypass-' ) ) {
			return $subscription_id;
		}

		$bundle_id = (string) get_user_meta( $user_id, 'cta_bundle_subscription_id', true );

		if ( $bundle_id && 0 !== strpos( $bundle_id, 'bypass-' ) ) {
			return $bundle_id;
		}

		return '';
	}

	/**
	 * Pull the latest Stripe subscription for a user and sync local meta.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	public function sync_user_subscription_from_stripe( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user.', 'cta-lms' ) );
		}

		if ( ! $this->is_configured() || ! class_exists( '\Stripe\Subscription' ) ) {
			return new WP_Error( 'stripe_not_configured', __( 'Stripe is not configured.', 'cta-lms' ) );
		}

		$subscription_id = $this->resolve_supervision_subscription_id( $user_id );

		if ( ! $subscription_id ) {
			return new WP_Error(
				'no_subscription',
				__( 'No Stripe subscription is linked to this account.', 'cta-lms' )
			);
		}

		try {
			$subscription = \Stripe\Subscription::retrieve( $subscription_id );
			$this->sync_subscription_status_from_stripe( $subscription );
			return true;
		} catch ( Exception $e ) {
			return new WP_Error(
				'sync_failed',
				sprintf(
					/* translators: %s: Stripe error message */
					__( 'Unable to sync subscription from Stripe: %s', 'cta-lms' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Admin: cancel a student's Stripe subscription.
	 *
	 * @param int    $user_id User ID.
	 * @param string $mode    at_period_end|immediately.
	 * @return true|WP_Error
	 */
	public function admin_cancel_subscription( $user_id, $mode = 'at_period_end' ) {
		$user_id = absint( $user_id );
		$mode    = ( 'immediately' === $mode ) ? 'immediately' : 'at_period_end';

		if ( ! $user_id ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user.', 'cta-lms' ) );
		}

		if ( ! $this->is_configured() || ! class_exists( '\Stripe\Subscription' ) ) {
			return new WP_Error( 'stripe_not_configured', __( 'Stripe is not configured.', 'cta-lms' ) );
		}

		$subscription_id = $this->resolve_supervision_subscription_id( $user_id );

		if ( ! $subscription_id ) {
			// Local-only cancellation (agency / bypass / already detached from Stripe).
			update_user_meta( $user_id, 'cta_supervision_status', 'cancelled' );
			update_user_meta( $user_id, 'cta_supervision_cancel_at_period_end', '0' );
			return true;
		}

		try {
			if ( 'immediately' === $mode ) {
				$subscription = \Stripe\Subscription::retrieve( $subscription_id );
				$subscription->cancel();
				$this->handle_subscription_cancelled( $subscription );
			} else {
				$subscription = \Stripe\Subscription::update(
					$subscription_id,
					array( 'cancel_at_period_end' => true )
				);
				$this->sync_subscription_status_from_stripe( $subscription );
			}

			return true;
		} catch ( Exception $e ) {
			return new WP_Error(
				'cancel_failed',
				sprintf(
					/* translators: %s: Stripe error message */
					__( 'Unable to cancel subscription in Stripe: %s', 'cta-lms' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Admin: clear cancel_at_period_end so renewal continues.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	public function admin_reactivate_subscription( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user.', 'cta-lms' ) );
		}

		if ( ! $this->is_configured() || ! class_exists( '\Stripe\Subscription' ) ) {
			return new WP_Error( 'stripe_not_configured', __( 'Stripe is not configured.', 'cta-lms' ) );
		}

		$subscription_id = $this->resolve_supervision_subscription_id( $user_id );

		if ( ! $subscription_id ) {
			return new WP_Error(
				'no_subscription',
				__( 'No Stripe subscription is linked to this account.', 'cta-lms' )
			);
		}

		try {
			$subscription = \Stripe\Subscription::update(
				$subscription_id,
				array( 'cancel_at_period_end' => false )
			);
			$this->sync_subscription_status_from_stripe( $subscription );
			return true;
		} catch ( Exception $e ) {
			return new WP_Error(
				'reactivate_failed',
				sprintf(
					/* translators: %s: Stripe error message */
					__( 'Unable to reactivate subscription in Stripe: %s', 'cta-lms' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Sync local subscription meta from a Stripe subscription object.
	 *
	 * Keeps access active through the paid period when cancel_at_period_end is set.
	 *
	 * @param object $subscription Stripe subscription.
	 */
	public function sync_subscription_status_from_stripe( $subscription ) {
		$subscription_id = sanitize_text_field( $subscription->id ?? '' );

		if ( ! $subscription_id ) {
			return;
		}

		$user_id = $this->get_user_id_by_subscription( $subscription_id );

		if ( ! $user_id ) {
			return;
		}

		$status             = sanitize_text_field( $subscription->status ?? '' );
		$cancel_at_period_end = ! empty( $subscription->cancel_at_period_end );
		$period_end         = ! empty( $subscription->current_period_end ) ? (int) $subscription->current_period_end : 0;

		if ( $period_end > 0 ) {
			update_user_meta( $user_id, 'cta_supervision_period_end', $period_end );
		}

		update_user_meta( $user_id, 'cta_supervision_cancel_at_period_end', $cancel_at_period_end ? '1' : '0' );

		if ( in_array( $status, array( 'active', 'trialing' ), true ) ) {
			$current_local = (string) get_user_meta( $user_id, 'cta_supervision_status', true );

			// Do not override Associate Pending Approval with Active from Stripe alone.
			if ( 'pending_approval' !== $current_local ) {
				update_user_meta( $user_id, 'cta_supervision_status', 'active' );
			}
			return;
		}

		if ( in_array( $status, array( 'past_due', 'unpaid' ), true ) ) {
			update_user_meta( $user_id, 'cta_supervision_status', 'past_due' );
			return;
		}

		if ( in_array( $status, array( 'canceled', 'cancelled', 'incomplete_expired' ), true ) ) {
			update_user_meta( $user_id, 'cta_supervision_status', 'cancelled' );
			update_user_meta( $user_id, 'cta_supervision_cancel_at_period_end', '0' );
		}
	}

	/**
	 * Get publishable key for frontend.
	 *
	 * @return string
	 */
	public function get_publishable_key() {
		return $this->publishable_key;
	}

	/**
	 * Get supervision monthly price from options (DB).
	 *
	 * @return float
	 */
	public function get_supervision_monthly_price() {
		return CTA_Supervision_Plans::get_group_price();
	}

	/**
	 * Monthly price for a supervision plan slug.
	 *
	 * @param string $plan Plan slug (group|hybrid).
	 * @return float
	 */
	public function get_supervision_plan_price( $plan = 'group' ) {
		return CTA_Supervision_Plans::get_price( $plan );
	}

	/**
	 * Ensure All-Access subscription bundles use the canonical name and price.
	 *
	 * @param object $bundle Bundle row.
	 * @return object
	 */
	private function normalize_supervision_bundle( $bundle ) {
		if ( ! $bundle || 'subscription' !== (string) ( $bundle->plan_type ?? '' ) ) {
			return $bundle;
		}

		$slug = (string) ( $bundle->slug ?? '' );
		$name = (string) ( $bundle->name ?? '' );

		if (
			! in_array( $slug, array( CTA_Supervision_Plans::ALL_ACCESS_BUNDLE_SLUG, CTA_Supervision_Plans::LEGACY_HYBRID_BUNDLE_SLUG ), true )
			&& false === stripos( $name, 'Hybrid' )
			&& false === stripos( $name, 'All-Access Program' )
			&& false === stripos( $name, 'Supervision + CE' )
		) {
			return $bundle;
		}

		$bundle->name        = CTA_Supervision_Plans::get_name( CTA_Supervision_Plans::HYBRID_SLUG );
		$bundle->price       = CTA_Supervision_Plans::get_price( CTA_Supervision_Plans::HYBRID_SLUG );
		$bundle->description = CTA_Supervision_Plans::get_plan( CTA_Supervision_Plans::HYBRID_SLUG )['description'];
		$bundle->slug        = CTA_Supervision_Plans::ALL_ACCESS_BUNDLE_SLUG;

		return $bundle;
	}

	/**
	 * Create one-time Stripe Checkout session for a course.
	 *
	 * CE / Exam Prep checkout is available to any active account, including
	 * Registered Associates whose supervision application is still pending.
	 */
	public function create_checkout_session() {
		check_ajax_referer( 'cta_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please log in to enroll in this course.', 'cta-lms' ),
				)
			);
		}

		$user_id = get_current_user_id();

		if ( class_exists( 'CTA_Roles' ) ) {
			CTA_Roles::ensure_learner_profile( $user_id );
		}

		if ( class_exists( 'CTA_Associate_Access' ) ) {
			CTA_Associate_Access::heal_decoupled_statuses( $user_id );
		}

		if (
			class_exists( 'CTA_Associate_Access' )
			&& ! CTA_Associate_Access::can_access_ce_and_exam_prep( $user_id )
		) {
			wp_send_json_error(
				array(
					'message' => __( 'Your account is inactive. Please contact support.', 'cta-lms' ),
					'code'    => 'account_inactive',
				)
			);
		}

		$course_id = absint( wp_unslash( $_POST['course_id'] ?? 0 ) );

		if ( ! $course_id ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid course selected.', 'cta-lms' ),
				)
			);
		}

		// Never silent-enroll paid courses. Resolve price first; Stripe only for paid.
		global $wpdb;

		$course = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cta_courses
				WHERE id = %d AND status = 'published'",
				$course_id
			)
		);

		if ( ! $course ) {
			wp_send_json_error(
				array(
					'message' => __( 'Course not found.', 'cta-lms' ),
				)
			);
		}

		$user_id = get_current_user_id();

		$already_has_access = false;
		if ( class_exists( 'CTA_Exam_Access' ) && CTA_Exam_Access::is_exam_prep( $course ) ) {
			$already_has_access = CTA_Exam_Access::has_active_access( $user_id, $course_id );
		} elseif ( class_exists( 'CTA_CE_Access' ) && CTA_CE_Access::is_ce_course( $course ) ) {
			$already_has_access = CTA_CE_Access::has_active_access( $user_id, $course_id );
		} else {
			$already_has_access = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}cta_enrollments
					WHERE user_id = %d AND course_id = %d AND status IN ('active','completed')",
					$user_id,
					$course_id
				)
			);
		}

		if ( $already_has_access ) {
			wp_send_json_error(
				array(
					'message' => __( 'You are already enrolled in this course.', 'cta-lms' ),
				)
			);
		}

		$price = $this->resolve_course_checkout_price( $course );

		if ( $price <= 0 ) {
			// Truly free courses only.
			$this->bypass_course_enrollment( $course_id, true );
			return;
		}

		// Keep the in-memory course price aligned with what Stripe will charge.
		$course->price = $price;

		$this->refresh_active_credentials();

		if ( ! $this->is_configured() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Stripe is not configured for the Active Mode. Add Sandbox or Live API keys in CTA LMS → Settings, set Active Mode, then try again.', 'cta-lms' ),
					'code'    => 'stripe_not_configured',
				)
			);
		}

		$course_page = function_exists( 'cta_lms_get_single_course_url' )
			? cta_lms_get_single_course_url( $course_id )
			: $this->get_page_url( 'cta_single_course_page_id' );
		if ( ! $course_page ) {
			$course_page = home_url( '/' );
		}

		$dashboard_page = $this->get_page_url( 'cta_student_dashboard_page_id' );
		$success_base   = $dashboard_page ? $dashboard_page : $course_page;

		$success_url = $this->build_checkout_success_url(
			$success_base,
			array(
				'course_id'    => $course_id,
				'payment'      => 'success',
				'cta_enrolled' => '1',
			)
		);

		$cancel_url = ( class_exists( 'CTA_Course_Routes' ) && CTA_Course_Routes::get_canonical_url( $course_id ) )
			? $course_page
			: add_query_arg( 'course_id', $course_id, $course_page );

		$is_exam_prep = class_exists( 'CTA_Exam_Access' ) && CTA_Exam_Access::is_exam_prep( $course );
		$ce_hours     = rtrim( rtrim( number_format( (float) $course->ce_hours, 1, '.', '' ), '0' ), '.' );

		if ( $is_exam_prep ) {
			$access_months = ! empty( $course->access_period_months ) ? (int) $course->access_period_months : 6;
			$line_description = sprintf(
				/* translators: %d: access period in months */
				__( 'Exam Preparation Program — %d months access. No CE credit.', 'cta-lms' ),
				$access_months
			);
			$course_meta = array();
			if ( ! empty( $course->syllabus_meta ) ) {
				$decoded_course_meta = json_decode( (string) $course->syllabus_meta, true );
				$course_meta         = is_array( $decoded_course_meta ) ? $decoded_course_meta : array();
			}
			if ( ! empty( $course_meta['checkout_description'] ) ) {
				$line_description = (string) $course_meta['checkout_description'];
			}
		} else {
			$line_description = sprintf(
				/* translators: %s: CE hours */
				__( '%s CE Hours — BBS Approved', 'cta-lms' ),
				$ce_hours
			);
		}

		try {
			$session = \Stripe\Checkout\Session::create(
				array(
					'payment_method_types' => array( 'card' ),
					'mode'                 => 'payment',
					'customer_email'       => wp_get_current_user()->user_email,
					'line_items'           => array(
						array(
							'price_data' => array(
								'currency'     => 'usd',
								'unit_amount'  => (int) round( (float) $course->price * 100 ),
								'product_data' => array(
									'name'        => function_exists( 'cta_lms_get_course_display_title' )
										? cta_lms_get_course_display_title( $course )
										: $course->title,
									'description' => $line_description,
								),
							),
							'quantity' => 1,
						),
					),
					'metadata'    => array(
						'user_id'      => (string) $user_id,
						'course_id'    => (string) $course_id,
						'product_type' => $is_exam_prep ? 'exam_prep' : 'course',
					),
					// Copy onto PaymentIntent/Charge so charge.refunded can match without Session lookup.
					'payment_intent_data' => array(
						'metadata' => array(
							'user_id'      => (string) $user_id,
							'course_id'    => (string) $course_id,
							'product_type' => $is_exam_prep ? 'exam_prep' : 'course',
						),
					),
					'success_url' => $success_url,
					'cancel_url'  => $cancel_url,
				)
			);

			$wpdb->insert(
				$wpdb->prefix . 'cta_payments',
				array(
					'user_id'           => $user_id,
					'stripe_payment_id' => $session->id,
					'amount'            => $course->price,
					'currency'          => 'usd',
					'payment_type'      => 'one_time',
					'product_type'      => 'course',
					'product_id'        => $course_id,
					'status'            => 'pending',
				),
				array( '%d', '%s', '%f', '%s', '%s', '%s', '%d', '%s' )
			);

			wp_send_json_success(
				array(
					'checkout_url' => $session->url,
				)
			);
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: Stripe error message */
						__( 'Payment error: %s', 'cta-lms' ),
						$e->getMessage()
					),
				)
			);
		}
	}

	/**
	 * Create Stripe Checkout session for supervision subscription.
	 */
	public function create_subscription_session() {
		check_ajax_referer( 'cta_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please log in to subscribe to supervision.', 'cta-lms' ),
				)
			);
		}

		CTA_Associate_Access::require_associate_for_purchase( get_current_user_id() );
		CTA_Associate_Access::require_agency_for_supervision_application( get_current_user_id() );

		$this->refresh_active_credentials();

		if ( ! $this->is_configured() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Stripe is not configured for the Active Mode. Add Sandbox or Live API keys in CTA LMS → Settings, set Active Mode, then try again.', 'cta-lms' ),
					'code'    => 'stripe_not_configured',
				)
			);
		}

		global $wpdb;

		$user_id = get_current_user_id();
		$price   = CTA_Supervision_Plans::get_group_price();

		if ( $price <= 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Supervision pricing is not configured.', 'cta-lms' ),
				)
			);
		}

		$active_sub = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}cta_payments
				WHERE user_id = %d
				AND product_type = 'supervision'
				AND payment_type = 'subscription'
				AND status = 'completed'",
				$user_id
			)
		);

		if ( $active_sub ) {
			wp_send_json_error(
				array(
					'message' => __( 'You already have an active supervision subscription.', 'cta-lms' ),
				)
			);
		}

		$supervision_page = $this->get_page_url( 'cta_supervision_dashboard_page_id' );
		if ( ! $supervision_page ) {
			$supervision_page = $this->get_page_url( 'cta_supervision_page_id' );
		}
		if ( ! $supervision_page ) {
			$supervision_page = home_url( '/' );
		}

		$group_plan   = CTA_Supervision_Plans::get_plan( CTA_Supervision_Plans::GROUP_SLUG );
		$product_name = (string) get_option( 'cta_supervision_product_name', '' );
		if ( '' === $product_name ) {
			$product_name = $group_plan['name'];
		}

		$product_desc = (string) get_option( 'cta_supervision_product_description', '' );
		if ( '' === $product_desc ) {
			$product_desc = $group_plan['description'];
		}

		$success_url = $this->build_checkout_success_url(
			$supervision_page,
			array(
				'subscription' => 'success',
			)
		);

		$cancel_url = add_query_arg( 'subscription', 'cancelled', $supervision_page );

		try {
			$session_args = array(
				'payment_method_types' => array( 'card' ),
				'mode'                 => 'subscription',
				'line_items'           => array(
					array(
						'price_data' => array(
							'currency'     => 'usd',
							'unit_amount'  => (int) round( $price * 100 ),
							'recurring'    => array(
								'interval' => 'month',
							),
							'product_data' => array(
								'name'        => $product_name,
								'description' => $product_desc,
							),
						),
						'quantity' => 1,
					),
				),
				'metadata'    => array(
					'user_id'      => (string) $user_id,
					'product_type' => 'supervision',
				),
				'success_url' => $success_url,
				'cancel_url'  => $cancel_url,
			);

			$existing_customer = $this->resolve_stripe_customer_id( $user_id );

			if ( $existing_customer ) {
				$session_args['customer'] = $existing_customer;
			} else {
				$session_args['customer_email'] = wp_get_current_user()->user_email;
			}

			$session = \Stripe\Checkout\Session::create( $session_args );

			$wpdb->insert(
				$wpdb->prefix . 'cta_payments',
				array(
					'user_id'           => $user_id,
					'stripe_payment_id' => $session->id,
					'amount'            => $price,
					'currency'          => 'usd',
					'payment_type'      => 'subscription',
					'product_type'      => 'supervision',
					'product_id'        => 0,
					'plan_name'         => $product_name,
					'plan_details'      => wp_json_encode(
						array(
							'plan_slug'    => 'group',
							'plan_name'    => $product_name,
							'billing'      => 'monthly',
							'price'        => $price,
							'currency'     => 'usd',
							'product_type' => 'supervision',
							'description'  => $product_desc,
						)
					),
					'status'            => 'pending',
				),
				array( '%d', '%s', '%f', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
			);

			wp_send_json_success(
				array(
					'checkout_url' => $session->url,
				)
			);
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: Stripe error message */
						__( 'Payment error: %s', 'cta-lms' ),
						$e->getMessage()
					),
				)
			);
		}
	}

	/**
	 * Create one-time Stripe Checkout for a single Individual 1-on-1 session ($120/session).
	 *
	 * Independent from Group Supervision subscription — grants one prepaid booking credit.
	 */
	public function create_individual_session_checkout() {
		check_ajax_referer( 'cta_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please log in to purchase an Individual 1-on-1 session.', 'cta-lms' ),
				)
			);
		}

		$user_id = get_current_user_id();
		CTA_Associate_Access::require_associate_for_purchase( $user_id );
		CTA_Associate_Access::require_agency_for_supervision_application( $user_id );

		$price = CTA_Supervision_Plans::get_individual_session_price();
		$name  = CTA_Supervision_Plans::get_individual_session_name();
		$desc  = __( 'One 60-minute Individual 1-on-1 supervision session. Pay per session; purchase additional sessions anytime.', 'cta-lms' );

		if ( $price <= 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Individual session pricing is not configured.', 'cta-lms' ),
				)
			);
		}

		$this->refresh_active_credentials();

		if ( ! $this->is_configured() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Stripe is not configured for the Active Mode. Add Sandbox or Live API keys in CTA LMS → Settings, set Active Mode, then try again.', 'cta-lms' ),
					'code'    => 'stripe_not_configured',
				)
			);
		}

		$supervision_page = $this->get_page_url( 'cta_supervision_dashboard_page_id' );
		if ( ! $supervision_page ) {
			$supervision_page = $this->get_page_url( 'cta_supervision_page_id' );
		}
		if ( ! $supervision_page ) {
			$supervision_page = home_url( '/' );
		}

		$success_url = $this->build_checkout_success_url(
			$supervision_page,
			array(
				'individual_session' => 'success',
			)
		);
		$cancel_url = add_query_arg( 'individual_session', 'cancelled', $supervision_page );

		try {
			$session_args = array(
				'payment_method_types' => array( 'card' ),
				'mode'                 => 'payment',
				'line_items'           => array(
					array(
						'price_data' => array(
							'currency'     => 'usd',
							'unit_amount'  => (int) round( $price * 100 ),
							'product_data' => array(
								'name'        => $name,
								'description' => $desc,
							),
						),
						'quantity'   => 1,
					),
				),
				'success_url'          => $success_url,
				'cancel_url'           => $cancel_url,
				'client_reference_id'  => (string) $user_id,
				'metadata'             => array(
					'user_id'      => (string) $user_id,
					'product_type' => CTA_Supervision_Plans::INDIVIDUAL_SESSION_PRODUCT,
					'plan_slug'    => 'individual',
					'billing'      => 'one_time',
					'price'        => (string) $price,
				),
			);

			$customer_id = (string) get_user_meta( $user_id, 'cta_stripe_customer_id', true );
			if ( $customer_id ) {
				$session_args['customer'] = $customer_id;
			} else {
				$user = get_userdata( $user_id );
				if ( $user && $user->user_email ) {
					$session_args['customer_email'] = $user->user_email;
				}
			}

			$session = \Stripe\Checkout\Session::create( $session_args );

			global $wpdb;
			$wpdb->insert(
				$wpdb->prefix . 'cta_payments',
				array(
					'user_id'           => $user_id,
					'stripe_payment_id' => $session->id,
					'amount'            => $price,
					'currency'          => 'usd',
					'payment_type'      => 'one_time',
					'product_type'      => CTA_Supervision_Plans::INDIVIDUAL_SESSION_PRODUCT,
					'product_id'        => 0,
					'plan_name'         => $name,
					'plan_details'      => wp_json_encode(
						array(
							'plan_slug'    => 'individual',
							'plan_name'    => $name,
							'billing'      => 'one_time',
							'price'        => $price,
							'currency'     => 'usd',
							'product_type' => CTA_Supervision_Plans::INDIVIDUAL_SESSION_PRODUCT,
							'credits'      => 1,
							'description'  => $desc,
						)
					),
					'status'            => 'pending',
				),
				array( '%d', '%s', '%f', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
			);

			wp_send_json_success(
				array(
					'checkout_url' => $session->url,
				)
			);
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: Stripe error message */
						__( 'Payment error: %s', 'cta-lms' ),
						$e->getMessage()
					),
				)
			);
		}
	}

	/**
	 * Bypass Stripe and grant one Individual 1-on-1 session credit (test mode).
	 */
	private function bypass_individual_session_purchase() {
		$user_id = get_current_user_id();
		CTA_Associate_Access::require_associate_for_purchase( $user_id );
		CTA_Associate_Access::require_agency_for_supervision_application( $user_id );

		$payment_id = 'bypass-indiv-' . time();
		$this->activate_individual_session_purchase(
			$user_id,
			array(
				'checkout_session_id' => $payment_id,
				'amount'              => 0,
				'send_receipt'        => false,
			)
		);

		$redirect = $this->get_page_url( 'cta_supervision_dashboard_page_id' );
		if ( ! $redirect ) {
			$redirect = $this->get_page_url( 'cta_supervision_page_id' );
		}
		if ( ! $redirect ) {
			$redirect = home_url( '/' );
		}

		$redirect = add_query_arg(
			array(
				'individual_session' => 'success',
				'cta_paid'           => '1',
				'_cta'               => (string) time(),
			),
			$redirect
		);

		clean_user_cache( $user_id );

		wp_send_json_success(
			array(
				'enrolled'     => true,
				'redirect_url' => $redirect,
				'message'      => __( 'Individual session credit added (payment bypass mode).', 'cta-lms' ),
			)
		);
	}

	/**
	 * Record Individual 1-on-1 payment and add one prepaid booking credit.
	 *
	 * Does not activate Group Supervision subscription access.
	 *
	 * @param int   $user_id User ID.
	 * @param array $args    Optional args.
	 * @return int Payment row ID.
	 */
	public function activate_individual_session_purchase( $user_id, $args = array() ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return 0;
		}

		$args = wp_parse_args(
			$args,
			array(
				'checkout_session_id' => '',
				'customer_id'         => '',
				'amount'              => CTA_Supervision_Plans::get_individual_session_price(),
				'send_receipt'        => false,
				'receipt_payment_id'  => '',
				'credits'             => 1,
			)
		);

		$name    = CTA_Supervision_Plans::get_individual_session_name();
		$amount  = (float) $args['amount'];
		$credits = max( 1, (int) $args['credits'] );
		$session_id = sanitize_text_field( $args['checkout_session_id'] );
		$customer_id = sanitize_text_field( $args['customer_id'] );
		$table   = $wpdb->prefix . 'cta_payments';
		$payment_id = 0;

		$details = wp_json_encode(
			array(
				'plan_slug'    => 'individual',
				'plan_name'    => $name,
				'billing'      => 'one_time',
				'price'        => $amount,
				'currency'     => 'usd',
				'product_type' => CTA_Supervision_Plans::INDIVIDUAL_SESSION_PRODUCT,
				'credits'      => $credits,
			)
		);

		if ( $session_id ) {
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE stripe_payment_id = %s LIMIT 1",
					$session_id
				)
			);

			if ( $existing ) {
				$wpdb->update(
					$table,
					array(
						'user_id'            => $user_id,
						'stripe_customer_id' => $customer_id ? $customer_id : null,
						'amount'             => $amount,
						'currency'           => 'usd',
						'payment_type'       => 'one_time',
						'product_type'       => CTA_Supervision_Plans::INDIVIDUAL_SESSION_PRODUCT,
						'plan_name'          => $name,
						'plan_details'       => $details,
						'status'             => 'completed',
					),
					array( 'id' => (int) $existing->id ),
					array( '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
				$payment_id = (int) $existing->id;
			}
		}

		if ( ! $payment_id ) {
			$wpdb->insert(
				$table,
				array(
					'user_id'            => $user_id,
					'stripe_payment_id'  => $session_id ? $session_id : ( 'indiv-' . time() ),
					'stripe_customer_id' => $customer_id ? $customer_id : null,
					'amount'             => $amount,
					'currency'           => 'usd',
					'payment_type'       => 'one_time',
					'product_type'       => CTA_Supervision_Plans::INDIVIDUAL_SESSION_PRODUCT,
					'product_id'         => 0,
					'plan_name'          => $name,
					'plan_details'       => $details,
					'status'             => 'completed',
				),
				array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
			);
			$payment_id = (int) $wpdb->insert_id;
		}

		// Idempotent: webhook + success redirect must not double-grant credits.
		$credit_guard     = $session_id ? ( 'cta_indiv_credit_' . md5( $session_id ) ) : '';
		$already_credited = (bool) ( $credit_guard && get_user_meta( $user_id, $credit_guard, true ) );

		if ( ! $already_credited && class_exists( 'CTA_Supervision' ) ) {
			CTA_Supervision::add_individual_session_credits( $user_id, $credits );
			if ( $credit_guard ) {
				update_user_meta( $user_id, $credit_guard, 1 );
			}
		}

		if ( $customer_id ) {
			update_user_meta( $user_id, 'cta_stripe_customer_id', $customer_id );
		}

		if ( class_exists( 'CTA_Associate_Access' ) ) {
			CTA_Associate_Access::ensure_account_active( $user_id );
		}

		if ( ! empty( $args['send_receipt'] ) && ! $already_credited ) {
			CTA_Emails::send(
				'payment_receipt',
				$user_id,
				array(
					'payment_id'   => sanitize_text_field( $args['receipt_payment_id'] ? $args['receipt_payment_id'] : $session_id ),
					'product_name' => $name . ' (' . CTA_Supervision_Plans::get_individual_session_price_label() . ')',
				)
			);
		}

		return $payment_id;
	}

	/**
	 * Register Stripe webhook REST route.
	 */
	public function register_webhook_route() {
		register_rest_route(
			'cta-lms/v1',
			'/stripe-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle incoming Stripe webhook events.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( $request ) {
		if ( ! class_exists( '\Stripe\Webhook' ) ) {
			return new WP_REST_Response(
				array( 'error' => 'Stripe SDK not loaded.' ),
				500
			);
		}

		$payload    = $request->get_body();
		$sig_header = $request->get_header( 'stripe-signature' );
		$env_param  = sanitize_text_field( (string) $request->get_param( 'env' ) );
		$verified   = $this->construct_webhook_event( $payload, $sig_header, $env_param );

		if ( is_wp_error( $verified ) ) {
			$code = (int) $verified->get_error_data();
			if ( $code < 400 ) {
				$code = 400;
			}
			return new WP_REST_Response( array( 'error' => $verified->get_error_message() ), $code );
		}

		$event = $verified['event'];
		$env   = $verified['env'];

		if ( empty( $event->type ) ) {
			return new WP_REST_Response( array( 'error' => 'Missing event type.' ), 400 );
		}

		$event_id = sanitize_text_field( (string) ( $event->id ?? '' ) );
		$is_dup   = ( $event_id && ! $this->claim_webhook_event( $event_id ) );

		// charge.refunded: allow Stripe Dashboard resend to re-run revoke logic.
		// Other event types stay idempotent (acknowledge duplicate, no re-process).
		if ( $is_dup && 'charge.refunded' !== (string) ( $event->type ?? '' ) ) {
			return new WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
		}

		$this->dispatch_webhook_event( $event, $env );

		return new WP_REST_Response(
			array(
				'received'  => true,
				'duplicate' => (bool) $is_dup,
			),
			200
		);
	}

	/**
	 * Verify webhook signature using the environment signing secret.
	 *
	 * @param string $payload    Raw body.
	 * @param string $sig_header Stripe-Signature header.
	 * @param string $env_param  Optional env query (test|live).
	 * @return array{event:object,env:string}|WP_Error
	 */
	private function construct_webhook_event( $payload, $sig_header, $env_param = '' ) {
		$candidates = array();
		if ( in_array( $env_param, array( 'test', 'live' ), true ) ) {
			$candidates[] = $env_param;
		} else {
			$candidates[] = self::get_mode();
			$other        = ( 'live' === self::get_mode() ) ? 'test' : 'live';
			$candidates[] = $other;
		}

		$last_error = null;

		foreach ( $candidates as $env ) {
			$creds  = self::get_credentials( $env );
			$secret = $creds['webhook_secret'];

			if ( '' === $secret ) {
				continue;
			}

			try {
				$event = \Stripe\Webhook::constructEvent( $payload, $sig_header, $secret );
				return array(
					'event' => $event,
					'env'   => $env,
				);
			} catch ( \UnexpectedValueException $e ) {
				return new WP_Error( 'invalid_payload', 'Invalid payload.', 400 );
			} catch ( \Stripe\Exception\SignatureVerificationException $e ) {
				$last_error = $e;
				continue;
			}
		}

		// No signing secret configured for any candidate — reject (do not process unverified).
		if ( null === $last_error ) {
			return new WP_Error( 'webhook_secret_missing', 'Webhook signing secret is not configured.', 400 );
		}

		return new WP_Error( 'invalid_signature', 'Invalid signature.', 400 );
	}

	/**
	 * Claim an event ID for idempotent processing. Returns false if already seen.
	 *
	 * @param string $event_id Stripe event ID.
	 * @return bool
	 */
	private function claim_webhook_event( $event_id ) {
		$event_id = sanitize_text_field( $event_id );
		if ( '' === $event_id ) {
			return true;
		}

		$key = 'cta_stripe_evt_' . md5( $event_id );
		if ( get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, 1, WEEK_IN_SECONDS );
		return true;
	}

	/**
	 * Cron/async entry reserved for future queue workers.
	 *
	 * @param string $event_id Stripe event ID.
	 * @param string $event_json Encoded event object.
	 * @return void
	 */
	public function process_webhook_event_async( $event_id, $event_json ) {
		$event = json_decode( (string) $event_json );
		if ( ! $event || empty( $event->type ) ) {
			return;
		}

		$this->dispatch_webhook_event( $event, self::get_mode() );
	}

	/**
	 * Route a verified Stripe event to the correct handler.
	 *
	 * @param object $event Stripe event.
	 * @param string $env   test|live.
	 * @return void
	 */
	private function dispatch_webhook_event( $event, $env = 'test' ) {
		$type   = (string) ( $event->type ?? '' );
		$object = isset( $event->data->object ) ? $event->data->object : null;

		if ( ! $object ) {
			return;
		}

		switch ( $type ) {
			case 'checkout.session.completed':
				$this->handle_checkout_completed( $object );
				break;

			case 'customer.created':
			case 'customer.updated':
				$this->handle_customer_upsert( $object );
				break;

			case 'customer.subscription.created':
			case 'customer.subscription.updated':
				$this->sync_subscription_status_from_stripe( $object );
				break;

			case 'customer.subscription.deleted':
				$this->handle_subscription_cancelled( $object );
				break;

			case 'customer.subscription.trial_will_end':
				$this->handle_subscription_trial_will_end( $object );
				break;

			case 'invoice.paid':
				$this->handle_subscription_invoice_paid( $object );
				break;

			case 'invoice.payment_failed':
				$this->handle_subscription_payment_failed( $object );
				break;

			case 'payment_intent.succeeded':
				$this->handle_payment_intent_succeeded( $object );
				break;

			case 'payment_intent.payment_failed':
				$this->handle_payment_intent_failed( $object );
				break;

			case 'charge.refunded':
				$this->handle_charge_refunded( $object, $env );
				break;
		}
	}

	/**
	 * Keep local Stripe Customer ID linked when Stripe customer events arrive.
	 *
	 * @param object $customer Stripe customer object.
	 * @return void
	 */
	private function handle_customer_upsert( $customer ) {
		$customer_id = sanitize_text_field( (string) ( $customer->id ?? '' ) );
		if ( '' === $customer_id ) {
			return;
		}

		$user_id = 0;
		if ( ! empty( $customer->metadata->user_id ) ) {
			$user_id = absint( $customer->metadata->user_id );
		}

		if ( ! $user_id && ! empty( $customer->email ) ) {
			$user = get_user_by( 'email', sanitize_email( (string) $customer->email ) );
			if ( $user ) {
				$user_id = (int) $user->ID;
			}
		}

		if ( $user_id ) {
			update_user_meta( $user_id, 'cta_stripe_customer_id', $customer_id );
		}
	}

	/**
	 * Trial ending soon — record reminder state; skip if already paid/active.
	 *
	 * @param object $subscription Stripe subscription.
	 * @return void
	 */
	private function handle_subscription_trial_will_end( $subscription ) {
		$status = sanitize_text_field( (string) ( $subscription->status ?? '' ) );
		if ( ! in_array( $status, array( 'trialing', 'incomplete' ), true ) ) {
			return;
		}

		$user_id = $this->get_user_id_by_subscription( sanitize_text_field( (string) ( $subscription->id ?? '' ) ) );
		if ( ! $user_id ) {
			return;
		}

		update_user_meta( $user_id, 'cta_supervision_trial_ending_notice', gmdate( 'c' ) );
	}

	/**
	 * One-time PaymentIntent succeeded.
	 *
	 * @param object $intent Stripe PaymentIntent.
	 * @return void
	 */
	private function handle_payment_intent_succeeded( $intent ) {
		global $wpdb;

		$intent_id = sanitize_text_field( (string) ( $intent->id ?? '' ) );
		if ( '' === $intent_id ) {
			return;
		}

		$wpdb->update(
			$wpdb->prefix . 'cta_payments',
			array( 'status' => 'completed' ),
			array( 'stripe_payment_id' => $intent_id ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * One-time PaymentIntent failed.
	 *
	 * @param object $intent Stripe PaymentIntent.
	 * @return void
	 */
	private function handle_payment_intent_failed( $intent ) {
		global $wpdb;

		$intent_id = sanitize_text_field( (string) ( $intent->id ?? '' ) );
		if ( '' === $intent_id ) {
			return;
		}

		$wpdb->update(
			$wpdb->prefix . 'cta_payments',
			array( 'status' => 'failed' ),
			array( 'stripe_payment_id' => $intent_id ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Normalize a Stripe ID that may be a string or expanded object.
	 *
	 * @param mixed $value Stripe ID string or object with ->id.
	 * @return string
	 */
	private function stripe_id( $value ) {
		if ( is_string( $value ) || is_numeric( $value ) ) {
			return sanitize_text_field( (string) $value );
		}
		if ( is_object( $value ) && isset( $value->id ) ) {
			return sanitize_text_field( (string) $value->id );
		}
		if ( is_array( $value ) && ! empty( $value['id'] ) ) {
			return sanitize_text_field( (string) $value['id'] );
		}
		return '';
	}

	/**
	 * Charge refunded — mark matching payment refunded; full refunds revoke course access.
	 *
	 * Primary path: PaymentIntent metadata (user_id / course_id) → same
	 * revoke_course_access_from_payment() used by the admin "Revoke access" tool.
	 * Fallback: existing Checkout Session (cs_…) lookup.
	 *
	 * @param object $charge Stripe charge object.
	 * @param string $env    Webhook environment (test|live).
	 * @return void
	 */
	private function handle_charge_refunded( $charge, $env = 'test' ) {
		$amount          = isset( $charge->amount ) ? (int) $charge->amount : 0;
		$amount_refunded = isset( $charge->amount_refunded ) ? (int) $charge->amount_refunded : 0;
		$charge_id       = $this->stripe_id( $charge->id ?? '' );
		$pi_id           = $this->stripe_id( $charge->payment_intent ?? '' );
		$customer_id     = $this->stripe_id( $charge->customer ?? '' );
		$env             = self::normalize_env( $env );

		if ( class_exists( 'CTA_Roles' ) ) {
			CTA_Roles::log_enrollment_issue(
				'charge_refunded_received',
				__( 'charge.refunded webhook handler entered.', 'cta-lms' ),
				array(
					'charge_id'       => $charge_id,
					'payment_intent'  => $pi_id,
					'customer'        => $customer_id,
					'amount'          => $amount,
					'amount_refunded' => $amount_refunded,
					'env'             => $env,
				)
			);
		}

		// Full refund only — partial refunds must not change access.
		if ( $amount <= 0 || $amount_refunded < $amount ) {
			if ( class_exists( 'CTA_Roles' ) ) {
				CTA_Roles::log_enrollment_issue(
					'charge_refunded_partial_ignored',
					__( 'Partial refund received; course access left unchanged.', 'cta-lms' ),
					array(
						'charge_id'       => $charge_id,
						'amount'          => $amount,
						'amount_refunded' => $amount_refunded,
					)
				);
			}
			return;
		}

		// --- Primary: PaymentIntent metadata → same admin revoke helper ---
		$pi_meta = $this->get_payment_intent_refund_metadata( $charge, $env );
		$user_id = absint( $pi_meta['user_id'] ?? 0 );
		$course_id = absint( $pi_meta['course_id'] ?? 0 );

		if ( $user_id && $course_id ) {
			$payment_ref = $this->find_payment_ref_for_user_course( $user_id, $course_id, $pi_id );
			if ( $payment_ref ) {
				$result = $this->revoke_course_access_from_payment( $payment_ref );
				if ( ! is_wp_error( $result ) ) {
					$this->log_charge_refunded_revoke_result(
						'charge_refunded_access_revoked_via_metadata',
						__( 'Full refund: access revoked via PaymentIntent metadata.', 'cta-lms' ),
						array(
							'charge_id'      => $charge_id,
							'payment_intent' => $pi_id,
							'payment_ref'    => $payment_ref,
							'user_id'        => $user_id,
							'course_id'      => $course_id,
							'env'            => $env,
						)
					);
					return;
				}
			}
		}

		// --- Fallback: Checkout Session (cs_…) lookup (kept as backup) ---
		$session_id = $this->resolve_checkout_session_id_for_charge( $charge, $env );
		$payments   = $this->find_payments_for_refunded_charge( $charge, $env, $session_id );

		$payment_refs = array();
		if ( $session_id ) {
			$payment_refs[] = $session_id;
		}
		foreach ( (array) $payments as $payment ) {
			$ref = sanitize_text_field( (string) ( $payment->stripe_payment_id ?? '' ) );
			if ( $ref && ! in_array( $ref, $payment_refs, true ) ) {
				$payment_refs[] = $ref;
			}
		}

		$did_revoke = false;
		foreach ( $payment_refs as $payment_ref ) {
			$result = $this->revoke_course_access_from_payment( $payment_ref );
			if ( is_wp_error( $result ) ) {
				continue;
			}
			$did_revoke = true;
			$row_user   = 0;
			$row_course = 0;
			foreach ( (array) $payments as $payment ) {
				if ( (string) ( $payment->stripe_payment_id ?? '' ) === $payment_ref ) {
					$row_user   = absint( $payment->user_id ?? 0 );
					$row_course = absint( $payment->product_id ?? 0 );
					break;
				}
			}
			$this->log_charge_refunded_revoke_result(
				'charge_refunded_access_revoked_via_session_lookup',
				__( 'Full refund: access revoked via Checkout Session lookup fallback.', 'cta-lms' ),
				array(
					'charge_id'      => $charge_id,
					'payment_intent' => $pi_id,
					'session_id'     => $session_id,
					'payment_ref'    => $payment_ref,
					'user_id'        => $row_user,
					'course_id'      => $row_course,
					'env'            => $env,
				)
			);
		}

		if ( $did_revoke ) {
			return;
		}

		if ( class_exists( 'CTA_Roles' ) ) {
			CTA_Roles::log_enrollment_issue(
				'charge_refunded_payment_not_found',
				__( 'Full refund received but PaymentIntent metadata and Session lookup both failed to revoke access.', 'cta-lms' ),
				array(
					'charge_id'       => $charge_id,
					'payment_intent'  => $pi_id,
					'customer'        => $customer_id,
					'session_id'      => $session_id,
					'pi_user_id'      => $user_id,
					'pi_course_id'    => $course_id,
					'amount'          => $amount,
					'amount_refunded' => $amount_refunded,
					'env'             => $env,
				)
			);
		}
	}

	/**
	 * Read user_id / course_id from PaymentIntent metadata (primary refund path).
	 *
	 * @param object $charge Stripe charge.
	 * @param string $env    test|live.
	 * @return array{user_id:int,course_id:int}
	 */
	private function get_payment_intent_refund_metadata( $charge, $env ) {
		$user_id   = 0;
		$course_id = 0;

		$pi_field = $charge->payment_intent ?? null;

		// Already-expanded PaymentIntent on the charge object.
		if ( is_object( $pi_field ) && isset( $pi_field->metadata ) ) {
			$meta = is_object( $pi_field->metadata ) && method_exists( $pi_field->metadata, 'toArray' )
				? $pi_field->metadata->toArray()
				: (array) $pi_field->metadata;
			$user_id   = absint( $meta['user_id'] ?? 0 );
			$course_id = absint( $meta['course_id'] ?? 0 );
			if ( $user_id && $course_id ) {
				return array(
					'user_id'   => $user_id,
					'course_id' => $course_id,
				);
			}
		}

		$pi_id = $this->stripe_id( $pi_field );
		if ( '' === $pi_id || ! $this->set_stripe_api_for_env( $env ) ) {
			return array(
				'user_id'   => $user_id,
				'course_id' => $course_id,
			);
		}

		try {
			$intent = \Stripe\PaymentIntent::retrieve( $pi_id );
			if ( $intent && ! empty( $intent->metadata ) ) {
				$meta = is_object( $intent->metadata ) && method_exists( $intent->metadata, 'toArray' )
					? $intent->metadata->toArray()
					: (array) $intent->metadata;
				$user_id   = absint( $meta['user_id'] ?? 0 );
				$course_id = absint( $meta['course_id'] ?? 0 );
			}
		} catch ( \Exception $e ) {
			if ( class_exists( 'CTA_Roles' ) ) {
				CTA_Roles::log_enrollment_issue(
					'charge_refunded_payment_intent_retrieve_failed',
					__( 'Could not retrieve PaymentIntent for refund metadata.', 'cta-lms' ),
					array(
						'payment_intent' => $pi_id,
						'env'            => self::normalize_env( $env ),
						'error'          => $e->getMessage(),
					)
				);
			}
		}

		return array(
			'user_id'   => $user_id,
			'course_id' => $course_id,
		);
	}

	/**
	 * Find local stripe_payment_id (cs_…) for a user + course (and optional PI).
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $course_id Course ID.
	 * @param string $pi_id     Optional PaymentIntent ID.
	 * @return string
	 */
	private function find_payment_ref_for_user_course( $user_id, $course_id, $pi_id = '' ) {
		global $wpdb;

		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$pi_id     = sanitize_text_field( (string) $pi_id );

		if ( ! $user_id || ! $course_id ) {
			return '';
		}

		$table = $wpdb->prefix . 'cta_payments';

		if ( $pi_id ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT stripe_payment_id FROM {$table}
					WHERE user_id = %d
					AND product_id = %d
					AND product_type IN ('course', 'exam_prep')
					AND plan_details LIKE %s
					ORDER BY id DESC
					LIMIT 1",
					$user_id,
					$course_id,
					'%' . $wpdb->esc_like( $pi_id ) . '%'
				)
			);
			if ( $row && ! empty( $row->stripe_payment_id ) ) {
				return sanitize_text_field( (string) $row->stripe_payment_id );
			}
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT stripe_payment_id FROM {$table}
				WHERE user_id = %d
				AND product_id = %d
				AND product_type IN ('course', 'exam_prep')
				AND status IN ('completed', 'pending', 'refunded')
				ORDER BY id DESC
				LIMIT 1",
				$user_id,
				$course_id
			)
		);

		return ( $row && ! empty( $row->stripe_payment_id ) )
			? sanitize_text_field( (string) $row->stripe_payment_id )
			: '';
	}

	/**
	 * Log refund revoke outcome with has_active_access proof fields.
	 *
	 * @param string               $code    Log code.
	 * @param string               $message Message.
	 * @param array<string,mixed>  $context Context.
	 * @return void
	 */
	private function log_charge_refunded_revoke_result( $code, $message, $context ) {
		if ( ! class_exists( 'CTA_Roles' ) ) {
			return;
		}

		$user_id   = absint( $context['user_id'] ?? 0 );
		$course_id = absint( $context['course_id'] ?? 0 );

		$enrollment = ( $user_id && $course_id && class_exists( 'CTA_Database' ) )
			? CTA_Database::get_user_enrollment( $user_id, $course_id )
			: null;
		$still_has  = ( $user_id && $course_id && class_exists( 'CTA_CE_Access' ) )
			? CTA_CE_Access::has_active_access( $user_id, $course_id )
			: null;

		$context['enrollment_status'] = $enrollment ? (string) $enrollment->status : 'missing';
		$context['has_active_access'] = $still_has ? 1 : 0;

		CTA_Roles::log_enrollment_issue( $code, $message, $context );
	}

	/**
	 * Resolve user_id + course_id for a refunded charge.
	 *
	 * @param object $charge     Stripe charge.
	 * @param string $env        test|live.
	 * @param string $session_id Checkout Session ID if known.
	 * @param array  $payments   Already-matched payment rows.
	 * @return array{user_id:int,course_id:int}
	 */
	private function resolve_refund_user_course( $charge, $env, $session_id, $payments ) {
		global $wpdb;

		foreach ( (array) $payments as $payment ) {
			$user_id   = absint( $payment->user_id ?? 0 );
			$course_id = absint( $payment->product_id ?? 0 );
			if ( $user_id && $course_id ) {
				return array(
					'user_id'   => $user_id,
					'course_id' => $course_id,
				);
			}
		}

		$meta = array();
		if ( isset( $charge->metadata ) ) {
			if ( is_object( $charge->metadata ) && method_exists( $charge->metadata, 'toArray' ) ) {
				$meta = $charge->metadata->toArray();
			} else {
				$meta = (array) $charge->metadata;
			}
		}
		$user_id   = absint( $meta['user_id'] ?? 0 );
		$course_id = absint( $meta['course_id'] ?? 0 );

		if ( $session_id ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT user_id, product_id FROM {$wpdb->prefix}cta_payments
					WHERE stripe_payment_id = %s LIMIT 1",
					$session_id
				)
			);
			if ( $row ) {
				if ( ! $user_id ) {
					$user_id = absint( $row->user_id );
				}
				if ( ! $course_id ) {
					$course_id = absint( $row->product_id );
				}
			}

			$enrollment = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT user_id, course_id FROM {$wpdb->prefix}cta_enrollments
					WHERE payment_id = %s LIMIT 1",
					$session_id
				)
			);
			if ( $enrollment ) {
				if ( ! $user_id ) {
					$user_id = absint( $enrollment->user_id );
				}
				if ( ! $course_id ) {
					$course_id = absint( $enrollment->course_id );
				}
			}

			if ( ( ! $user_id || ! $course_id ) && $this->set_stripe_api_for_env( $env ) ) {
				try {
					$session = \Stripe\Checkout\Session::retrieve( $session_id );
					if ( $session && ! empty( $session->metadata ) ) {
						$sm = is_object( $session->metadata ) && method_exists( $session->metadata, 'toArray' )
							? $session->metadata->toArray()
							: (array) $session->metadata;
						if ( ! $user_id ) {
							$user_id = absint( $sm['user_id'] ?? 0 );
						}
						if ( ! $course_id ) {
							$course_id = absint( $sm['course_id'] ?? 0 );
						}
					}
				} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// Keep empty targets; caller logs not-found.
				}
			}
		}

		return array(
			'user_id'   => $user_id,
			'course_id' => $course_id,
		);
	}

	/**
	 * Set Stripe SDK API key for a specific environment (test|live).
	 *
	 * @param string $env Environment.
	 * @return bool
	 */
	private function set_stripe_api_for_env( $env ) {
		$creds = self::get_credentials( self::normalize_env( $env ) );
		if ( empty( $creds['secret_key'] ) || ! class_exists( '\Stripe\Stripe' ) ) {
			return false;
		}
		\Stripe\Stripe::setApiKey( $creds['secret_key'] );
		return true;
	}

	/**
	 * Resolve Checkout Session ID (cs_…) for a charge/PaymentIntent.
	 *
	 * Local payments store cs_… as stripe_payment_id; charges only carry pi_/ch_.
	 *
	 * @param object $charge Stripe charge.
	 * @param string $env    test|live.
	 * @return string
	 */
	private function resolve_checkout_session_id_for_charge( $charge, $env = 'test' ) {
		global $wpdb;

		$pi = $this->stripe_id( $charge->payment_intent ?? '' );
		if ( '' === $pi ) {
			return '';
		}

		// Prefer local mapping saved at checkout (plan_details.payment_intent).
		$table = $wpdb->prefix . 'cta_payments';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT stripe_payment_id FROM {$table}
				WHERE plan_details LIKE %s
				AND stripe_payment_id LIKE 'cs_%%'
				ORDER BY id DESC
				LIMIT 1",
				'%' . $wpdb->esc_like( $pi ) . '%'
			)
		);
		if ( $row && ! empty( $row->stripe_payment_id ) ) {
			return sanitize_text_field( (string) $row->stripe_payment_id );
		}

		// Ask Stripe which Checkout Session created this PaymentIntent (same env as webhook).
		if ( ! $this->set_stripe_api_for_env( $env ) ) {
			if ( class_exists( 'CTA_Roles' ) ) {
				CTA_Roles::log_enrollment_issue(
					'charge_refunded_session_lookup_failed',
					__( 'Stripe secret key missing for refund Session lookup.', 'cta-lms' ),
					array(
						'payment_intent' => $pi,
						'env'            => self::normalize_env( $env ),
					)
				);
			}
			return '';
		}

		try {
			$sessions = \Stripe\Checkout\Session::all(
				array(
					'payment_intent' => $pi,
					'limit'          => 1,
				)
			);
			if ( ! empty( $sessions->data[0]->id ) ) {
				$session_id = sanitize_text_field( (string) $sessions->data[0]->id );

				// Backfill payment_intent onto the local payment row for next time.
				$existing = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id, plan_details FROM {$table} WHERE stripe_payment_id = %s LIMIT 1",
						$session_id
					)
				);
				if ( $existing ) {
					$details = array();
					if ( ! empty( $existing->plan_details ) ) {
						$decoded = json_decode( (string) $existing->plan_details, true );
						if ( is_array( $decoded ) ) {
							$details = $decoded;
						}
					}
					$details['payment_intent'] = $pi;
					$charge_id                 = $this->stripe_id( $charge->id ?? '' );
					if ( $charge_id ) {
						$details['charge_id'] = $charge_id;
					}
					$wpdb->update(
						$table,
						array( 'plan_details' => wp_json_encode( $details ) ),
						array( 'id' => (int) $existing->id ),
						array( '%s' ),
						array( '%d' )
					);
				}

				return $session_id;
			}
		} catch ( \Exception $e ) {
			if ( class_exists( 'CTA_Roles' ) ) {
				CTA_Roles::log_enrollment_issue(
					'charge_refunded_session_lookup_failed',
					__( 'Could not resolve Checkout Session for refunded PaymentIntent.', 'cta-lms' ),
					array(
						'payment_intent' => $pi,
						'env'            => self::normalize_env( $env ),
						'error'          => $e->getMessage(),
					)
				);
			}
		}

		return '';
	}

	/**
	 * Locate local payment rows for a refunded Stripe charge.
	 *
	 * Course checkouts store Checkout Session IDs (cs_…) as stripe_payment_id, so
	 * payment_intent / charge IDs alone often miss. Resolve cs_ via Stripe, then
	 * fall back to customer/email + amount.
	 *
	 * @param object $charge     Stripe charge.
	 * @param string $env        test|live.
	 * @param string $session_id Optional pre-resolved Checkout Session ID.
	 * @return array<int,object>
	 */
	private function find_payments_for_refunded_charge( $charge, $env = 'test', $session_id = '' ) {
		global $wpdb;

		$table = $wpdb->prefix . 'cta_payments';
		$found = array();
		$seen  = array();

		$add_row = static function ( $row ) use ( &$found, &$seen ) {
			if ( ! $row || empty( $row->id ) ) {
				return;
			}
			$id = (int) $row->id;
			if ( isset( $seen[ $id ] ) ) {
				return;
			}
			$seen[ $id ] = true;
			$found[]     = $row;
		};

		if ( '' === $session_id ) {
			$session_id = $this->resolve_checkout_session_id_for_charge( $charge, $env );
		}

		$candidates = array_filter(
			array(
				$session_id,
				$this->stripe_id( $charge->payment_intent ?? '' ),
				$this->stripe_id( $charge->id ?? '' ),
			)
		);

		foreach ( $candidates as $id ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE stripe_payment_id = %s LIMIT 1",
					$id
				)
			);
			$add_row( $row );
		}

		if ( ! empty( $found ) ) {
			return $found;
		}

		$amount_cents = isset( $charge->amount ) ? (int) $charge->amount : 0;
		$customer_id  = $this->stripe_id( $charge->customer ?? '' );

		// Match by Stripe customer + amount in cents (avoids float ROUND/%f mismatches).
		if ( $customer_id && $amount_cents > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					WHERE stripe_customer_id = %s
					AND product_type IN ('course', 'exam_prep')
					AND status IN ('completed', 'pending', 'refunded')
					AND ABS( ROUND( amount * 100 ) - %d ) < 1
					ORDER BY id DESC
					LIMIT 5",
					$customer_id,
					$amount_cents
				)
			);
			foreach ( (array) $rows as $row ) {
				$add_row( $row );
			}
		}

		if ( ! empty( $found ) ) {
			return $found;
		}

		// Resolve WP user: charge metadata, then billing/receipt email, then customer meta map.
		$meta = array();
		if ( isset( $charge->metadata ) ) {
			if ( is_object( $charge->metadata ) && method_exists( $charge->metadata, 'toArray' ) ) {
				$meta = $charge->metadata->toArray();
			} else {
				$meta = (array) $charge->metadata;
			}
		}
		$meta_user   = absint( $meta['user_id'] ?? 0 );
		$meta_course = absint( $meta['course_id'] ?? 0 );
		$user_id     = $meta_user;

		// Session metadata (user_id / course_id) when we resolved cs_… from Stripe.
		if ( ( ! $user_id || ! $meta_course ) && $session_id && $this->set_stripe_api_for_env( $env ) ) {
			try {
				$session = \Stripe\Checkout\Session::retrieve( $session_id );
				if ( $session && ! empty( $session->metadata ) ) {
					$session_meta = is_object( $session->metadata ) && method_exists( $session->metadata, 'toArray' )
						? $session->metadata->toArray()
						: (array) $session->metadata;
					if ( ! $user_id ) {
						$user_id = absint( $session_meta['user_id'] ?? 0 );
					}
					if ( ! $meta_course ) {
						$meta_course = absint( $session_meta['course_id'] ?? 0 );
					}
				}
			} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fall through to email / amount matching.
			}
		}

		if ( ! $user_id ) {
			$email = '';
			if ( ! empty( $charge->billing_details->email ) ) {
				$email = sanitize_email( (string) $charge->billing_details->email );
			} elseif ( ! empty( $charge->receipt_email ) ) {
				$email = sanitize_email( (string) $charge->receipt_email );
			}
			if ( $email ) {
				$user = get_user_by( 'email', $email );
				if ( $user ) {
					$user_id = (int) $user->ID;
				}
			}
		}

		if ( ! $user_id && $customer_id ) {
			$users = get_users(
				array(
					'meta_key'   => 'cta_stripe_customer_id',
					'meta_value' => $customer_id,
					'number'     => 1,
					'fields'     => 'ID',
				)
			);
			if ( ! empty( $users[0] ) ) {
				$user_id = (int) $users[0];
			}
		}

		if ( $user_id && $meta_course ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					WHERE user_id = %d
					AND product_id = %d
					AND product_type IN ('course', 'exam_prep')
					AND status IN ('completed', 'pending', 'refunded')
					ORDER BY id DESC
					LIMIT 1",
					$user_id,
					$meta_course
				)
			);
			$add_row( $row );
		}

		if ( ! empty( $found ) ) {
			return $found;
		}

		// User + amount (Checkout metadata lives on the Session, not the Charge).
		if ( $user_id && $amount_cents > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					WHERE user_id = %d
					AND product_type IN ('course', 'exam_prep')
					AND status IN ('completed', 'pending', 'refunded')
					AND ABS( ROUND( amount * 100 ) - %d ) < 1
					ORDER BY id DESC
					LIMIT 5",
					$user_id,
					$amount_cents
				)
			);
			foreach ( (array) $rows as $row ) {
				$add_row( $row );
			}
		}

		return $found;
	}

	/**
	 * Revoke active course access after a full refund (does not delete certificates).
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return void
	 */
	private function revoke_course_access_after_full_refund( $user_id, $course_id ) {
		global $wpdb;

		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id || ! $course_id ) {
			return;
		}

		$table = $wpdb->prefix . 'cta_enrollments';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status FROM {$table}
				WHERE user_id = %d AND course_id = %d
				LIMIT 1",
				$user_id,
				$course_id
			)
		);

		if ( $row ) {
			$status = sanitize_key( (string) $row->status );
			// Lock access for both in-progress and completed enrollments.
			// Certificate rows in cta_certificates are left untouched; modules_completed stays on the row.
			if ( in_array( $status, array( 'active', 'completed' ), true ) ) {
				$wpdb->update(
					$table,
					array( 'status' => 'revoked' ),
					array( 'id' => (int) $row->id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		// Exam prep timed access (if any) — expire without deleting progress history.
		$exam_table = $wpdb->prefix . 'cta_exam_access';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $exam_table ) ) === $exam_table ) {
			$wpdb->update(
				$exam_table,
				array( 'expires_at' => current_time( 'mysql' ) ),
				array(
					'user_id'   => $user_id,
					'course_id' => $course_id,
				),
				array( '%s' ),
				array( '%d', '%d' )
			);
		}
	}

	/**
	 * Process completed checkout session.
	 *
	 * @param object $session Stripe checkout session.
	 */
	private function handle_checkout_completed( $session ) {
		global $wpdb;

		$metadata = isset( $session->metadata ) ? (array) $session->metadata : array();
		$user_id  = absint( $metadata['user_id'] ?? 0 );
		$type     = sanitize_text_field( $metadata['product_type'] ?? '' );

		// Fallback when metadata user_id is missing: match Stripe customer email to WP user.
		if ( ! $user_id ) {
			$email = '';
			if ( ! empty( $session->customer_email ) ) {
				$email = sanitize_email( (string) $session->customer_email );
			} elseif ( ! empty( $session->customer_details->email ) ) {
				$email = sanitize_email( (string) $session->customer_details->email );
			}
			if ( $email ) {
				$user = get_user_by( 'email', $email );
				if ( $user ) {
					$user_id = (int) $user->ID;
				}
			}
		}

		$session_id      = sanitize_text_field( (string) ( $session->id ?? '' ) );
		$payment_intent  = sanitize_text_field( (string) ( $session->payment_intent ?? '' ) );
		$plan_details    = array();
		if ( $payment_intent ) {
			$plan_details['payment_intent'] = $payment_intent;
		}

		$update_data = array(
			'status'             => 'completed',
			'stripe_customer_id' => sanitize_text_field( $session->customer ?? '' ),
		);
		$update_fmt  = array( '%s', '%s' );

		if ( ! empty( $plan_details ) ) {
			// Merge with any existing plan_details on the pending row.
			$existing_details = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT plan_details FROM {$wpdb->prefix}cta_payments WHERE stripe_payment_id = %s LIMIT 1",
					$session_id
				)
			);
			if ( $existing_details ) {
				$decoded = json_decode( (string) $existing_details, true );
				if ( is_array( $decoded ) ) {
					$plan_details = array_merge( $decoded, $plan_details );
				}
			}
			$update_data['plan_details'] = wp_json_encode( $plan_details );
			$update_fmt[]                = '%s';
		}

		$wpdb->update(
			$wpdb->prefix . 'cta_payments',
			$update_data,
			array( 'stripe_payment_id' => $session_id ),
			$update_fmt,
			array( '%s' )
		);

		if ( 'course' === $type || 'exam_prep' === $type ) {
			$course_id = absint( $metadata['course_id'] ?? 0 );

			if ( ! $user_id || ! $course_id ) {
				if ( class_exists( 'CTA_Roles' ) ) {
					CTA_Roles::log_enrollment_issue(
						'checkout_missing_ids',
						__( 'Paid checkout completed but user or course could not be resolved for enrollment.', 'cta-lms' ),
						array(
							'session_id'   => sanitize_text_field( (string) ( $session->id ?? '' ) ),
							'user_id'      => $user_id,
							'course_id'    => $course_id,
							'product_type' => $type,
						)
					);
				}
			} elseif ( $user_id && $course_id ) {
				$enrolled = $this->create_enrollment(
					$user_id,
					$course_id,
					sanitize_text_field( $session->id ),
					array(
						'access_source' => 'purchase',
						'expires_at'    => null,
					)
				);

				if ( ! $enrolled && class_exists( 'CTA_Roles' ) ) {
					CTA_Roles::log_enrollment_issue(
						'enrollment_failed_after_payment',
						__( 'Payment completed but course enrollment could not be created.', 'cta-lms' ),
						array(
							'session_id' => sanitize_text_field( (string) ( $session->id ?? '' ) ),
							'user_id'    => $user_id,
							'course_id'  => $course_id,
						)
					);
				}

				$course = CTA_Database::get_course( $course_id );
				if ( $course && class_exists( 'CTA_Exam_Access' ) && CTA_Exam_Access::is_exam_prep( $course ) ) {
					$months = ! empty( $course->access_period_months ) ? (int) $course->access_period_months : 6;
					CTA_Exam_Access::grant_access( $user_id, $course_id, $months );
				}

				if ( $course ) {
					CTA_Emails::send(
						'payment_receipt',
						$user_id,
						array(
							'payment_id'   => sanitize_text_field( $session->id ),
							'product_name' => function_exists( 'cta_lms_get_course_display_title' )
								? cta_lms_get_course_display_title( $course )
								: $course->title,
						)
					);
				}
			}
		}

		if ( 'supervision' === $type && $user_id ) {
			$subscription_id = sanitize_text_field( $session->subscription ?? '' );
			$customer_id     = sanitize_text_field( $session->customer ?? '' );
			$session_id      = sanitize_text_field( $session->id ?? '' );

			$this->activate_supervision_purchase(
				$user_id,
				array(
					'checkout_session_id' => $session_id,
					'subscription_id'     => $subscription_id,
					'customer_id'         => $customer_id,
					'amount'              => $this->get_supervision_monthly_price(),
					'send_receipt'        => true,
					'receipt_payment_id'  => $session_id,
				)
			);
		}

		if ( CTA_Supervision_Plans::INDIVIDUAL_SESSION_PRODUCT === $type && $user_id ) {
			$session_id  = sanitize_text_field( $session->id ?? '' );
			$customer_id = sanitize_text_field( $session->customer ?? '' );
			$amount      = isset( $session->amount_total ) ? ( (float) $session->amount_total / 100 ) : CTA_Supervision_Plans::get_individual_session_price();

			$this->activate_individual_session_purchase(
				$user_id,
				array(
					'checkout_session_id' => $session_id,
					'customer_id'         => $customer_id,
					'amount'              => $amount,
					'send_receipt'        => true,
					'receipt_payment_id'  => $session_id,
				)
			);
		}

		if ( 'bundle' === $type && $user_id ) {
			$bundle_id = absint( $metadata['bundle_id'] ?? 0 );
			$billing   = sanitize_text_field( $metadata['billing'] ?? '' );

			if ( $bundle_id ) {
				$this->activate_bundle_access(
					$user_id,
					$bundle_id,
					$billing,
					sanitize_text_field( $session->id )
				);

				$subscription_id = sanitize_text_field( $session->subscription ?? '' );
				$customer_id     = sanitize_text_field( $session->customer ?? '' );

				if ( $customer_id ) {
					update_user_meta( $user_id, 'cta_stripe_customer_id', $customer_id );
				}

				if ( $subscription_id ) {
					$wpdb->update(
						$wpdb->prefix . 'cta_payments',
						array(
							'stripe_payment_id'  => $subscription_id,
							'stripe_customer_id' => $customer_id ? $customer_id : null,
							'status'             => 'completed',
						),
						array(
							'user_id'      => $user_id,
							'product_id'   => $bundle_id,
							'product_type' => 'bundle',
						),
						array( '%s', '%s', '%s' ),
						array( '%d', '%d', '%s' )
					);

					update_user_meta( $user_id, 'cta_bundle_subscription_id', $subscription_id );
				}
			}
		}
	}

	/**
	 * Create Stripe Checkout session for a membership bundle.
	 *
	 * @param object $bundle Bundle row from database.
	 */
	public function create_bundle_checkout_session( $bundle ) {
		$bundle = $this->normalize_supervision_bundle( $bundle );

		$this->refresh_active_credentials();

		if ( ! $this->is_configured() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Stripe is not configured for the Active Mode. Add Sandbox or Live API keys in CTA LMS → Settings, set Active Mode, then try again.', 'cta-lms' ),
					'code'    => 'stripe_not_configured',
				)
			);
		}

		global $wpdb;

		$user_id = get_current_user_id();
		$user    = wp_get_current_user();
		$billing = sanitize_text_field( $bundle->billing_cycle );

		$memberships_page = $this->get_page_url( 'cta_memberships_page_id' );
		if ( ! $memberships_page ) {
			$memberships_page = home_url( '/' );
		}

		$success_url = $this->build_checkout_success_url(
			$memberships_page,
			array(
				'bundle_purchase' => 'success',
				'bundle_id'       => (int) $bundle->id,
			)
		);

		$cancel_url = $memberships_page;

		$mode         = ( 'monthly' === $billing ) ? 'subscription' : 'payment';
		$payment_type = ( 'monthly' === $billing ) ? 'subscription' : 'one_time';

		try {
			$session_args = array(
				'payment_method_types' => array( 'card' ),
				'mode'                 => $mode,
				'metadata'             => array(
					'user_id'      => (string) $user_id,
					'bundle_id'    => (string) $bundle->id,
					'product_type' => 'bundle',
					'billing'      => $billing,
				),
				'success_url'          => $success_url,
				'cancel_url'           => $cancel_url,
			);

			$existing_customer = $this->resolve_stripe_customer_id( $user_id );

			if ( $existing_customer ) {
				$session_args['customer'] = $existing_customer;
			} else {
				$session_args['customer_email'] = $user->user_email;
			}

			if ( 'monthly' === $billing && ! empty( $bundle->stripe_price_id ) ) {
				$session_args['line_items'] = array(
					array(
						'price'    => $bundle->stripe_price_id,
						'quantity' => 1,
					),
				);
			} elseif ( 'monthly' === $billing ) {
				$session_args['line_items'] = array(
					array(
						'price_data' => array(
							'currency'     => 'usd',
							'unit_amount'  => (int) round( (float) $bundle->price * 100 ),
							'recurring'    => array(
								'interval' => 'month',
							),
							'product_data' => array(
								'name'        => $bundle->name,
								'description' => wp_strip_all_tags( (string) $bundle->description ),
							),
						),
						'quantity'   => 1,
					),
				);
			} else {
				$session_args['line_items'] = array(
					array(
						'price_data' => array(
							'currency'     => 'usd',
							'unit_amount'  => (int) round( (float) $bundle->price * 100 ),
							'product_data' => array(
								'name'        => $bundle->name,
								'description' => wp_strip_all_tags( (string) $bundle->description ),
							),
						),
						'quantity'   => 1,
					),
				);
			}

			$session = \Stripe\Checkout\Session::create( $session_args );

			$wpdb->insert(
				$wpdb->prefix . 'cta_payments',
				array(
					'user_id'           => $user_id,
					'stripe_payment_id' => $session->id,
					'amount'            => $bundle->price,
					'currency'          => 'usd',
					'payment_type'      => $payment_type,
					'product_type'      => 'bundle',
					'product_id'        => (int) $bundle->id,
					'status'            => 'pending',
				),
				array( '%d', '%s', '%f', '%s', '%s', '%s', '%d', '%s' )
			);

			wp_send_json_success(
				array(
					'checkout_url' => $session->url,
				)
			);
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: Stripe error message */
						__( 'Payment error: %s', 'cta-lms' ),
						$e->getMessage()
					),
				)
			);
		}
	}

	/**
	 * Activate bundle access after successful payment.
	 *
	 * @param int    $user_id    User ID.
	 * @param int    $bundle_id  Bundle ID.
	 * @param string $billing    Billing cycle.
	 * @param string $payment_id Stripe session or payment ID.
	 */
	private function activate_bundle_access( $user_id, $bundle_id, $billing, $payment_id ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'cta_payments',
			array( 'status' => 'completed' ),
			array(
				'user_id'      => $user_id,
				'product_id'   => $bundle_id,
				'product_type' => 'bundle',
			),
			array( '%s' ),
			array( '%d', '%d', '%s' )
		);

		$bundle = CTA_Database::get_bundle( $bundle_id );
		if ( ! $bundle ) {
			return;
		}

		$included_ids = json_decode( (string) $bundle->included_courses, true );
		if ( ! is_array( $included_ids ) ) {
			$included_ids = array();
		}

		// CE memberships/bundles must never silently include exam prep products.
		if ( class_exists( 'CTA_Exam_Access' ) ) {
			$included_ids = CTA_Exam_Access::filter_ce_only_course_ids( $included_ids );
		}

		$enroll_args = class_exists( 'CTA_CE_Access' )
			? CTA_CE_Access::enrollment_args_for_bundle( $bundle, $billing )
			: array( 'access_source' => 'purchase', 'expires_at' => null );

		if ( 'annual' === $bundle->plan_type || 'yearly' === $billing || 'subscription' === $bundle->plan_type ) {
			$all_courses = CTA_Database::get_all_courses( 'published' );
			foreach ( $all_courses as $course ) {
				if ( class_exists( 'CTA_Exam_Access' ) && CTA_Exam_Access::is_exam_prep( $course ) ) {
					continue;
				}
				$this->enroll_user_in_course( $user_id, (int) $course->id, $payment_id, $enroll_args );
			}
		} else {
			foreach ( $included_ids as $course_id ) {
				$this->enroll_user_in_course( $user_id, (int) $course_id, $payment_id, $enroll_args );
			}
		}

		if ( 'subscription' === $bundle->plan_type ) {
			$all_access_name  = CTA_Supervision_Plans::get_name( CTA_Supervision_Plans::HYBRID_SLUG );
			$all_access_price = (float) $bundle->price > 0
				? (float) $bundle->price
				: CTA_Supervision_Plans::get_price( CTA_Supervision_Plans::HYBRID_SLUG );

			$wpdb->update(
				$wpdb->prefix . 'cta_payments',
				array(
					'plan_name'    => $all_access_name,
					'plan_details' => wp_json_encode(
						array(
							'plan_slug'    => CTA_Supervision_Plans::HYBRID_SLUG,
							'bundle_id'    => (int) $bundle->id,
							'billing'      => sanitize_text_field( $bundle->billing_cycle ),
							'price'        => $all_access_price,
							'product_type' => 'bundle',
							'description'  => wp_strip_all_tags( (string) $bundle->description ),
						)
					),
				),
				array(
					'user_id'      => $user_id,
					'product_id'   => (int) $bundle->id,
					'product_type' => 'bundle',
				),
				array( '%s', '%s' ),
				array( '%d', '%d', '%s' )
			);

			$this->activate_supervision_purchase(
				$user_id,
				array(
					'subscription_id'    => (string) get_user_meta( $user_id, 'cta_bundle_subscription_id', true ),
					'customer_id'        => (string) get_user_meta( $user_id, 'cta_stripe_customer_id', true ),
					'amount'             => $all_access_price,
					'plan_slug'          => CTA_Supervision_Plans::HYBRID_SLUG,
					'plan_name'          => $all_access_name,
					'plan_details'       => array(
						'plan_slug'    => CTA_Supervision_Plans::HYBRID_SLUG,
						'bundle_id'    => (int) $bundle->id,
						'billing'      => sanitize_text_field( $bundle->billing_cycle ),
						'price'        => $all_access_price,
						'product_type' => 'bundle',
						'description'  => wp_strip_all_tags( (string) $bundle->description ),
					),
					'skip_payment_row'   => true,
					'send_receipt'       => false,
				)
			);

			update_user_meta( $user_id, 'cta_hybrid_plan_active', (int) $bundle->id );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}

		CTA_Emails::send(
			'payment_receipt',
			$user_id,
			array(
				'payment_id'   => $payment_id,
				'product_name' => $bundle->name,
			)
		);
	}

	/**
	 * Resolve the chargeable course price (heal zero/missing from CE catalog when needed).
	 *
	 * @param object $course Course row.
	 * @return float
	 */
	private function resolve_course_checkout_price( $course ) {
		$price = isset( $course->price ) ? (float) $course->price : 0.0;

		if ( $price > 0 ) {
			return $price;
		}

		if ( ! class_exists( 'CTA_Course_Catalog' ) || ! method_exists( 'CTA_Course_Catalog', 'get_ce_catalog' ) ) {
			return $price;
		}

		$title = isset( $course->title ) ? (string) $course->title : '';
		foreach ( CTA_Course_Catalog::get_ce_catalog() as $entry ) {
			$match_titles = array();
			if ( ! empty( $entry['match_titles'] ) && is_array( $entry['match_titles'] ) ) {
				$match_titles = $entry['match_titles'];
			}
			if ( ! empty( $entry['title'] ) ) {
				$match_titles[] = (string) $entry['title'];
			}

			$matched = false;
			foreach ( $match_titles as $candidate ) {
				if ( 0 === strcasecmp( trim( (string) $candidate ), trim( $title ) ) ) {
					$matched = true;
					break;
				}
			}

			if ( ! $matched ) {
				continue;
			}

			$catalog_price = isset( $entry['price'] ) ? (float) $entry['price'] : 0.0;
			if ( $catalog_price <= 0 ) {
				return $price;
			}

			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'cta_courses',
				array( 'price' => $catalog_price ),
				array( 'id' => (int) $course->id ),
				array( '%f' ),
				array( '%d' )
			);

			return $catalog_price;
		}

		return $price;
	}

	/**
	 * Enroll without Stripe — allowed only for truly free courses ($0).
	 *
	 * @param int  $course_id   Course ID.
	 * @param bool $free_enroll Must be true; paid silent enroll is blocked.
	 */
	private function bypass_course_enrollment( $course_id, $free_enroll = false ) {
		global $wpdb;

		$course = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cta_courses
				WHERE id = %d AND status = 'published'",
				$course_id
			)
		);

		if ( ! $course ) {
			wp_send_json_error(
				array(
					'message' => __( 'Course not found.', 'cta-lms' ),
				)
			);
		}

		$price = $this->resolve_course_checkout_price( $course );
		if ( ! $free_enroll || $price > 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'This course requires Stripe payment. Free enrollment is not available.', 'cta-lms' ),
					'code'    => 'payment_required',
				)
			);
		}

		$user_id    = get_current_user_id();
		$payment_id = 'free-' . time();

		$enrolled = $this->create_enrollment(
			$user_id,
			$course_id,
			$payment_id,
			array(
				'access_source' => 'purchase',
				'expires_at'    => null,
			)
		);

		if ( ! $enrolled ) {
			wp_send_json_error(
				array(
					'message' => __( 'Unable to create enrollment. Please try again.', 'cta-lms' ),
				)
			);
		}

		$wpdb->insert(
			$wpdb->prefix . 'cta_payments',
			array(
				'user_id'           => $user_id,
				'stripe_payment_id' => $payment_id,
				'amount'            => 0,
				'currency'          => 'usd',
				'payment_type'      => 'one_time',
				'product_type'      => 'course',
				'product_id'        => $course_id,
				'status'            => 'completed',
			),
			array( '%d', '%s', '%f', '%s', '%s', '%s', '%d', '%s' )
		);

		$dashboard = $this->get_page_url( 'cta_student_dashboard_page_id' );
		if ( ! $dashboard ) {
			$dashboard = $this->get_course_player_url( $course_id );
		} else {
			$dashboard = add_query_arg(
				array(
					'cta_enrolled' => '1',
					'course_id'    => $course_id,
					'_cta'         => (string) time(),
				),
				$dashboard
			);
		}

		wp_send_json_success(
			array(
				'enrolled'     => true,
				'free_enroll'  => true,
				'redirect_url' => $dashboard,
				'message'      => __( 'Enrolled successfully.', 'cta-lms' ),
			)
		);
	}

	/**
	 * Skip Stripe and activate supervision subscription (testing mode).
	 */
	private function bypass_supervision_subscription() {
		global $wpdb;

		$user_id = get_current_user_id();

		CTA_Associate_Access::require_associate_for_purchase( $user_id );
		CTA_Associate_Access::require_agency_for_supervision_application( $user_id );

		$active_sub = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}cta_payments
				WHERE user_id = %d
				AND product_type = 'supervision'
				AND payment_type = 'subscription'
				AND status = 'completed'",
				$user_id
			)
		);

		if ( $active_sub ) {
			wp_send_json_error(
				array(
					'message' => __( 'You already have an active supervision subscription.', 'cta-lms' ),
				)
			);
		}

		$payment_id = 'bypass-sub-' . time();

		$this->activate_supervision_purchase(
			$user_id,
			array(
				'checkout_session_id' => $payment_id,
				'subscription_id'     => $payment_id,
				'amount'              => 0,
				'create_payment_row'  => true,
				'send_receipt'        => false,
			)
		);

		$redirect = $this->get_page_url( 'cta_supervision_dashboard_page_id' );
		if ( ! $redirect ) {
			$redirect = $this->get_page_url( 'cta_supervision_page_id' );
		}
		if ( ! $redirect ) {
			$redirect = home_url( '/' );
		}

		$redirect = add_query_arg(
			array(
				'subscription' => 'success',
				'cta_paid'     => '1',
				'_cta'         => (string) time(),
			),
			$redirect
		);

		clean_user_cache( $user_id );

		wp_send_json_success(
			array(
				'enrolled'     => true,
				'redirect_url' => $redirect,
				'message'      => __( 'Subscription recorded as Pending Approval (payment bypass mode).', 'cta-lms' ),
			)
		);
	}

	/**
	 * Create / complete a supervision purchase record and set Pending Approval.
	 *
	 * Uses existing `{prefix}cta_payments` + user meta — no duplicate user tables.
	 *
	 * @param int   $user_id User ID.
	 * @param array $args    Optional arguments.
	 * @return int Payment row ID, or 0 on failure.
	 */
	public function activate_supervision_purchase( $user_id, $args = array() ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return 0;
		}

		$args = wp_parse_args(
			$args,
			array(
				'checkout_session_id' => '',
				'subscription_id'     => '',
				'customer_id'         => '',
				'amount'              => $this->get_supervision_monthly_price(),
				'plan_slug'           => 'group',
				'plan_name'           => '',
				'plan_details'        => array(),
				'product_id'          => 0,
				'create_payment_row'  => true,
				'skip_payment_row'    => false,
				'send_receipt'        => false,
				'receipt_payment_id'  => '',
			)
		);

		$plan_slug = sanitize_key( $args['plan_slug'] );
		if ( ! in_array( $plan_slug, array( CTA_Supervision_Plans::GROUP_SLUG, CTA_Supervision_Plans::HYBRID_SLUG ), true ) ) {
			$plan_slug = CTA_Supervision_Plans::GROUP_SLUG;
		}
		$plan_slug = CTA_Supervision_Plans::normalize_slug( $plan_slug );

		$plan_name = sanitize_text_field( $args['plan_name'] );
		if ( '' === $plan_name ) {
			$plan_name = CTA_Supervision_Plans::get_name( $plan_slug );
		} else {
			$plan_name = CTA_Supervision_Plans::canonicalize_name( $plan_name );
			// Keep slug aligned with the canonicalized name.
			if ( CTA_Supervision_Plans::name_indicates_all_access( $plan_name ) ) {
				$plan_slug = CTA_Supervision_Plans::HYBRID_SLUG;
			}
		}

		$amount = (float) $args['amount'];
		if ( $amount <= 0 ) {
			$amount = CTA_Supervision_Plans::get_price( $plan_slug );
		}

		$plan_details = is_array( $args['plan_details'] ) ? $args['plan_details'] : array();
		$plan_details = wp_parse_args(
			$plan_details,
			array(
				'plan_slug'             => $plan_slug,
				'plan_name'             => $plan_name,
				'billing'               => 'monthly',
				'price'                 => $amount,
				'currency'              => 'usd',
				'sessions_per_month'    => 4,
				'session_duration_mins' => class_exists( 'CTA_Supervision' ) ? CTA_Supervision::GROUP_DURATION_MINS : 120,
				'max_group_size'        => class_exists( 'CTA_Supervision' ) ? CTA_Supervision::GROUP_SEATS_MAX : 8,
				'product_type'          => 'supervision',
				'description'           => (string) get_option(
					'cta_supervision_product_description',
					__( 'Monthly group supervision subscription', 'cta-lms' )
				),
			)
		);

		$plan_details_json = wp_json_encode( $plan_details );
		$table             = $wpdb->prefix . 'cta_payments';
		$session_id        = sanitize_text_field( $args['checkout_session_id'] );
		$subscription_id   = sanitize_text_field( $args['subscription_id'] );
		$customer_id       = sanitize_text_field( $args['customer_id'] );
		$stripe_ref        = $subscription_id ? $subscription_id : $session_id;
		$payment_id        = 0;

		if ( empty( $args['skip_payment_row'] ) ) {
			if ( $session_id ) {
				$existing = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id FROM {$table} WHERE stripe_payment_id = %s LIMIT 1",
						$session_id
					)
				);

				if ( $existing ) {
					$wpdb->update(
						$table,
						array(
							'user_id'            => $user_id,
							'stripe_payment_id'  => $stripe_ref ? $stripe_ref : $session_id,
							'stripe_customer_id' => $customer_id ? $customer_id : null,
							'amount'             => $amount,
							'currency'           => 'usd',
							'payment_type'       => 'subscription',
							'product_type'       => 'supervision',
							'product_id'         => absint( $args['product_id'] ),
							'plan_name'          => $plan_name,
							'plan_details'       => $plan_details_json,
							'status'             => 'completed',
						),
						array( 'id' => (int) $existing->id ),
						array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ),
						array( '%d' )
					);
					$payment_id = (int) $existing->id;
				}
			}

			if ( ! $payment_id && $stripe_ref && $stripe_ref !== $session_id ) {
				$existing = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id FROM {$table} WHERE stripe_payment_id = %s LIMIT 1",
						$stripe_ref
					)
				);

				if ( $existing ) {
					$wpdb->update(
						$table,
						array(
							'user_id'            => $user_id,
							'stripe_customer_id' => $customer_id ? $customer_id : null,
							'amount'             => $amount,
							'plan_name'          => $plan_name,
							'plan_details'       => $plan_details_json,
							'status'             => 'completed',
							'product_type'       => 'supervision',
						),
						array( 'id' => (int) $existing->id ),
						array( '%d', '%s', '%f', '%s', '%s', '%s', '%s' ),
						array( '%d' )
					);
					$payment_id = (int) $existing->id;
				}
			}

			if ( ! $payment_id && ! empty( $args['create_payment_row'] ) ) {
				$inserted = $wpdb->insert(
					$table,
					array(
						'user_id'            => $user_id,
						'stripe_payment_id'  => $stripe_ref ? $stripe_ref : ( 'supervision-' . $user_id . '-' . time() ),
						'stripe_customer_id' => $customer_id ? $customer_id : null,
						'amount'             => $amount,
						'currency'           => 'usd',
						'payment_type'       => 'subscription',
						'product_type'       => 'supervision',
						'product_id'         => absint( $args['product_id'] ),
						'plan_name'          => $plan_name,
						'plan_details'       => $plan_details_json,
						'status'             => 'completed',
					),
					array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
				);

				if ( $inserted ) {
					$payment_id = (int) $wpdb->insert_id;
				}
			}
		}

		if ( $customer_id ) {
			update_user_meta( $user_id, 'cta_stripe_customer_id', $customer_id );
		}

		if ( $subscription_id ) {
			update_user_meta( $user_id, 'cta_supervision_subscription_id', $subscription_id );
		}

		update_user_meta( $user_id, 'cta_supervision_plan', $plan_slug );
		update_user_meta( $user_id, 'cta_supervision_plan_name', $plan_name );
		// Plan axis only — application pending lives on cta_approval_status.
		update_user_meta( $user_id, 'cta_supervision_status', 'pending_approval' );

		// Keep Associate supervision application in sync when still awaiting review.
		if ( class_exists( 'CTA_Associate_Access' ) && CTA_Associate_Access::is_associate( $user_id ) ) {
			$approval = CTA_Associate_Access::get_approval_status( $user_id );

			if ( '' === $approval || CTA_Associate_Access::STATUS_PENDING === $approval ) {
				update_user_meta( $user_id, 'cta_approval_status', CTA_Associate_Access::STATUS_PENDING );
				CTA_Associate_Access::notify_admins_pending_application( $user_id );
			} elseif ( CTA_Associate_Access::STATUS_APPROVED === $approval ) {
				// Already-approved Associates unlock immediately after purchase.
				update_user_meta( $user_id, 'cta_supervision_status', 'active' );
			}

			// Never let a supervision purchase deactivate CE / Exam Prep.
			CTA_Associate_Access::ensure_account_active( $user_id );
		}

		if ( ! empty( $args['send_receipt'] ) ) {
			CTA_Emails::send(
				'payment_receipt',
				$user_id,
				array(
					'payment_id'   => sanitize_text_field( $args['receipt_payment_id'] ? $args['receipt_payment_id'] : $stripe_ref ),
					'product_name' => $plan_name,
				)
			);
		}

		return $payment_id;
	}

	/**
	 * Skip Stripe and activate a membership bundle (testing mode).
	 *
	 * @param object $bundle Bundle row.
	 */
	public function bypass_bundle_purchase( $bundle ) {
		$bundle = $this->normalize_supervision_bundle( $bundle );
		$user_id    = get_current_user_id();
		$payment_id = 'bypass-bundle-' . time();
		$billing    = sanitize_text_field( $bundle->billing_cycle );

		$this->activate_bundle_access( $user_id, (int) $bundle->id, $billing, $payment_id );

		$redirect = CTA_Emails::get_page_url( 'cta_student_dashboard_page_id' );
		if ( ! $redirect ) {
			$redirect = home_url( '/' );
		}

		wp_send_json_success(
			array(
				'enrolled'     => true,
				'redirect_url' => $redirect,
				'message'      => __( 'Plan activated (payment bypass mode).', 'cta-lms' ),
			)
		);
	}

	/**
	 * Get course player URL for a course.
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	private function get_course_player_url( $course_id ) {
		$page = $this->get_page_url( 'cta_course_player_page_id' );
		if ( ! $page ) {
			$page = $this->get_page_url( 'cta_single_course_page_id' );
		}
		if ( ! $page ) {
			$page = home_url( '/' );
		}

		return add_query_arg( 'course_id', $course_id, $page );
	}

	/**
	 * Enroll a user in a course if not already enrolled.
	 *
	 * @param int    $user_id    User ID.
	 * @param int    $course_id  Course ID.
	 * @param string $payment_id Payment reference ID.
	 */
	private function enroll_user_in_course( $user_id, $course_id, $payment_id, $args = array() ) {
		if ( ! $course_id ) {
			return;
		}

		$this->create_enrollment( $user_id, $course_id, $payment_id, $args );
	}

	/**
	 * Create course enrollment after successful payment.
	 *
	 * @param int    $user_id    User ID.
	 * @param int    $course_id  Course ID.
	 * @param string $payment_id Stripe session/payment ID.
	 * @param array  $args {
	 *     Optional enrollment options.
	 *
	 *     @type string      $access_source purchase|membership. Default purchase.
	 *     @type string|null $expires_at    MySQL datetime or null (permanent).
	 * }
	 * @return bool True when enrollment exists or was created.
	 */
	private function create_enrollment( $user_id, $course_id, $payment_id, $args = array() ) {
		global $wpdb;

		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id ) {
			if ( class_exists( 'CTA_Roles' ) ) {
				CTA_Roles::log_enrollment_issue(
					'create_enrollment_invalid_ids',
					__( 'Enrollment skipped because user or course ID was missing.', 'cta-lms' ),
					array(
						'user_id'    => $user_id,
						'course_id'  => $course_id,
						'payment_id' => sanitize_text_field( (string) $payment_id ),
					)
				);
			}
			return false;
		}

		// Self-heal: WP users without a CTA role (Role "None" / non-CTA signup) get a learner profile.
		if ( class_exists( 'CTA_Roles' ) && ! CTA_Roles::ensure_learner_profile( $user_id ) ) {
			CTA_Roles::log_enrollment_issue(
				'learner_profile_missing',
				__( 'Could not create CTA LMS learner profile/role for paid enrollment.', 'cta-lms' ),
				array(
					'user_id'    => $user_id,
					'course_id'  => $course_id,
					'payment_id' => sanitize_text_field( (string) $payment_id ),
				)
			);
			return false;
		}

		$args = wp_parse_args(
			$args,
			array(
				'access_source' => class_exists( 'CTA_CE_Access' ) ? CTA_CE_Access::SOURCE_PURCHASE : 'purchase',
				'expires_at'    => null,
			)
		);

		$access_source = sanitize_key( (string) $args['access_source'] );
		if ( ! in_array( $access_source, array( 'purchase', 'membership' ), true ) ) {
			$access_source = 'purchase';
		}

		$expires_at = null;
		if ( ! empty( $args['expires_at'] ) ) {
			$expires_at = sanitize_text_field( (string) $args['expires_at'] );
		}

		// Individual CE purchase is always permanent.
		$course = CTA_Database::get_course( $course_id );
		$is_ce  = $course && ( ! class_exists( 'CTA_Exam_Access' ) || ! CTA_Exam_Access::is_exam_prep( $course ) );
		if ( $is_ce && 'purchase' === $access_source ) {
			$expires_at = null;
		}

		// Never downgrade a prior individual purchase to membership access.
		if ( $is_ce && class_exists( 'CTA_CE_Access' ) && CTA_CE_Access::user_has_individual_purchase( $user_id, $course_id ) ) {
			$access_source = CTA_CE_Access::SOURCE_PURCHASE;
			$expires_at    = null;
		}

		$table = $wpdb->prefix . 'cta_enrollments';

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE user_id = %d AND course_id = %d
				LIMIT 1",
				$user_id,
				$course_id
			)
		);

		$created_or_restored = false;

		if ( $existing ) {
			$existing_source = ! empty( $existing->access_source )
				? sanitize_key( (string) $existing->access_source )
				: '';

			// Preserve purchase permanence if already purchased.
			if ( 'purchase' === $existing_source || ( class_exists( 'CTA_CE_Access' ) && CTA_CE_Access::user_has_individual_purchase( $user_id, $course_id ) ) ) {
				$access_source = 'purchase';
				$expires_at    = null;
			}

			$update = array(
				'payment_id'    => sanitize_text_field( $payment_id ),
				'access_source' => $access_source,
				'expires_at'    => $expires_at,
			);
			$formats = array( '%s', '%s', '%s' );

			if ( 'active' === $existing->status || 'completed' === $existing->status ) {
				// Keep status; only refresh access metadata.
			} else {
				// Do not restore access if this payment (or the course's payment) was refunded.
				$pay_status = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT status FROM {$wpdb->prefix}cta_payments
						WHERE stripe_payment_id = %s
						LIMIT 1",
						sanitize_text_field( (string) $payment_id )
					)
				);
				if ( $pay_status && 'refunded' === (string) $pay_status ) {
					return false;
				}
				if ( class_exists( 'CTA_CE_Access' ) && CTA_CE_Access::user_has_refunded_course_payment( $user_id, $course_id )
					&& ! CTA_CE_Access::user_has_completed_course_payment( $user_id, $course_id ) ) {
					return false;
				}
				// Restore revoked/other statuses to active for a new grant.
				$update['status'] = 'active';
				$formats[]        = '%s';
			}

			$updated = $wpdb->update(
				$table,
				$update,
				array( 'id' => (int) $existing->id ),
				$formats,
				array( '%d' )
			);

			$created_or_restored = false !== $updated;
		} else {
			$inserted = $wpdb->insert(
				$table,
				array(
					'user_id'       => $user_id,
					'course_id'     => $course_id,
					'status'        => 'active',
					'progress'      => 0,
					'payment_id'    => sanitize_text_field( $payment_id ),
					'access_source' => $access_source,
					'expires_at'    => $expires_at,
				),
				array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
			);

			if ( ! $inserted ) {
				// Unique-key race: another request may have inserted first.
				$retry = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$table}
						WHERE user_id = %d AND course_id = %d
						LIMIT 1",
						$user_id,
						$course_id
					)
				);

				$created_or_restored = (bool) $retry;
			} else {
				$created_or_restored = true;

				CTA_Emails::send(
					'enrollment_confirmation',
					$user_id,
					array(
						'course_id'  => $course_id,
						'payment_id' => $payment_id,
					)
				);
			}
		}

		// Exam prep: always ensure timed access row exists (progress stays in enrollments).
		if ( $created_or_restored && class_exists( 'CTA_Exam_Access' ) ) {
			if ( $course && CTA_Exam_Access::is_exam_prep( $course ) ) {
				$months = ! empty( $course->access_period_months ) ? (int) $course->access_period_months : 6;
				CTA_Exam_Access::grant_access( $user_id, $course_id, $months );
			}
		}

		return $created_or_restored;
	}

	/**
	 * Create enrollments for completed course payments that never got a row.
	 *
	 * @param int $user_id User ID.
	 * @return bool True when at least one enrollment was created/restored.
	 */
	public function maybe_sync_course_enrollments_from_payments( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		$payments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, product_id, stripe_payment_id
				FROM {$wpdb->prefix}cta_payments
				WHERE user_id = %d
				AND product_type = 'course'
				AND status = 'completed'
				AND product_id > 0
				ORDER BY id DESC
				LIMIT 20",
				$user_id
			)
		);

		if ( empty( $payments ) ) {
			return false;
		}

		$did_sync = false;

		foreach ( $payments as $payment ) {
			$course_id = absint( $payment->product_id );
			if ( ! $course_id ) {
				continue;
			}

			$enrollment_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, status FROM {$wpdb->prefix}cta_enrollments
					WHERE user_id = %d
					AND course_id = %d
					LIMIT 1",
					$user_id,
					$course_id
				)
			);

			if ( $enrollment_row ) {
				$enroll_status = sanitize_key( (string) ( $enrollment_row->status ?? '' ) );
				// Already has access, or was revoked (refund) — never auto-restore revoked.
				if ( in_array( $enroll_status, array( 'active', 'completed', 'revoked' ), true ) ) {
					continue;
				}
			}

			$payment_ref = ! empty( $payment->stripe_payment_id )
				? (string) $payment->stripe_payment_id
				: ( 'payment-' . (int) $payment->id );

			if ( $this->create_enrollment(
				$user_id,
				$course_id,
				$payment_ref,
				array(
					'access_source' => 'purchase',
					'expires_at'    => null,
				)
			) ) {
				$did_sync = true;
			}
		}

		return $did_sync;
	}

	/**
	 * Handle cancelled supervision subscription.
	 *
	 * @param object $subscription Stripe subscription object.
	 */
	private function handle_subscription_cancelled( $subscription ) {
		global $wpdb;

		$subscription_id = sanitize_text_field( $subscription->id ?? '' );

		if ( ! $subscription_id ) {
			return;
		}

		$wpdb->update(
			$wpdb->prefix . 'cta_payments',
			array( 'status' => 'refunded' ),
			array( 'stripe_payment_id' => $subscription_id ),
			array( '%s' ),
			array( '%s' )
		);

		$user_id = $this->get_user_id_by_subscription( $subscription_id );

		if ( $user_id ) {
			update_user_meta( $user_id, 'cta_supervision_status', 'cancelled' );
			update_user_meta( $user_id, 'cta_supervision_cancel_at_period_end', '0' );
			CTA_Emails::send( 'supervision_locked', $user_id );

			// Revoke membership-sourced CE access; keep individual purchases + certificates.
			if ( class_exists( 'CTA_CE_Access' ) ) {
				CTA_CE_Access::revoke_membership_access( $user_id );
			}
		}
	}

	/**
	 * Restore access after a successful subscription invoice payment.
	 *
	 * @param object $invoice Stripe invoice object.
	 */
	private function handle_subscription_invoice_paid( $invoice ) {
		$subscription_id = sanitize_text_field( $invoice->subscription ?? '' );

		if ( ! $subscription_id || ! class_exists( '\Stripe\Subscription' ) ) {
			return;
		}

		try {
			$subscription = \Stripe\Subscription::retrieve( $subscription_id );
			$this->sync_subscription_status_from_stripe( $subscription );
		} catch ( Exception $e ) {
			$user_id = $this->get_user_id_by_subscription( $subscription_id );
			if ( $user_id ) {
				update_user_meta( $user_id, 'cta_supervision_status', 'active' );
				update_user_meta( $user_id, 'cta_supervision_cancel_at_period_end', '0' );
			}
		}
	}

	/**
	 * Handle failed subscription invoice payment.
	 *
	 * @param object $invoice Stripe invoice object.
	 */
	private function handle_subscription_payment_failed( $invoice ) {
		$subscription_id = sanitize_text_field( $invoice->subscription ?? '' );

		if ( ! $subscription_id ) {
			return;
		}

		$user_id = $this->get_user_id_by_subscription( $subscription_id );

		if ( $user_id ) {
			update_user_meta( $user_id, 'cta_supervision_status', 'past_due' );
			$plan_slug = class_exists( 'CTA_Supervision_Plans' )
				? CTA_Supervision_Plans::resolve_user_plan_slug( $user_id )
				: 'group';
			CTA_Emails::send(
				'payment_failed',
				$user_id,
				array(
					'subscription_plan' => CTA_Supervision_Plans::get_name( $plan_slug ),
				)
			);
		}
	}

	/**
	 * Find WordPress user ID by Stripe subscription ID.
	 *
	 * @param string $subscription_id Stripe subscription ID.
	 * @return int
	 */
	private function get_user_id_by_subscription( $subscription_id ) {
		global $wpdb;

		$user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}cta_payments
				WHERE stripe_payment_id = %s
				LIMIT 1",
				$subscription_id
			)
		);

		if ( $user_id ) {
			return (int) $user_id;
		}

		$users = get_users(
			array(
				'meta_key'   => 'cta_supervision_subscription_id',
				'meta_value' => $subscription_id,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);

		if ( ! empty( $users[0] ) ) {
			return (int) $users[0];
		}

		$users = get_users(
			array(
				'meta_key'   => 'cta_bundle_subscription_id',
				'meta_value' => $subscription_id,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);

		return ! empty( $users[0] ) ? (int) $users[0] : 0;
	}

	/**
	 * Build a Stripe Checkout success URL with an unencoded session placeholder.
	 *
	 * WordPress add_query_arg() encodes braces, which prevents Stripe from
	 * replacing {CHECKOUT_SESSION_ID}. Append the placeholder literally.
	 *
	 * @param string $base_url Base return URL.
	 * @param array  $args     Extra query args (without session_id).
	 * @return string
	 */
	private function build_checkout_success_url( $base_url, $args = array() ) {
		$url = $base_url ? $base_url : home_url( '/' );

		if ( ! empty( $args ) ) {
			$url = add_query_arg( $args, $url );
		}

		$separator = ( false === strpos( $url, '?' ) ) ? '?' : '&';

		return $url . $separator . 'session_id={CHECKOUT_SESSION_ID}';
	}

	/**
	 * Finalize a Stripe Checkout session after the buyer returns from Stripe.
	 *
	 * Webhooks can be delayed/missing; this activates access on success redirect.
	 *
	 * @param string $session_id Stripe Checkout Session ID (cs_...).
	 * @param int    $user_id    Expected WordPress user ID (0 = use session metadata).
	 * @return bool
	 */
	public function finalize_checkout_session( $session_id, $user_id = 0 ) {
		$session_id = sanitize_text_field( $session_id );
		$user_id    = absint( $user_id );

		if ( '' === $session_id || 0 !== strpos( $session_id, 'cs_' ) ) {
			return false;
		}

		if ( ! $this->is_configured() ) {
			return false;
		}

		try {
			$session = \Stripe\Checkout\Session::retrieve( $session_id );
		} catch ( \Exception $e ) {
			return false;
		}

		if ( ! $session || empty( $session->id ) ) {
			return false;
		}

		$session_status  = sanitize_text_field( (string) ( $session->status ?? '' ) );
		$payment_status  = sanitize_text_field( (string) ( $session->payment_status ?? '' ) );
		$is_paid         = in_array( $payment_status, array( 'paid', 'no_payment_required' ), true );
		$is_complete     = 'complete' === $session_status;

		if ( ! $is_complete && ! $is_paid ) {
			return false;
		}

		$metadata       = isset( $session->metadata ) ? (array) $session->metadata : array();
		$session_user_id = absint( $metadata['user_id'] ?? 0 );

		if ( $user_id && $session_user_id && (int) $user_id !== (int) $session_user_id ) {
			return false;
		}

		$this->handle_checkout_completed( $session );

		$final_user_id = $session_user_id ? $session_user_id : $user_id;
		if ( $final_user_id ) {
			clean_user_cache( $final_user_id );
		}

		return true;
	}

	/**
	 * Reprocess course enrollment from an already-completed payment row (no new Stripe charge).
	 *
	 * @param string $payment_ref Stripe Checkout Session ID (cs_...) or local payment stripe_payment_id.
	 * @return true|WP_Error
	 */
	public function reprocess_completed_course_payment( $payment_ref ) {
		global $wpdb;

		$payment_ref = sanitize_text_field( (string) $payment_ref );
		if ( '' === $payment_ref ) {
			return new WP_Error( 'missing_ref', __( 'Payment reference is required.', 'cta-lms' ) );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cta_payments
				WHERE stripe_payment_id = %s
				LIMIT 1",
				$payment_ref
			)
		);

		if ( ! $row ) {
			return new WP_Error( 'payment_not_found', __( 'No local payment record found for that reference.', 'cta-lms' ) );
		}

		$user_id   = absint( $row->user_id );
		$course_id = absint( $row->product_id );
		$type      = sanitize_key( (string) $row->product_type );

		if ( ! in_array( $type, array( 'course', 'exam_prep' ), true ) ) {
			return new WP_Error( 'not_course', __( 'That payment is not a course purchase.', 'cta-lms' ) );
		}

		if ( ! $user_id || ! $course_id ) {
			return new WP_Error( 'incomplete_payment', __( 'Payment row is missing user or course ID.', 'cta-lms' ) );
		}

		if ( 'completed' !== (string) $row->status ) {
			$wpdb->update(
				$wpdb->prefix . 'cta_payments',
				array( 'status' => 'completed' ),
				array( 'id' => (int) $row->id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		$ok = $this->create_enrollment(
			$user_id,
			$course_id,
			$payment_ref,
			array(
				'access_source' => 'purchase',
				'expires_at'    => null,
			)
		);

		if ( ! $ok ) {
			return new WP_Error( 'enroll_failed', __( 'Could not create enrollment for this payment.', 'cta-lms' ) );
		}

		$course = CTA_Database::get_course( $course_id );
		if ( $course && class_exists( 'CTA_Exam_Access' ) && CTA_Exam_Access::is_exam_prep( $course ) ) {
			$months = ! empty( $course->access_period_months ) ? (int) $course->access_period_months : 6;
			CTA_Exam_Access::grant_access( $user_id, $course_id, $months );
		}

		return true;
	}

	/**
	 * Revoke course access from a local payment row (no Stripe API call).
	 *
	 * Marks the payment refunded and locks the enrollment. Certificates stay intact.
	 * Use for full-refund repair when the webhook did not revoke access.
	 *
	 * @param string $payment_ref Stripe Checkout Session ID (cs_...) or local stripe_payment_id.
	 * @return true|WP_Error
	 */
	public function revoke_course_access_from_payment( $payment_ref ) {
		global $wpdb;

		$payment_ref = sanitize_text_field( (string) $payment_ref );
		if ( '' === $payment_ref ) {
			return new WP_Error( 'missing_ref', __( 'Payment reference is required.', 'cta-lms' ) );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cta_payments
				WHERE stripe_payment_id = %s
				LIMIT 1",
				$payment_ref
			)
		);

		if ( ! $row ) {
			return new WP_Error( 'payment_not_found', __( 'No local payment record found for that reference.', 'cta-lms' ) );
		}

		$user_id   = absint( $row->user_id );
		$course_id = absint( $row->product_id );
		$type      = sanitize_key( (string) $row->product_type );

		if ( ! in_array( $type, array( 'course', 'exam_prep' ), true ) ) {
			return new WP_Error( 'not_course', __( 'That payment is not a course purchase.', 'cta-lms' ) );
		}

		if ( ! $user_id || ! $course_id ) {
			return new WP_Error( 'incomplete_payment', __( 'Payment row is missing user or course ID.', 'cta-lms' ) );
		}

		$wpdb->update(
			$wpdb->prefix . 'cta_payments',
			array( 'status' => 'refunded' ),
			array( 'id' => (int) $row->id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->revoke_course_access_after_full_refund( $user_id, $course_id );

		if ( class_exists( 'CTA_Roles' ) ) {
			CTA_Roles::log_enrollment_issue(
				'admin_revoke_access_from_payment',
				__( 'Admin revoked course access from payment reference.', 'cta-lms' ),
				array(
					'payment_ref' => $payment_ref,
					'payment_id'  => (int) $row->id,
					'user_id'     => $user_id,
					'course_id'   => $course_id,
				)
			);
		}

		return true;
	}

	/**
	 * Recover pending supervision checkout rows for the current user.
	 *
	 * @param int $user_id User ID.
	 * @return bool True when at least one session was finalized.
	 */
	public function maybe_finalize_user_pending_checkouts( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id || ! $this->is_configured() ) {
			return false;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stripe_payment_id
				FROM {$wpdb->prefix}cta_payments
				WHERE user_id = %d
				AND product_type IN ('supervision', %s)
				AND status = 'pending'
				AND stripe_payment_id LIKE 'cs_%%'
				ORDER BY id DESC
				LIMIT 5",
				$user_id,
				CTA_Supervision_Plans::INDIVIDUAL_SESSION_PRODUCT
			)
		);

		if ( empty( $rows ) ) {
			return false;
		}

		$did_finalize = false;

		foreach ( $rows as $row ) {
			$session_id = sanitize_text_field( (string) $row->stripe_payment_id );
			if ( $this->finalize_checkout_session( $session_id, $user_id ) ) {
				$did_finalize = true;
			}
		}

		return $did_finalize;
	}

	/**
	 * Recover pending course checkout rows for a user.
	 *
	 * This keeps the student dashboard in sync when the Stripe webhook is
	 * delayed or unavailable after a successful checkout.
	 *
	 * @param int $user_id User ID.
	 * @return bool True when at least one session was finalized.
	 */
	public function maybe_finalize_user_pending_course_checkouts( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id || ! $this->is_configured() ) {
			return false;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stripe_payment_id
				FROM {$wpdb->prefix}cta_payments
				WHERE user_id = %d
				AND product_type = 'course'
				AND status = 'pending'
				AND stripe_payment_id LIKE 'cs_%%'
				ORDER BY id DESC
				LIMIT 5",
				$user_id
			)
		);

		if ( empty( $rows ) ) {
			return false;
		}

		$did_finalize = false;

		foreach ( $rows as $row ) {
			$session_id = sanitize_text_field( (string) $row->stripe_payment_id );
			if ( $this->finalize_checkout_session( $session_id, $user_id ) ) {
				$did_finalize = true;
			}
		}

		return $did_finalize;
	}

	/**
	 * Get permalink from plugin page option.
	 *
	 * @param string $option_name Option key.
	 * @return string
	 */
	private function get_page_url( $option_name ) {
		$page_id = absint( get_option( $option_name, 0 ) );

		if ( ! $page_id ) {
			return '';
		}

		$url = get_permalink( $page_id );

		return $url ? $url : '';
	}
}
}