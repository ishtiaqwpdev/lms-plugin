<?php
/**
 * CE course materials ownership repair and learner-facing filtering.
 *
 * Ensures each CE course displays only materials approved for that course.
 * Misassigned exam-prep or cross-CE rows are reassigned by course_id (files kept).
 *
 * @package CTA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CTA_CE_Materials_Sync
 */
if ( ! class_exists( 'CTA_CE_Materials_Sync' ) ) {

class CTA_CE_Materials_Sync {

	const REPAIR_OPTION = 'cta_ce_materials_repair_1_0_313';
	const AUDIT_OPTION  = 'cta_ce_materials_audit_report';

	/**
	 * Bundled CE material signatures keyed by course_code.
	 *
	 * @return array<string,array{resource_keys:array,title_patterns:array,path_patterns:array}>
	 */
	public static function get_bundled_ce_signatures() {
		return array(
			'CTA-CE-001' => array(
				'resource_keys'   => array(
					'cta_ce_001_final_syllabus_v2_1',
					'cta_ce_001_practice_protection_toolkit_v1',
				),
				'title_patterns'  => array(
					'/final\s+syllabus/i',
					'/practice\s+protection\s+toolkit/i',
					'/california\s+law\s*&?\s*ethics\s+practice\s+protection/i',
				),
				'path_patterns'   => array(
					'cta_ce_001',
					'final_syllabus_v2_1',
					'practice_protection_toolkit',
					'california_law_ethics_practice_protection',
				),
			),
			'CTA-CE-002' => array(
				'resource_keys'   => array(
					'telehealth_clinical_resource_toolkit_v2',
				),
				'title_patterns'  => array(
					'/telehealth\s+clinical\s+resource\s+toolkit/i',
				),
				'path_patterns'   => array(
					'telehealth_clinical_resource_toolkit',
					'cta_telehealth',
				),
			),
			'CTA-CE-003' => array(
				'resource_keys'   => array(
					'suicide_risk_learner_resource_toolkit_v1_1',
				),
				'title_patterns'  => array(
					'/learner\s+resource\s+toolkit/i',
					'/suicide\s+risk.*toolkit/i',
				),
				'path_patterns'   => array(
					'suicide_risk_learner_resource_toolkit',
					'cta_suicide_risk',
					'suicide-risk-ce',
				),
			),
		);
	}

	/**
	 * Exam-prep program path markers → exam prep catalog slug.
	 *
	 * @return array<string,string>
	 */
	public static function get_exam_prep_path_slug_map() {
		return array(
			'lmft-law-ethics'   => 'california-law-ethics-exam-preparation',
			'lpcc-law-ethics'   => 'lpcc-california-law-ethics-exam-preparation',
			'lcsw-law-ethics'   => 'lcsw-california-law-ethics-exam-preparation',
			'lmft-amftrb'       => 'lmft-amftrb-national-exam-preparation',
			'lmft-clinical'     => 'lmft-california-clinical-exam-preparation',
			'lcsw-aswb'         => 'lcsw-aswb-clinical-exam-preparation',
			'lpcc-ncmhce'       => 'lpcc-ncmhce-exam-preparation',
		);
	}

	/**
	 * All published/draft CE course rows.
	 *
	 * @return object[]
	 */
	public static function get_ce_courses() {
		global $wpdb;

		if ( ! class_exists( 'CTA_CE_Access' ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'cta_courses';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" );
		$out   = array();

		foreach ( (array) $rows as $row ) {
			if ( CTA_CE_Access::is_ce_course( $row ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Resolve CTA-CE course code for a course row.
	 *
	 * @param object|null $course Course row.
	 * @return string
	 */
	public static function get_course_code( $course ) {
		if ( ! $course ) {
			return '';
		}

		if ( ! empty( $course->syllabus_meta ) ) {
			$decoded = json_decode( (string) $course->syllabus_meta, true );
			if ( is_array( $decoded ) && ! empty( $decoded['course_code'] ) ) {
				return (string) $decoded['course_code'];
			}
		}

		if ( class_exists( 'CTA_Course_Catalog' ) ) {
			$code_by_index = array(
				0 => 'CTA-CE-001',
				1 => 'CTA-CE-002',
				2 => 'CTA-CE-003',
			);
			foreach ( (array) CTA_Course_Catalog::get_ce_catalog() as $index => $entry ) {
				$titles = isset( $entry['match_titles'] ) ? (array) $entry['match_titles'] : array( $entry['title'] ?? '' );
				foreach ( $titles as $title ) {
					$title = trim( (string) $title );
					if ( '' === $title ) {
						continue;
					}
					$row_title = trim( (string) $course->title );
					if ( 0 === strcasecmp( $row_title, $title ) || false !== stripos( $row_title, $title ) ) {
						return isset( $code_by_index[ $index ] ) ? (string) $code_by_index[ $index ] : '';
					}
				}
			}
		}

		return '';
	}

	/**
	 * Normalize resource path/title haystack for signature checks.
	 *
	 * @param object|null $resource Resource row.
	 * @return string
	 */
	public static function resource_haystack( $resource ) {
		if ( ! $resource ) {
			return '';
		}

		return strtolower(
			trim(
				(string) ( $resource->file_path ?? '' ) . ' ' .
				(string) ( $resource->file_url ?? '' ) . ' ' .
				(string) ( $resource->title ?? '' )
			)
		);
	}

	/**
	 * Whether a resource matches a bundled CE signature set.
	 *
	 * @param object      $resource Resource row.
	 * @param string|null $course_code Optional CTA-CE code.
	 * @return bool
	 */
	public static function matches_bundled_ce_signature( $resource, $course_code = null ) {
		$haystack = self::resource_haystack( $resource );
		if ( '' === $haystack ) {
			return false;
		}

		$sets = self::get_bundled_ce_signatures();
		if ( null !== $course_code && isset( $sets[ $course_code ] ) ) {
			$sets = array( $course_code => $sets[ $course_code ] );
		}

		foreach ( $sets as $sig ) {
			foreach ( (array) ( $sig['path_patterns'] ?? array() ) as $needle ) {
				if ( '' !== $needle && false !== strpos( $haystack, strtolower( (string) $needle ) ) ) {
					return true;
				}
			}
			foreach ( (array) ( $sig['title_patterns'] ?? array() ) as $pattern ) {
				if ( '' !== $pattern && preg_match( (string) $pattern, (string) ( $resource->title ?? '' ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Resolve bundled CE course_code owner for a resource (empty if none).
	 *
	 * @param object $resource Resource row.
	 * @return string
	 */
	public static function resolve_bundled_ce_owner_code( $resource ) {
		foreach ( self::get_bundled_ce_signatures() as $code => $sig ) {
			$haystack = self::resource_haystack( $resource );
			foreach ( (array) ( $sig['path_patterns'] ?? array() ) as $needle ) {
				if ( '' !== $needle && false !== strpos( $haystack, strtolower( (string) $needle ) ) ) {
					return (string) $code;
				}
			}
			foreach ( (array) ( $sig['title_patterns'] ?? array() ) as $pattern ) {
				if ( '' !== $pattern && preg_match( (string) $pattern, (string) ( $resource->title ?? '' ) ) ) {
					return (string) $code;
				}
			}
		}

		return '';
	}

	/**
	 * Whether a downloadable resource is exam-prep content.
	 *
	 * @param object|null $resource Resource row.
	 * @return bool
	 */
	public static function is_exam_prep_resource( $resource ) {
		if ( ! $resource ) {
			return false;
		}

		if ( ! empty( $resource->is_practice_test ) ) {
			return true;
		}

		$haystack = self::resource_haystack( $resource );
		if ( '' === $haystack ) {
			return false;
		}

		foreach ( array_keys( self::get_exam_prep_path_slug_map() ) as $marker ) {
			if ( false !== strpos( $haystack, $marker ) ) {
				return true;
			}
		}

		$title = (string) ( $resource->title ?? '' );
		$patterns = array(
			'/candidate\s+edition/i',
			'/practice\s+examination/i',
			'/comprehensive\s+final/i',
			'/form\s+[ab]\b/i',
			'/workbook\s+\d+/i',
			'/audio\s+review/i',
			'/authoritative\s+audio/i',
			'/answer\s+key/i',
			'/rationales/i',
			'/remediation\s+workbook/i',
			'/study\s+schedule/i',
			'/flashcard/i',
			'/CTA-EP-/i',
			'/exam\s+preparation\s+program/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $title ) || preg_match( $pattern, $haystack ) ) {
				return true;
			}
		}

		$unlock = isset( $resource->unlock_after_quiz_type ) ? sanitize_text_field( (string) $resource->unlock_after_quiz_type ) : '';
		if ( preg_match( '/^(form_[ab]|wb\d+_bank|checkpoint_\d+|form_a_remediation)$/i', $unlock ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Find CE course row by CTA-CE course code.
	 *
	 * @param string $course_code Course code.
	 * @return object|null
	 */
	public static function find_ce_course_by_code( $course_code ) {
		$course_code = trim( (string) $course_code );
		if ( '' === $course_code ) {
			return null;
		}

		foreach ( self::get_ce_courses() as $course ) {
			if ( self::get_course_code( $course ) === $course_code ) {
				return $course;
			}
		}

		if ( 'CTA-CE-001' === $course_code && class_exists( 'CTA_Law_Ethics_Module_Sync' ) ) {
			return CTA_Law_Ethics_Module_Sync::find_course();
		}
		if ( 'CTA-CE-002' === $course_code && class_exists( 'CTA_Telehealth_Exam_Sync' ) && method_exists( 'CTA_Telehealth_Exam_Sync', 'find_course' ) ) {
			return CTA_Telehealth_Exam_Sync::find_course();
		}
		if ( 'CTA-CE-003' === $course_code && class_exists( 'CTA_Suicide_Risk_Module_Sync' ) ) {
			return CTA_Suicide_Risk_Module_Sync::find_course();
		}

		return null;
	}

	/**
	 * Find exam prep course row by catalog slug.
	 *
	 * @param string $slug Exam prep slug.
	 * @return object|null
	 */
	public static function find_exam_prep_course_by_slug( $slug ) {
		global $wpdb;

		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug ) {
			return null;
		}

		$table = $wpdb->prefix . 'cta_courses';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE slug = %s LIMIT 1",
				$slug
			)
		);

		if ( $row && class_exists( 'CTA_Exam_Access' ) && CTA_Exam_Access::is_exam_prep( $row ) ) {
			return $row;
		}

		return null;
	}

	/**
	 * Resolve the course_id that should own a resource row.
	 *
	 * @param object $resource Resource row.
	 * @return int 0 when unknown.
	 */
	public static function resolve_resource_owner_course_id( $resource ) {
		$owner_code = self::resolve_bundled_ce_owner_code( $resource );
		if ( '' !== $owner_code ) {
			$owner = self::find_ce_course_by_code( $owner_code );
			return $owner ? (int) $owner->id : 0;
		}

		if ( ! self::is_exam_prep_resource( $resource ) ) {
			return 0;
		}

		$haystack = self::resource_haystack( $resource );
		foreach ( self::get_exam_prep_path_slug_map() as $marker => $slug ) {
			if ( false !== strpos( $haystack, $marker ) ) {
				$owner = self::find_exam_prep_course_by_slug( $slug );
				return $owner ? (int) $owner->id : 0;
			}
		}

		return 0;
	}

	/**
	 * Whether a resource should display on a CE course materials list.
	 *
	 * @param int    $course_id CE course ID.
	 * @param object $resource  Resource row.
	 * @return bool
	 */
	public static function resource_belongs_to_ce_course( $course_id, $resource ) {
		$course_id = absint( $course_id );
		if ( ! $course_id || ! $resource ) {
			return false;
		}

		if ( (int) ( $resource->course_id ?? 0 ) !== $course_id ) {
			return false;
		}

		if ( self::is_exam_prep_resource( $resource ) ) {
			return false;
		}

		$course = class_exists( 'CTA_Database' ) ? CTA_Database::get_course( $course_id ) : null;
		if ( ! $course || ! class_exists( 'CTA_CE_Access' ) || ! CTA_CE_Access::is_ce_course( $course ) ) {
			return false;
		}

		$course_code = self::get_course_code( $course );
		$owner_code  = self::resolve_bundled_ce_owner_code( $resource );

		if ( '' !== $owner_code && '' !== $course_code && $owner_code !== $course_code ) {
			return false;
		}

		if ( '' !== $owner_code && '' === $course_code ) {
			return false;
		}

		return true;
	}

	/**
	 * Filter CE course resources for learner display.
	 *
	 * @param int   $course_id CE course ID.
	 * @param array $resources Resource rows.
	 * @return array
	 */
	public static function filter_ce_course_resources( $course_id, $resources ) {
		$course_id = absint( $course_id );
		$out       = array();

		foreach ( (array) $resources as $resource ) {
			if ( self::resource_belongs_to_ce_course( $course_id, $resource ) ) {
				$out[] = $resource;
			}
		}

		return $out;
	}

	/**
	 * Find duplicate resource on a course by file_path or title.
	 *
	 * @param int    $course_id   Course ID.
	 * @param object $resource    Resource row.
	 * @param int    $exclude_id  Resource ID to exclude.
	 * @return int
	 */
	private static function find_duplicate_resource_id( $course_id, $resource, $exclude_id = 0 ) {
		global $wpdb;

		$course_id  = absint( $course_id );
		$exclude_id = absint( $exclude_id );
		$path       = trim( (string) ( $resource->file_path ?? '' ) );
		$title      = trim( (string) ( $resource->title ?? '' ) );
		$table      = $wpdb->prefix . 'cta_downloadable_resources';

		if ( '' !== $path ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE course_id = %d AND file_path = %s AND id != %d LIMIT 1",
					$course_id,
					$path,
					$exclude_id
				)
			);
			if ( $existing ) {
				return $existing;
			}
		}

		if ( '' !== $title ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE course_id = %d AND title = %s AND id != %d LIMIT 1",
					$course_id,
					$title,
					$exclude_id
				)
			);
			if ( $existing ) {
				return $existing;
			}
		}

		return 0;
	}

	/**
	 * Reassign or dedupe a mislinked resource row.
	 *
	 * @param object $resource     Resource row.
	 * @param int    $owner_id     Target course ID.
	 * @return array{action:string,resource_id:int,from_course_id:int,to_course_id:int}
	 */
	private static function reassign_resource( $resource, $owner_id ) {
		global $wpdb;

		$resource_id     = (int) $resource->id;
		$from_course_id  = (int) ( $resource->course_id ?? 0 );
		$owner_id        = absint( $owner_id );
		$table           = $wpdb->prefix . 'cta_downloadable_resources';
		$result          = array(
			'action'          => 'skipped',
			'resource_id'     => $resource_id,
			'from_course_id'  => $from_course_id,
			'to_course_id'    => $owner_id,
		);

		if ( ! $resource_id || ! $owner_id || $from_course_id === $owner_id ) {
			return $result;
		}

		$duplicate_id = self::find_duplicate_resource_id( $owner_id, $resource, $resource_id );
		if ( $duplicate_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, array( 'id' => $resource_id ), array( '%d' ) );
			$result['action'] = 'removed_duplicate_row';
			return $result;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'course_id' => $owner_id ),
			array( 'id' => $resource_id ),
			array( '%d' ),
			array( '%d' )
		);
		$result['action'] = 'reassigned';

		return $result;
	}

	/**
	 * Repair mislinked materials for one CE course.
	 *
	 * @param int $course_id CE course ID.
	 * @return array
	 */
	public static function repair_ce_course( $course_id ) {
		$course_id = absint( $course_id );
		$report    = array(
			'course_id' => $course_id,
			'title'     => '',
			'actions'   => array(),
		);

		if ( ! $course_id || ! class_exists( 'CTA_Database' ) ) {
			return $report;
		}

		$course = CTA_Database::get_course( $course_id );
		if ( ! $course || ! class_exists( 'CTA_CE_Access' ) || ! CTA_CE_Access::is_ce_course( $course ) ) {
			return $report;
		}

		$report['title'] = (string) $course->title;

		foreach ( (array) CTA_Database::get_downloadable_resources( $course_id ) as $resource ) {
			if ( self::resource_belongs_to_ce_course( $course_id, $resource ) ) {
				continue;
			}

			$owner_id = self::resolve_resource_owner_course_id( $resource );
			if ( ! $owner_id ) {
				$report['actions'][] = array(
					'action'       => 'unresolved',
					'resource_id'  => (int) $resource->id,
					'title'        => (string) ( $resource->title ?? '' ),
					'file_path'    => (string) ( $resource->file_path ?? '' ),
					'reason'       => self::is_exam_prep_resource( $resource ) ? 'exam_prep_unmapped' : 'foreign_ce_or_unknown',
				);
				continue;
			}

			$report['actions'][] = array_merge(
				array(
					'title'     => (string) ( $resource->title ?? '' ),
					'file_path' => (string) ( $resource->file_path ?? '' ),
				),
				self::reassign_resource( $resource, $owner_id )
			);
		}

		if ( class_exists( 'CTA_Course_Materials' ) ) {
			CTA_Course_Materials::ensure_bundled_resources();
		}
		if ( class_exists( 'CTA_Suicide_Risk_Toolkit_Sync' ) && self::get_course_code( $course ) === 'CTA-CE-003' ) {
			CTA_Suicide_Risk_Toolkit_Sync::ensure();
		}

		return $report;
	}

	/**
	 * Repair all CE courses (idempotent).
	 *
	 * @param bool $force Re-run even if already seeded.
	 * @return array
	 */
	public static function repair_all_ce_courses( $force = false ) {
		if ( ! $force && get_option( self::REPAIR_OPTION ) ) {
			return array(
				'ok'      => true,
				'message' => 'already_repaired',
				'courses' => array(),
			);
		}

		$courses = array();
		foreach ( self::get_ce_courses() as $course ) {
			$courses[] = self::repair_ce_course( (int) $course->id );
		}

		$audit = self::audit_all_ce_courses();
		update_option( self::REPAIR_OPTION, array( 'at' => current_time( 'mysql' ) ), false );
		update_option( self::AUDIT_OPTION, $audit, false );

		return array(
			'ok'      => true,
			'message' => 'repaired',
			'courses' => $courses,
			'audit'   => $audit,
		);
	}

	/**
	 * Throttled self-heal when a learner opens a CE course.
	 *
	 * @param int $course_id CE course ID.
	 * @return void
	 */
	public static function maybe_repair_ce_course( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return;
		}

		$lock_key = 'cta_ce_materials_heal_' . $course_id;
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );

		self::repair_ce_course( $course_id );
	}

	/**
	 * Audit CE course materials after filtering/repair rules.
	 *
	 * @return array
	 */
	public static function audit_all_ce_courses() {
		$report = array(
			'generated_at' => current_time( 'mysql' ),
			'courses'      => array(),
		);

		foreach ( self::get_ce_courses() as $course ) {
			$course_id   = (int) $course->id;
			$course_code = self::get_course_code( $course );
			$raw         = class_exists( 'CTA_Database' ) ? (array) CTA_Database::get_downloadable_resources( $course_id ) : array();
			if ( class_exists( 'CTA_Course_Materials' ) ) {
				$raw = CTA_Course_Materials::filter_student_visible_resources( $raw );
			}
			$visible = self::filter_ce_course_resources( $course_id, $raw );

			$hidden = array();
			foreach ( $raw as $resource ) {
				if ( ! self::resource_belongs_to_ce_course( $course_id, $resource ) ) {
					$hidden[] = array(
						'id'        => (int) $resource->id,
						'title'     => (string) ( $resource->title ?? '' ),
						'file_path' => (string) ( $resource->file_path ?? '' ),
						'reason'    => self::is_exam_prep_resource( $resource ) ? 'exam_prep' : 'foreign_ce_or_unknown',
					);
				}
			}

			$materials = array();
			foreach ( $visible as $resource ) {
				$materials[] = array(
					'id'    => (int) $resource->id,
					'title' => (string) ( $resource->title ?? '' ),
					'type'  => (string) ( $resource->file_type ?? '' ),
				);
			}

			$report['courses'][] = array(
				'course_id'   => $course_id,
				'course_code' => $course_code,
				'title'       => (string) $course->title,
				'materials'   => $materials,
				'hidden'      => $hidden,
				'status'      => empty( $hidden ) ? 'clean' : 'filtered_or_pending_repair',
				'verified'    => empty( $hidden ),
			);
		}

		return $report;
	}
}

}
