<?php
/**
 * Custom user roles for CTA LMS.
 *
 * @package CTA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CTA_Roles
 */
if ( ! class_exists( 'CTA_Roles' ) ) {

class CTA_Roles {

	const ROLE_LICENSED  = 'cta_licensed_professional';
	const ROLE_ASSOCIATE = 'cta_associate';

	/**
	 * Create custom plugin roles.
	 */
	public static function create_roles() {
		add_role(
			self::ROLE_LICENSED,
			'CTA Licensed Professional',
			array(
				'read'                      => true,
				'cta_access_courses'        => true,
				'cta_download_certificates' => true,
			)
		);

		add_role(
			self::ROLE_ASSOCIATE,
			'CTA Associate',
			array(
				'read'                     => true,
				'cta_access_supervision'   => true,
				'cta_upload_bbs_documents' => true,
				'cta_book_sessions'        => true,
			)
		);
	}

	/**
	 * Whether the user already has a CTA learner role (or is an administrator).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_has_cta_learner_role( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		if ( ! $user ) {
			return false;
		}

		$roles = (array) $user->roles;

		return in_array( self::ROLE_LICENSED, $roles, true )
			|| in_array( self::ROLE_ASSOCIATE, $roles, true )
			|| in_array( 'administrator', $roles, true );
	}

	/**
	 * Ensure a WordPress user has a CTA LMS learner profile/role.
	 *
	 * Used when accounts were created outside CTA registration (WP admin,
	 * imports, Role "None") so dashboard + enrollment can attach correctly.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $preferred Preferred role when assigning (licensed|associate slug).
	 * @return bool True when the user has a usable CTA learner role afterward.
	 */
	public static function ensure_learner_profile( $user_id, $preferred = self::ROLE_LICENSED ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}

		$user = new WP_User( $user_id );
		if ( ! $user->ID ) {
			return false;
		}

		self::create_roles();

		$roles = (array) $user->roles;

		if ( in_array( 'administrator', $roles, true ) ) {
			return true;
		}

		if ( in_array( self::ROLE_LICENSED, $roles, true ) || in_array( self::ROLE_ASSOCIATE, $roles, true ) ) {
			if ( class_exists( 'CTA_Associate_Access' ) ) {
				CTA_Associate_Access::ensure_account_active( $user_id );
			}
			return true;
		}

		$preferred = sanitize_key( $preferred );
		if ( ! in_array( $preferred, array( self::ROLE_LICENSED, self::ROLE_ASSOCIATE ), true ) ) {
			$preferred = self::ROLE_LICENSED;
		}

		if ( empty( $roles ) ) {
			$user->set_role( $preferred );
		} else {
			$user->add_role( $preferred );
		}

		if ( class_exists( 'CTA_Associate_Access' ) ) {
			CTA_Associate_Access::ensure_account_active( $user_id );
		} else {
			update_user_meta( $user_id, 'cta_account_status', 'active' );
		}

		clean_user_cache( $user_id );

		return self::user_has_cta_learner_role( $user_id );
	}

	/**
	 * One-time: assign CTA learner roles to WP users who have none (Role "None").
	 *
	 * Does not enroll anyone in courses — profile/role only.
	 *
	 * @return array{healed:int,skipped:int}
	 */
	public static function heal_users_missing_cta_roles() {
		self::create_roles();

		$healed  = 0;
		$skipped = 0;

		$users = get_users(
			array(
				'number' => -1,
				'fields' => array( 'ID' ),
			)
		);

		foreach ( (array) $users as $row ) {
			$user_id = isset( $row->ID ) ? absint( $row->ID ) : absint( $row );
			$user    = get_userdata( $user_id );
			if ( ! $user ) {
				++$skipped;
				continue;
			}

			$roles = (array) $user->roles;
			if ( ! empty( $roles ) ) {
				++$skipped;
				continue;
			}

			if ( self::ensure_learner_profile( $user_id ) ) {
				++$healed;
			} else {
				++$skipped;
			}
		}

		return array(
			'healed'  => $healed,
			'skipped' => $skipped,
		);
	}

	/**
	 * Persist an enrollment issue for admins (never silent after paid checkout).
	 *
	 * @param string              $code    Machine code.
	 * @param string              $message Human message (no secrets).
	 * @param array<string,mixed> $context IDs / refs only.
	 * @return void
	 */
	public static function log_enrollment_issue( $code, $message, $context = array() ) {
		$entry = array(
			'at'      => gmdate( 'c' ),
			'code'    => sanitize_key( (string) $code ),
			'message' => sanitize_text_field( (string) $message ),
			'context' => $context,
		);

		$log   = get_option( 'cta_enrollment_issue_log', array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = $entry;
		if ( count( $log ) > 100 ) {
			$log = array_slice( $log, -100 );
		}
		update_option( 'cta_enrollment_issue_log', $log, false );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[CTA LMS] Enrollment issue: ' . wp_json_encode( $entry ) );
		}
	}

	/**
	 * Recent enrollment issues for admin UI.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_enrollment_issue_log( $limit = 20 ) {
		$log = get_option( 'cta_enrollment_issue_log', array() );
		$log = is_array( $log ) ? $log : array();
		return array_slice( array_reverse( $log ), 0, absint( $limit ) );
	}

	/**
	 * Remove custom plugin roles (called on uninstall only).
	 */
	public static function remove_roles() {
		remove_role( self::ROLE_LICENSED );
		remove_role( self::ROLE_ASSOCIATE );
	}
}
}
