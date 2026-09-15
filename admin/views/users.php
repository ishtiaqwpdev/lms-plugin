<?php
/**
 * Admin users list view.
 *
 * @package CTA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$license_filter        = isset( $license_filter ) ? $license_filter : 'all';
$supervision_filter    = isset( $supervision_filter ) ? $supervision_filter : 'all';
$missing_license_count = isset( $missing_license_count ) ? (int) $missing_license_count : 0;
$license_types         = isset( $license_types ) ? $license_types : cta_lms_get_license_types();
$notice                = sanitize_text_field( wp_unslash( $_GET['cta_notice'] ?? '' ) );
?>
<div class="wrap cta-admin-wrap">
	<h1><?php esc_html_e( 'CTA Users', 'cta-lms' ); ?></h1>

	<?php if ( 'license_saved' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'License information updated.', 'cta-lms' ); ?></p></div>
	<?php endif; ?>

	<div class="cta-admin-panel" style="margin:16px 0;">
		<h2><?php esc_html_e( 'Enrollment repair tools', 'cta-lms' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Use these after a successful Stripe payment when a learner is missing from CTA Users or cannot open My Dashboard. These tools never create a new Stripe charge.', 'cta-lms' ); ?></p>
		<p>
			<label for="cta-reprocess-payment-ref"><strong><?php esc_html_e( 'Reprocess / revoke course access from payment', 'cta-lms' ); ?></strong></label><br>
			<input type="text" class="regular-text" id="cta-reprocess-payment-ref" placeholder="cs_..." autocomplete="off">
			<button type="button" class="button button-primary" id="cta-reprocess-payment-btn"><?php esc_html_e( 'Grant access from this payment', 'cta-lms' ); ?></button>
			<button type="button" class="button" id="cta-revoke-payment-btn"><?php esc_html_e( 'Revoke access from this payment', 'cta-lms' ); ?></button>
			<span id="cta-reprocess-payment-result" class="cta-inline-result"></span>
		</p>
		<p class="description"><?php esc_html_e( 'Paste the Checkout Session ID (cs_…) from Stripe → Payments / Events. Grant restores access after a paid checkout; Revoke locks the course after a full refund (same cs_… — no new charge).', 'cta-lms' ); ?></p>
		<p>
			<button type="button" class="button" id="cta-heal-missing-roles-btn"><?php esc_html_e( 'Heal WordPress users with Role None', 'cta-lms' ); ?></button>
			<span id="cta-heal-missing-roles-result" class="cta-inline-result"></span>
		</p>
		<?php
		$last_refund_ping = get_option( 'cta_stripe_last_refund_webhook', null );
		if ( is_array( $last_refund_ping ) && ! empty( $last_refund_ping ) ) :
			?>
			<p class="description" style="margin-top:8px;">
				<strong><?php esc_html_e( 'Last refund webhook ping:', 'cta-lms' ); ?></strong>
				<code><?php echo esc_html( (string) ( $last_refund_ping['at'] ?? '' ) ); ?></code>
				— <code><?php echo esc_html( (string) ( $last_refund_ping['code'] ?? '' ) ); ?></code>
				<?php if ( ! empty( $last_refund_ping['context'] ) && is_array( $last_refund_ping['context'] ) ) : ?>
					<em>(<?php echo esc_html( wp_json_encode( $last_refund_ping['context'] ) ); ?>)</em>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<?php
		$enrollment_issues = isset( $enrollment_issues ) && is_array( $enrollment_issues ) ? $enrollment_issues : array();
		if ( ! empty( $enrollment_issues ) ) :
			?>
			<details style="margin-top:12px;">
				<summary><strong><?php esc_html_e( 'Recent enrollment issues', 'cta-lms' ); ?></strong></summary>
				<ul>
					<?php foreach ( $enrollment_issues as $issue ) : ?>
						<li>
							<code><?php echo esc_html( (string) ( $issue['at'] ?? '' ) ); ?></code>
							— <?php echo esc_html( (string) ( $issue['message'] ?? '' ) ); ?>
							<?php if ( ! empty( $issue['context'] ) && is_array( $issue['context'] ) ) : ?>
								<em>(<?php echo esc_html( wp_json_encode( $issue['context'] ) ); ?>)</em>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>
	</div>

	<div class="cta-admin-tabs">
		<?php
		$tabs = array(
			'all'           => __( 'All', 'cta-lms' ),
			'licensed'      => __( 'Licensed Professionals', 'cta-lms' ),
			'associate'     => __( 'Associates', 'cta-lms' ),
			'administrator' => __( 'Administrators', 'cta-lms' ),
		);
		foreach ( $tabs as $key => $label ) :
			$url = add_query_arg(
				array(
					'page'        => 'cta-lms-users',
					'role'        => $key,
					'license'     => $license_filter,
					'supervision' => $supervision_filter,
					's'           => $search,
				),
				admin_url( 'admin.php' )
			);
			?>
			<a class="cta-admin-tab <?php echo $role_filter === $key ? 'cta-admin-tab--active' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</div>

	<form method="get" class="cta-admin-filters">
		<input type="hidden" name="page" value="cta-lms-users">
		<input type="hidden" name="role" value="<?php echo esc_attr( $role_filter ); ?>">
		<select name="license">
			<option value="all" <?php selected( $license_filter, 'all' ); ?>><?php esc_html_e( 'All license info', 'cta-lms' ); ?></option>
			<option value="missing" <?php selected( $license_filter, 'missing' ); ?>>
				<?php
				printf(
					/* translators: %d: count of users missing license number */
					esc_html__( 'Missing license info (%d)', 'cta-lms' ),
					(int) $missing_license_count
				);
				?>
			</option>
			<option value="present" <?php selected( $license_filter, 'present' ); ?>><?php esc_html_e( 'Has license number', 'cta-lms' ); ?></option>
		</select>
		<select name="supervision">
			<option value="all" <?php selected( $supervision_filter, 'all' ); ?>><?php esc_html_e( 'All supervision statuses', 'cta-lms' ); ?></option>
			<option value="active" <?php selected( $supervision_filter, 'active' ); ?>><?php esc_html_e( 'Active', 'cta-lms' ); ?></option>
			<option value="past_due" <?php selected( $supervision_filter, 'past_due' ); ?>><?php esc_html_e( 'Past Due', 'cta-lms' ); ?></option>
			<option value="locked" <?php selected( $supervision_filter, 'locked' ); ?>><?php esc_html_e( 'Locked', 'cta-lms' ); ?></option>
			<option value="cancelled" <?php selected( $supervision_filter, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'cta-lms' ); ?></option>
			<option value="pending_approval" <?php selected( $supervision_filter, 'pending_approval' ); ?>><?php esc_html_e( 'Supervision Application Pending', 'cta-lms' ); ?></option>
			<option value="none" <?php selected( $supervision_filter, 'none' ); ?>><?php esc_html_e( 'No supervision status', 'cta-lms' ); ?></option>
		</select>
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name or email', 'cta-lms' ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'cta-lms' ); ?></button>
	</form>

	<?php if ( 'missing' === $license_filter ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'Showing students who have not entered a license / registration number. You can enter or correct it for them below — the same fields appear in their Account Settings.', 'cta-lms' ); ?></p></div>
	<?php endif; ?>

	<table class="widefat striped cta-admin-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'cta-lms' ); ?></th>
				<th><?php esc_html_e( 'Email', 'cta-lms' ); ?></th>
				<th><?php esc_html_e( 'Role', 'cta-lms' ); ?></th>
				<th><?php esc_html_e( 'License Number', 'cta-lms' ); ?></th>
				<th><?php esc_html_e( 'License Type', 'cta-lms' ); ?></th>
				<th><?php esc_html_e( 'Joined', 'cta-lms' ); ?></th>
				<th><?php esc_html_e( 'Enrolled', 'cta-lms' ); ?></th>
				<th><?php esc_html_e( 'Supervision', 'cta-lms' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'cta-lms' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $users ) ) : ?>
				<tr><td colspan="9"><?php esc_html_e( 'No users found.', 'cta-lms' ); ?></td></tr>
			<?php else : ?>
				<?php
				global $wpdb;
				foreach ( $users as $user ) :
					$roles              = (array) $user->roles;
					$role_label         = ! empty( $roles ) ? ucwords( str_replace( array( 'cta_', '_' ), array( '', ' ' ), $roles[0] ) ) : '';
					$enrolled_count     = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}cta_enrollments WHERE user_id = %d",
							$user->ID
						)
					);
					$supervision_status = (string) get_user_meta( $user->ID, 'cta_supervision_status', true );
					$cancel_pending     = '1' === (string) get_user_meta( $user->ID, 'cta_supervision_cancel_at_period_end', true );
					$subscription_id    = (string) get_user_meta( $user->ID, 'cta_supervision_subscription_id', true );
					if ( '' === $subscription_id ) {
						$subscription_id = (string) get_user_meta( $user->ID, 'cta_bundle_subscription_id', true );
					}
					$has_stripe_sub = ( '' !== $subscription_id && 0 !== strpos( $subscription_id, 'bypass-' ) );
					$supervision_label  = $supervision_status
						? ( 'pending_approval' === $supervision_status
							? __( 'Supervision Application Pending', 'cta-lms' )
							: ( 'past_due' === $supervision_status
								? __( 'Past Due', 'cta-lms' )
								: ucwords( str_replace( '_', ' ', $supervision_status ) ) ) )
						: '—';
					if ( $cancel_pending && 'active' === $supervision_status ) {
						$supervision_label = __( 'Active (cancels at period end)', 'cta-lms' );
					}
					$license_number     = cta_lms_get_user_license_number( $user->ID );
					$license_type       = (string) get_user_meta( $user->ID, 'cta_license_type', true );
					$has_license        = '' !== $license_number;
					?>
					<tr
						data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>"
						data-license-number="<?php echo esc_attr( $license_number ); ?>"
						data-license-type="<?php echo esc_attr( $license_type ); ?>"
						data-display-name="<?php echo esc_attr( $user->display_name ); ?>"
						data-supervision-status="<?php echo esc_attr( $supervision_status ); ?>"
						data-cancel-pending="<?php echo $cancel_pending ? '1' : '0'; ?>"
						data-has-stripe-sub="<?php echo $has_stripe_sub ? '1' : '0'; ?>"
					>
						<td><strong><?php echo esc_html( $user->display_name ); ?></strong></td>
						<td><?php echo esc_html( $user->user_email ); ?></td>
						<td><?php echo esc_html( $role_label ); ?></td>
						<td class="cta-user-license-number">
							<?php if ( $has_license ) : ?>
								<?php echo esc_html( $license_number ); ?>
							<?php else : ?>
								<span class="cta-status-badge cta-status-badge--draft" title="<?php esc_attr_e( 'Student has not entered a license number', 'cta-lms' ); ?>"><?php esc_html_e( 'Missing', 'cta-lms' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="cta-user-license-type"><?php echo $license_type ? esc_html( $license_type ) : '—'; ?></td>
						<td><?php echo esc_html( cta_lms_format_local_date( $user->user_registered, 'M j, Y' ) ); ?></td>
						<td><?php echo esc_html( (string) $enrolled_count ); ?></td>
						<td class="cta-user-supervision-status"><?php echo esc_html( $supervision_label ); ?></td>
						<td class="cta-table-actions">
							<button
								type="button"
								class="button button-small cta-edit-user-license"
								data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>"
							><?php esc_html_e( 'Edit License', 'cta-lms' ); ?></button>
							<button type="button" class="button button-small cta-view-user-stats" data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>"><?php esc_html_e( 'Stats', 'cta-lms' ); ?></button>
							<?php if ( $has_stripe_sub || in_array( $supervision_status, array( 'active', 'past_due', 'locked', 'cancelled' ), true ) ) : ?>
								<button type="button" class="button button-small cta-admin-sync-sub" data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>"><?php esc_html_e( 'Sync Stripe', 'cta-lms' ); ?></button>
								<?php if ( $cancel_pending ) : ?>
									<button type="button" class="button button-small cta-admin-reactivate-sub" data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>"><?php esc_html_e( 'Reactivate', 'cta-lms' ); ?></button>
								<?php elseif ( in_array( $supervision_status, array( 'active', 'past_due', 'locked' ), true ) ) : ?>
									<button type="button" class="button button-small cta-admin-cancel-sub" data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>" data-mode="at_period_end"><?php esc_html_e( 'Cancel at Period End', 'cta-lms' ); ?></button>
									<button type="button" class="button button-small cta-admin-cancel-sub" data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>" data-mode="immediately"><?php esc_html_e( 'Cancel Now', 'cta-lms' ); ?></button>
								<?php endif; ?>
							<?php endif; ?>
							<a class="button button-small" href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php esc_html_e( 'WP Profile', 'cta-lms' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<div id="cta-user-stats-modal" class="cta-admin-modal" hidden>
		<div class="cta-admin-modal__content">
			<button type="button" class="cta-admin-modal__close" aria-label="<?php esc_attr_e( 'Close', 'cta-lms' ); ?>">&times;</button>
			<h2><?php esc_html_e( 'User Stats', 'cta-lms' ); ?></h2>
			<div id="cta-user-stats-body"></div>
		</div>
	</div>

	<div id="cta-user-license-modal" class="cta-admin-modal" hidden>
		<div class="cta-admin-modal__content">
			<button type="button" class="cta-admin-modal__close" aria-label="<?php esc_attr_e( 'Close', 'cta-lms' ); ?>">&times;</button>
			<h2><?php esc_html_e( 'Edit License Information', 'cta-lms' ); ?></h2>
			<p class="description" id="cta-license-modal-user"></p>
			<p class="description"><?php esc_html_e( 'Updates the same License Number and License Type the student sees in Account Settings (and on CE certificates).', 'cta-lms' ); ?></p>
			<form id="cta-user-license-form" class="cta-admin-form">
				<input type="hidden" name="user_id" id="cta-license-user-id" value="">
				<p>
					<label for="cta-license-number-input"><strong><?php esc_html_e( 'License Number', 'cta-lms' ); ?></strong></label><br>
					<input type="text" class="regular-text" id="cta-license-number-input" name="license_number" maxlength="64" autocomplete="off">
				</p>
				<p>
					<label for="cta-license-type-input"><strong><?php esc_html_e( 'License Type', 'cta-lms' ); ?></strong></label><br>
					<select id="cta-license-type-input" name="license_type">
						<option value=""><?php esc_html_e( '— Select —', 'cta-lms' ); ?></option>
						<?php foreach ( $license_types as $type ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $type ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save License Info', 'cta-lms' ); ?></button>
					<span id="cta-license-save-status" class="cta-inline-result"></span>
				</p>
			</form>
		</div>
	</div>
</div>
