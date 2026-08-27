<?php
/**
 * Queue heavy LMS upgrade/sync work so plugin updates do not 504 the live site.
 *
 * @package CTA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CTA_Lms_Deferred_Upgrades' ) ) {

class CTA_Lms_Deferred_Upgrades {

	const QUEUE_OPTION = 'cta_lms_deferred_upgrade_queue';
	const CRON_HOOK    = 'cta_lms_process_deferred_upgrades';
	const LOCK_KEY     = 'cta_lms_deferred_processing';

	/**
	 * Register cron + lazy processor hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_one' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_process_on_admin' ), 20 );
		add_action( 'shutdown', array( __CLASS__, 'maybe_process_on_shutdown' ), 9999 );
	}

	/**
	 * Queue CE + Exam Prep module / quiz / materials sync (one task per request).
	 *
	 * @return void
	 */
	public static function queue_full_content_sync() {
		$tasks = array(
			'content_law_ethics_ce',
			'content_telehealth',
			'content_suicide_risk',
			'content_lmft_law_ethics',
			'content_lcsw_law_ethics',
			'content_lpcc_law_ethics',
			'content_lcsw_aswb',
			'content_lmft_clinical',
			'content_lmft_amftrb',
			'content_lpcc_ncmhce',
			'content_materials',
			'lcsw_forms_ab',
			'lcsw_workbook_banks',
			'lmft_clinical_form_a',
			'lmft_clinical_workbook_banks',
			'lmft_law_ethics_workbook_banks',
			'lmft_law_ethics_practice_exams',
			'lmft_amftrb_workbook_banks',
			'lpcc_ncmhce_form_a_v2',
			'lpcc_ncmhce_form_b_v2',
			'lpcc_ncmhce_forms_ab',
		);

		foreach ( $tasks as $task ) {
			self::queue( $task );
		}
	}

	/**
	 * Queue only Exam Prep modules, workbooks, quizzes, and downloads.
	 *
	 * @return void
	 */
	public static function queue_exam_prep_content_sync() {
		$tasks = array(
			'content_lmft_law_ethics',
			'content_lcsw_law_ethics',
			'content_lpcc_law_ethics',
			'content_lcsw_aswb',
			'content_lmft_clinical',
			'content_lmft_amftrb',
			'content_lpcc_ncmhce',
			'lcsw_forms_ab',
			'lcsw_workbook_banks',
			'lmft_clinical_form_a',
			'lmft_clinical_workbook_banks',
			'lmft_law_ethics_workbook_banks',
			'lmft_law_ethics_practice_exams',
			'lmft_amftrb_workbook_banks',
			'lpcc_ncmhce_form_a_v2',
			'lpcc_ncmhce_form_b_v2',
			'lpcc_ncmhce_forms_ab',
		);

		foreach ( $tasks as $task ) {
			self::queue( $task );
		}
	}

	/**
	 * Remaining background content-sync tasks.
	 *
	 * @return int
	 */
	public static function remaining_count() {
		$queue = (array) get_option( self::QUEUE_OPTION, array() );
		return count( $queue );
	}

	/**
	 * Drain one queued task on admin page views (not during plugin install).
	 *
	 * @return void
	 */
	public static function maybe_process_on_admin() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( in_array( $action, array( 'cta_sync_syllabus', 'cta_sync_exam_prep_content', 'update-plugin', 'install-plugin', 'activate-plugin' ), true ) ) {
			return;
		}

		self::process_one();
	}

	/**
	 * @param string $task Task key.
	 * @return void
	 */
	public static function queue( $task ) {
		$task = sanitize_key( (string) $task );
		if ( '' === $task ) {
			return;
		}

		$queue = (array) get_option( self::QUEUE_OPTION, array() );
		if ( ! in_array( $task, $queue, true ) ) {
			$queue[] = $task;
			update_option( self::QUEUE_OPTION, $queue, false );
		}

		self::schedule();
	}

	/**
	 * @return void
	 */
	public static function schedule() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + 15, self::CRON_HOOK );
	}

	/**
	 * Process one queued task (cron).
	 *
	 * @return void
	 */
	public static function process_one() {
		if ( get_transient( self::LOCK_KEY ) || get_transient( 'cta_lms_upgrading' ) ) {
			self::schedule();
			return;
		}

		$queue = (array) get_option( self::QUEUE_OPTION, array() );
		if ( empty( $queue ) ) {
			return;
		}

		set_transient( self::LOCK_KEY, 1, MINUTE_IN_SECONDS );

		$task  = (string) array_shift( $queue );
		update_option( self::QUEUE_OPTION, $queue, false );

		self::run_task( $task );

		delete_transient( self::LOCK_KEY );

		if ( ! empty( $queue ) ) {
			self::schedule();
		}
	}

	/**
	 * Process one queued task after the HTTP response (avoids admin/update 504s).
	 *
	 * @return void
	 */
	public static function maybe_process_on_shutdown() {
		if ( get_transient( self::LOCK_KEY ) || get_transient( 'cta_lms_upgrading' ) ) {
			return;
		}

		$queue = (array) get_option( self::QUEUE_OPTION, array() );
		if ( empty( $queue ) ) {
			return;
		}

		if ( ! wp_doing_cron() && ! is_admin() ) {
			return;
		}

		// Never run during plugin install/update HTTP actions.
		if ( wp_doing_ajax() && ! empty( $_REQUEST['action'] ) ) {
			$action = sanitize_key( (string) wp_unslash( $_REQUEST['action'] ) );
			if ( in_array( $action, array( 'update-plugin', 'install-plugin', 'activate-plugin', 'wppusher-pull' ), true ) ) {
				return;
			}
		}

		self::process_one();
	}

	/**
	 * @deprecated 1.0.283 Use maybe_process_on_shutdown().
	 * @return void
	 */
	public static function maybe_process_on_load() {
		self::maybe_process_on_shutdown();
	}

	/**
	 * @param string $task Task key.
	 * @return void
	 */
	private static function run_task( $task ) {
		switch ( $task ) {
			case 'lcsw_workbook_banks':
				if ( class_exists( 'CTA_Lcsw_Aswb_Sync' ) ) {
					$result = CTA_Lcsw_Aswb_Sync::sync_workbook_banks_missing( 0, 2 );
					if ( empty( $result['ok'] ) && ! empty( $result['remaining'] ) ) {
						self::queue( 'lcsw_workbook_banks' );
					}
				}
				break;

			case 'lcsw_forms_ab':
				if ( class_exists( 'CTA_Lcsw_Aswb_Sync' ) ) {
					CTA_Lcsw_Aswb_Sync::sync_forms_only( 0 );
				}
				break;

			case 'lmft_clinical_workbook_banks':
				if ( class_exists( 'CTA_Lmft_Clinical_Sync' ) ) {
					$result = CTA_Lmft_Clinical_Sync::sync_workbook_banks_missing( 0, 2 );
					if ( empty( $result['ok'] ) && ! empty( $result['remaining'] ) ) {
						self::queue( 'lmft_clinical_workbook_banks' );
					}
				}
				break;

			case 'lmft_law_ethics_workbook_banks':
				if ( class_exists( 'CTA_Lmft_Law_Ethics_Sync' ) ) {
					$result = CTA_Lmft_Law_Ethics_Sync::sync_workbook_banks_missing( 0, 2 );
					if ( empty( $result['ok'] ) && ! empty( $result['remaining'] ) ) {
						self::queue( 'lmft_law_ethics_workbook_banks' );
					}
				}
				break;

			case 'lmft_law_ethics_practice_exams':
				if ( class_exists( 'CTA_Lmft_Law_Ethics_Sync' ) ) {
					$result = CTA_Lmft_Law_Ethics_Sync::sync_practice_exams_missing( 0, 1 );
					if ( empty( $result['ok'] ) && ! empty( $result['remaining'] ) ) {
						self::queue( 'lmft_law_ethics_practice_exams' );
					}
				}
				break;

			case 'lmft_clinical_form_a':
				if ( class_exists( 'CTA_Lmft_Clinical_Legacy_Forms_Archive' ) ) {
					CTA_Lmft_Clinical_Legacy_Forms_Archive::archive_non_final_active_forms(
						CTA_Lmft_Clinical_Legacy_Forms_Archive::TARGET_COURSE_ID,
						true
					);
					CTA_Lmft_Clinical_Legacy_Forms_Archive::purge_archived_duplicate_form_quizzes(
						CTA_Lmft_Clinical_Legacy_Forms_Archive::TARGET_COURSE_ID,
						true
					);
				}
				if ( class_exists( 'CTA_Lmft_Clinical_Form_A_Sync' ) ) {
					CTA_Lmft_Clinical_Form_A_Sync::sync( true );
				}
				if ( class_exists( 'CTA_Lmft_Clinical_Form_A_Answer_Sync' ) ) {
					CTA_Lmft_Clinical_Form_A_Answer_Sync::sync_answer_keys( true );
				}
				break;

			case 'lmft_amftrb_workbook_banks':
				if ( class_exists( 'CTA_Lmft_Amftrb_Sync' ) ) {
					CTA_Lmft_Amftrb_Sync::ensure_learner_forms( 0, true );
				}
				break;

			case 'lpcc_ncmhce_form_a':
				if ( class_exists( 'CTA_Lpcc_Ncmhce_Form_A_Sync' ) ) {
					CTA_Lpcc_Ncmhce_Form_A_Sync::sync( true );
				}
				break;

			case 'lpcc_ncmhce_form_b':
				if ( class_exists( 'CTA_Lpcc_Ncmhce_Form_B_Sync' ) ) {
					CTA_Lpcc_Ncmhce_Form_B_Sync::sync( true );
				}
				break;

			case 'lpcc_ncmhce_forms_ab':
				self::queue( 'lpcc_ncmhce_form_a' );
				self::queue( 'lpcc_ncmhce_form_b' );
				break;

			case 'content_law_ethics_ce':
				if ( class_exists( 'CTA_Law_Ethics_Module_Sync' ) ) {
					CTA_Law_Ethics_Module_Sync::sync_modules( true );
				}
				if ( class_exists( 'CTA_Law_Ethics_Exam_Sync' ) ) {
					CTA_Law_Ethics_Exam_Sync::sync( true );
				}
				if ( class_exists( 'CTA_Law_Ethics_Evaluation_Sync' ) ) {
					CTA_Law_Ethics_Evaluation_Sync::sync( true );
				}
				break;

			case 'content_telehealth':
				if ( class_exists( 'CTA_Telehealth_Exam_Sync' ) ) {
					CTA_Telehealth_Exam_Sync::sync( true );
					CTA_Telehealth_Exam_Sync::sync_module_videos( true );
					CTA_Telehealth_Exam_Sync::sync_thumbnail( true );
				}
				break;

			case 'content_suicide_risk':
				if ( class_exists( 'CTA_Suicide_Risk_Module_Sync' ) ) {
					CTA_Suicide_Risk_Module_Sync::sync_modules( true );
				}
				if ( class_exists( 'CTA_Suicide_Risk_Toolkit_Sync' ) ) {
					CTA_Suicide_Risk_Toolkit_Sync::sync( true );
				}
				if ( class_exists( 'CTA_Suicide_Risk_Exam_Sync' ) ) {
					CTA_Suicide_Risk_Exam_Sync::sync( true );
				}
				if ( class_exists( 'CTA_Suicide_Risk_Evaluation_Sync' ) ) {
					CTA_Suicide_Risk_Evaluation_Sync::sync( true );
				}
				if ( class_exists( 'CTA_Suicide_Risk_Certificate_Sync' ) ) {
					CTA_Suicide_Risk_Certificate_Sync::sync( true );
				}
				break;

			case 'content_lmft_law_ethics':
				if ( class_exists( 'CTA_Lmft_Law_Ethics_Sync' ) ) {
					CTA_Lmft_Law_Ethics_Sync::sync( true );
					if ( method_exists( 'CTA_Lmft_Law_Ethics_Sync', 'sync_materials' ) ) {
						CTA_Lmft_Law_Ethics_Sync::sync_materials( true );
					}
					if ( method_exists( 'CTA_Lmft_Law_Ethics_Sync', 'sync_toolkits' ) ) {
						CTA_Lmft_Law_Ethics_Sync::sync_toolkits( true );
					}
					if ( method_exists( 'CTA_Lmft_Law_Ethics_Sync', 'ensure_workbook_banks' ) ) {
						CTA_Lmft_Law_Ethics_Sync::ensure_workbook_banks( 0, false );
					}
					if ( method_exists( 'CTA_Lmft_Law_Ethics_Sync', 'ensure_practice_exams' ) ) {
						CTA_Lmft_Law_Ethics_Sync::ensure_practice_exams( 0, false );
					}
				}
				break;

			case 'content_lcsw_law_ethics':
				if ( class_exists( 'CTA_Lcsw_Law_Ethics_Sync' ) ) {
					CTA_Lcsw_Law_Ethics_Sync::sync( true );
				}
				break;

			case 'content_lpcc_law_ethics':
				if ( class_exists( 'CTA_Lpcc_Law_Ethics_Sync' ) ) {
					CTA_Lpcc_Law_Ethics_Sync::sync( true );
				}
				break;

			case 'content_lcsw_aswb':
				if ( class_exists( 'CTA_Lcsw_Aswb_Sync' ) ) {
					CTA_Lcsw_Aswb_Sync::sync( true );
				}
				break;

			case 'content_lmft_clinical':
				if ( class_exists( 'CTA_Lmft_Clinical_Sync' ) ) {
					CTA_Lmft_Clinical_Sync::sync( true );
				}
				break;

			case 'content_lmft_amftrb':
				if ( class_exists( 'CTA_Lmft_Amftrb_Sync' ) ) {
					CTA_Lmft_Amftrb_Sync::sync( true );
				}
				break;

			case 'content_lpcc_ncmhce':
				if ( class_exists( 'CTA_Lpcc_Ncmhce_Sync' ) ) {
					CTA_Lpcc_Ncmhce_Sync::sync( true );
				}
				break;

			case 'content_materials':
				if ( class_exists( 'CTA_Course_Materials' ) ) {
					CTA_Course_Materials::ensure_bundled_resources();
				}
				break;

			case 'ce_catalog_materials_heal':
				if ( class_exists( 'CTA_Course_Materials' ) ) {
					$result = CTA_Course_Materials::heal_ce_catalog_material_mapping( 100 );
					if ( empty( $result['ok'] ) && ! empty( $result['remaining'] ) ) {
						self::queue( 'ce_catalog_materials_heal' );
					} else {
						CTA_Course_Materials::ensure_bundled_resources();
						if ( class_exists( 'CTA_Suicide_Risk_Toolkit_Sync' ) && method_exists( 'CTA_Suicide_Risk_Toolkit_Sync', 'ensure' ) ) {
							CTA_Suicide_Risk_Toolkit_Sync::ensure();
						}
						update_option( CTA_Course_Materials::CE_MATERIALS_HEAL_OPTION, $result, false );
					}
				}
				break;

			case 'lpcc_ncmhce_form_a_v2':
				if ( class_exists( 'CTA_Lpcc_Ncmhce_Form_A_V2_Sync' ) ) {
					CTA_Lpcc_Ncmhce_Form_A_V2_Sync::sync( true );
				}
				if ( class_exists( 'CTA_Lpcc_Ncmhce_Form_A_V2_Answer_Sync' ) ) {
					CTA_Lpcc_Ncmhce_Form_A_V2_Answer_Sync::sync_answer_keys( true );
				}
				break;

			case 'lpcc_ncmhce_form_b_v2':
				if ( class_exists( 'CTA_Lpcc_Ncmhce_Form_B_V2_Sync' ) ) {
					CTA_Lpcc_Ncmhce_Form_B_V2_Sync::sync( true );
				}
				if ( class_exists( 'CTA_Lpcc_Ncmhce_Form_B_V2_Answer_Sync' ) ) {
					CTA_Lpcc_Ncmhce_Form_B_V2_Answer_Sync::sync_answer_keys( true );
				}
				break;

			default:
				break;
		}
	}
}

}
