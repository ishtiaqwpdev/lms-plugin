<?php
/**
 * Exam Prep Flashcard Study Center — blueprint-aligned deck loader.
 *
 * Separate from the legacy CTA_Flashcards viewer (workbook/materials embed).
 * Decks live in per-program flashcard-study-center.json files.
 *
 * @package CTA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CTA_Exam_Prep_Flashcard_Center
 */
if ( ! class_exists( 'CTA_Exam_Prep_Flashcard_Center' ) ) {

class CTA_Exam_Prep_Flashcard_Center {

	/**
	 * Course slug → relative JSON path under the plugin directory.
	 *
	 * @return array<string,string>
	 */
	public static function get_deck_path_map() {
		$map = array(
			'california-law-ethics-exam-preparation'      => 'assets/course-materials/lmft-law-ethics/study-tools/flashcard-study-center.json',
			'lmft-california-clinical-exam-preparation'   => 'assets/course-materials/lmft-clinical/study-tools/flashcard-study-center.json',
			'lmft-amftrb-national-exam-preparation'       => 'assets/course-materials/lmft-amftrb/study-tools/flashcard-study-center.json',
			'lcsw-aswb-clinical-exam-preparation'         => 'assets/course-materials/lcsw-aswb/study-tools/flashcard-study-center.json',
			'lcsw-california-clinical-exam-preparation'   => 'assets/course-materials/lcsw-aswb/study-tools/flashcard-study-center.json',
			'lcsw-california-law-ethics-exam-preparation' => 'assets/course-materials/lcsw-law-ethics/study-tools/flashcard-study-center.json',
			'lpcc-ncmhce-exam-preparation'                => 'assets/course-materials/lpcc-ncmhce/study-tools/flashcard-study-center.json',
			'lpcc-california-clinical-exam-preparation'   => 'assets/course-materials/lpcc-ncmhce/study-tools/flashcard-study-center.json',
			'lpcc-california-law-ethics-exam-preparation' => 'assets/course-materials/lpcc-law-ethics/study-tools/flashcard-study-center.json',
		);

		/**
		 * Filter deck JSON paths for exam-prep Flashcard Study Center.
		 *
		 * @param array<string,string> $map Course slug → relative path.
		 */
		return apply_filters( 'cta_exam_prep_flashcard_study_center_paths', $map );
	}

	/**
	 * Course slugs allowed to reuse legacy flashcards.json when Study Center JSON is empty.
	 *
	 * Programs with an approved blueprint-aligned Study Center deck must NOT appear here.
	 *
	 * @return array<int,string>
	 */
	public static function get_legacy_fallback_slugs() {
		$slugs = array(
			'lpcc-california-law-ethics-exam-preparation',
			'lcsw-california-law-ethics-exam-preparation',
		);

		// When the approved Study Center deck is live, never fall back to legacy JSON.
		if ( self::study_center_deck_is_live( 'lcsw-aswb' ) ) {
			$slugs = array_values(
				array_diff(
					$slugs,
					array(
						'lcsw-aswb-clinical-exam-preparation',
						'lcsw-california-clinical-exam-preparation',
					)
				)
			);
		}

		if ( self::study_center_deck_is_live( 'lpcc-ncmhce' ) ) {
			$slugs = array_values(
				array_diff(
					$slugs,
					array(
						'lpcc-ncmhce-exam-preparation',
						'lpcc-california-clinical-exam-preparation',
					)
				)
			);
		}

		if ( self::study_center_deck_is_live( 'lmft-amftrb' ) ) {
			$slugs = array_values(
				array_diff(
					$slugs,
					array(
						'lmft-amftrb-national-exam-preparation',
					)
				)
			);
		}

		/**
		 * Filter slugs that may fall back to legacy flashcards.json decks.
		 *
		 * @param array<int,string> $slugs Course slugs.
		 */
		return apply_filters( 'cta_exam_prep_flashcard_study_center_legacy_fallback_slugs', $slugs );
	}

	/**
	 * Whether a program's flashcard-study-center.json has the approved live deck.
	 *
	 * @param string $program_key Program materials folder key (e.g. lcsw-aswb).
	 * @return bool
	 */
	public static function study_center_deck_is_live( $program_key ) {
		$program_key = sanitize_key( (string) $program_key );
		if ( '' === $program_key ) {
			return false;
		}

		$path = CTA_PLUGIN_DIR . 'assets/course-materials/' . $program_key . '/study-tools/flashcard-study-center.json';
		$data = self::read_deck_file( $path );
		if ( ! is_array( $data ) || empty( $data['cards'] ) || ! is_array( $data['cards'] ) ) {
			return false;
		}

		$expected = isset( $data['expected_total'] ) ? (int) $data['expected_total'] : 180;
		if ( $expected < 1 ) {
			$expected = 180;
		}

		return count( $data['cards'] ) >= $expected;
	}

	/**
	 * Course slugs that must use the approved Study Center JSON only — never quiz-bank fallback.
	 *
	 * Quiz-bank fallback incorrectly treats Form A/B practice exams as flashcard domains.
	 *
	 * @param string $slug Course slug.
	 * @return bool
	 */
	public static function program_requires_study_center_deck( $slug ) {
		$slug = sanitize_title( (string) $slug );
		$slugs = array(
			'lmft-amftrb-national-exam-preparation',
			'lmft-california-clinical-exam-preparation',
			'lpcc-ncmhce-exam-preparation',
			'lpcc-california-clinical-exam-preparation',
			'lcsw-aswb-clinical-exam-preparation',
			'lcsw-california-clinical-exam-preparation',
		);

		/**
		 * Filter slugs that must not use quiz-bank flashcard fallback.
		 *
		 * @param array<int,string> $slugs Course slugs.
		 */
		$slugs = apply_filters( 'cta_exam_prep_flashcard_study_center_required_slugs', $slugs );

		if ( in_array( $slug, $slugs, true ) ) {
			return true;
		}

		// Any course with a mapped Study Center JSON path must never use quiz-bank mixing.
		$map = self::get_deck_path_map();
		return isset( $map[ $slug ] ) && false !== strpos( (string) $map[ $slug ], 'flashcard-study-center.json' );
	}

	/**
	 * Domain / quiz_type keys that belong to Practice Exams, never Flashcard Study Center.
	 *
	 * @return string[]
	 */
	public static function practice_exam_domain_keys() {
		$keys = array(
			'form_a',
			'form_b',
			'form-a',
			'form-b',
			'practice_a',
			'practice_b',
			'comprehensive_final',
			'checkpoint_1',
			'checkpoint_2',
			'checkpoint_3',
			'legacy_form_a',
			'legacy_form_b',
			'form_a_v2',
			'form_b_v2',
		);

		if ( class_exists( 'CTA_Exam_Prep_Workbooks' ) ) {
			$keys = array_merge(
				$keys,
				CTA_Exam_Prep_Workbooks::program_level_quiz_types()
			);
		}

		return array_values( array_unique( array_map( 'sanitize_key', $keys ) ) );
	}

	/**
	 * Remove Practice Exam / Checkpoint / Form cards that must never appear
	 * in Flashcard Study Center (even if a bad deck file mixes them in).
	 *
	 * @param array<int,array<string,mixed>> $cards Normalized cards.
	 * @return array<int,array<string,mixed>>
	 */
	public static function strip_practice_exam_cards( array $cards ) {
		$blocked = self::practice_exam_domain_keys();
		$out     = array();

		foreach ( $cards as $card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}
			$key   = sanitize_key( (string) ( $card['domain'] ?? '' ) );
			$label = strtolower( trim( (string) ( $card['domain_label'] ?? '' ) ) );
			if ( '' === $label && isset( $card['domain'] ) ) {
				$label = strtolower( str_replace( array( '-', '_' ), ' ', (string) $card['domain'] ) );
			}

			if ( in_array( $key, $blocked, true ) ) {
				continue;
			}
			if ( preg_match( '/^checkpoint/', $key ) || preg_match( '/\bcheckpoint\b/', $label ) ) {
				continue;
			}
			if ( preg_match( '/^form\s*[ab]\b/', $label ) || preg_match( '/comprehensive\s+simulation/', $label ) ) {
				continue;
			}
			$out[] = $card;
		}

		return $out;
	}

	/**
	 * Remove cards whose domain is a workbook practice-bank quiz type / label.
	 * Used only for programs that ship an approved blueprint Study Center deck.
	 *
	 * @param array<int,array<string,mixed>> $cards Normalized cards.
	 * @return array<int,array<string,mixed>>
	 */
	public static function strip_workbook_bank_domain_cards( array $cards ) {
		$out = array();

		foreach ( $cards as $card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}
			$key   = sanitize_key( (string) ( $card['domain'] ?? '' ) );
			$label = strtolower( trim( (string) ( $card['domain_label'] ?? '' ) ) );
			if ( '' === $label && isset( $card['domain'] ) ) {
				$label = strtolower( str_replace( array( '-', '_' ), ' ', (string) $card['domain'] ) );
			}

			if ( preg_match( '/^wb\d+_?bank$/', $key ) || preg_match( '/^wb\s*\d+\s*bank\b/', $label ) ) {
				continue;
			}
			$out[] = $card;
		}

		return $out;
	}

	/**
	 * Default empty deck payload for a course.
	 *
	 * @param object|null $course Course row.
	 * @return array<string,mixed>
	 */
	public static function get_empty_deck( $course = null ) {
		$title = __( 'Flashcard Study Center', 'cta-lms' );
		if ( $course && function_exists( 'cta_lms_get_course_display_title' ) ) {
			$title = sprintf(
				/* translators: %s: program display title */
				__( '%s — Flashcard Study Center', 'cta-lms' ),
				cta_lms_get_course_display_title( $course )
			);
		}

		return array(
			'title'       => $title,
			'count'       => 0,
			'cards'       => array(),
			'domains'     => array(),
			'has_content' => false,
		);
	}

	/**
	 * Resolve Flashcard Study Center deck for a course.
	 *
	 * @param object|null $course Course row.
	 * @return array<string,mixed>
	 */
	public static function get_deck_for_course( $course ) {
		if ( ! $course || empty( $course->slug ) ) {
			return self::get_empty_deck( $course );
		}

		if ( class_exists( 'CTA_Exam_Access' ) && ! CTA_Exam_Access::is_exam_prep( $course ) ) {
			return self::get_empty_deck( $course );
		}

		$map  = self::get_deck_path_map();
		$slug = sanitize_title( (string) $course->slug );
		$data = null;
		if ( isset( $map[ $slug ] ) ) {
			$path = CTA_PLUGIN_DIR . ltrim( $map[ $slug ], '/' );
			$data = self::read_deck_file( $path );
		}

		// Some programs ship a dedicated Study Center deck that must remain
		// separate from the legacy printable/interactive flashcards.json library.
		$legacy_fallback_slugs = self::get_legacy_fallback_slugs();
		if (
			( ! is_array( $data ) || empty( $data['cards'] ) )
			&& class_exists( 'CTA_Flashcards' )
			&& in_array( $slug, $legacy_fallback_slugs, true )
		) {
			$legacy_map = CTA_Flashcards::get_deck_map();
			if ( isset( $legacy_map[ $slug ] ) ) {
				$data = self::read_deck_file( CTA_PLUGIN_DIR . ltrim( $legacy_map[ $slug ], '/' ) );
			}
		}

		if ( ! is_array( $data ) || empty( $data['cards'] ) ) {
			// Approved Study Center programs must never fall through to quiz banks
			// (that path incorrectly mixes Form A/B practice exams into flashcards).
			if ( ! self::program_requires_study_center_deck( $slug ) ) {
				$fallback = self::build_fallback_deck_data( $course );
				if ( is_array( $fallback ) && ! empty( $fallback['cards'] ) ) {
					$data = $fallback;
				}
			}
		}

		if ( ! is_array( $data ) ) {
			$deck = self::get_empty_deck( $course );
			return apply_filters( 'cta_exam_prep_flashcard_study_center_deck', $deck, $course );
		}

		$domain_map = self::normalize_domains( isset( $data['domains'] ) ? (array) $data['domains'] : array() );
		$cards      = self::normalize_cards(
			isset( $data['cards'] ) ? (array) $data['cards'] : array(),
			$domain_map
		);
		$cards = self::strip_practice_exam_cards( $cards );

		// Required Study Center programs must never surface WbN Bank quiz domains
		// (that is the broken quiz-bank mixing shape seen on live NCMHCE/AMFTRB).
		if ( self::program_requires_study_center_deck( $slug ) ) {
			$cards = self::strip_workbook_bank_domain_cards( $cards );
		}

		if ( empty( $cards ) ) {
			if ( ! self::program_requires_study_center_deck( $slug ) ) {
				$fallback = self::build_fallback_deck_data( $course );
				if ( is_array( $fallback ) && ! empty( $fallback['cards'] ) ) {
					$domain_map = self::normalize_domains( isset( $fallback['domains'] ) ? (array) $fallback['domains'] : array() );
					$cards      = self::strip_practice_exam_cards(
						self::normalize_cards(
							isset( $fallback['cards'] ) ? (array) $fallback['cards'] : array(),
							$domain_map
						)
					);
					if ( ! empty( $fallback['title'] ) ) {
						$data['title'] = (string) $fallback['title'];
					}
				}
			}
		}

		if ( empty( $cards ) ) {
			$deck = self::get_empty_deck( $course );
			if ( ! empty( $data['title'] ) ) {
				$deck['title'] = sanitize_text_field( (string) $data['title'] );
			}
			$deck['domains'] = array_values( $domain_map );
			return apply_filters( 'cta_exam_prep_flashcard_study_center_deck', $deck, $course );
		}

		$domains = self::build_domain_stats( $cards, $domain_map );

		$deck = array(
			'title'       => ! empty( $data['title'] )
				? sanitize_text_field( (string) $data['title'] )
				: self::get_empty_deck( $course )['title'],
			'count'       => count( $cards ),
			'cards'       => $cards,
			'domains'     => $domains,
			'has_content' => true,
		);

		/**
		 * Filter parsed Flashcard Study Center deck.
		 *
		 * @param array<string,mixed> $deck   Normalized deck.
		 * @param object              $course Course row.
		 */
		return apply_filters( 'cta_exam_prep_flashcard_study_center_deck', $deck, $course );
	}

	/**
	 * Read and decode a flashcard JSON file.
	 *
	 * @param string $path Absolute file path.
	 * @return array<string,mixed>|null
	 */
	private static function read_deck_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			return null;
		}

		// Strip UTF-8 BOM so json_decode does not fail on hosted files.
		if ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}

		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Normalize domain definitions from JSON.
	 *
	 * @param array<int,mixed> $domains Raw domain rows.
	 * @return array<string,array{key:string,label:string,order:int}>
	 */
	private static function normalize_domains( array $domains ) {
		$map = array();
		$order = 0;

		foreach ( $domains as $domain ) {
			if ( ! is_array( $domain ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $domain['key'] ?? $domain['id'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}
			$label = isset( $domain['label'] ) ? sanitize_text_field( (string) $domain['label'] ) : $key;
			$map[ $key ] = array(
				'key'   => $key,
				'label' => $label,
				'order' => isset( $domain['order'] ) ? (int) $domain['order'] : ++$order,
			);
		}

		uasort(
			$map,
			static function ( $a, $b ) {
				return (int) $a['order'] <=> (int) $b['order'];
			}
		);

		return $map;
	}

	/**
	 * Normalize card rows.
	 *
	 * @param array<int,mixed>                              $cards      Raw cards.
	 * @param array<string,array{key:string,label:string,order:int}> $domain_map Domain map.
	 * @return array<int,array<string,string>>
	 */
	private static function normalize_cards( array $cards, array &$domain_map ) {
		$normalized = array();
		$auto_order = count( $domain_map );

		foreach ( $cards as $index => $card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}

			$front = isset( $card['front'] ) ? self::sanitize_card_text( (string) $card['front'] ) : '';
			$back  = isset( $card['back'] ) ? self::sanitize_card_text( (string) $card['back'] ) : '';
			if ( '' === $front || '' === $back ) {
				continue;
			}

			$domain_key = self::resolve_card_domain_key( $card );

			if ( '' === $domain_key ) {
				$domain_key = 'general';
			}

			if ( ! isset( $domain_map[ $domain_key ] ) ) {
				$label = ! empty( $card['domain_label'] )
					? sanitize_text_field( (string) $card['domain_label'] )
					: self::default_domain_label( $domain_key, $card );
				$domain_map[ $domain_key ] = array(
					'key'   => $domain_key,
					'label' => $label,
					'order' => ++$auto_order,
				);
			}

			$memory_cue = isset( $card['memory_cue'] )
				? self::sanitize_card_text( (string) $card['memory_cue'] )
				: ( isset( $card['memoryCue'] ) ? self::sanitize_card_text( (string) $card['memoryCue'] ) : '' );

			$row = array(
				'id'         => isset( $card['id'] ) ? sanitize_text_field( (string) $card['id'] ) : (string) ( count( $normalized ) + 1 ),
				'domain'     => $domain_key,
				'front'      => $front,
				'back'       => $back,
				'memory_cue' => $memory_cue,
			);

			if ( isset( $card['sort_order'] ) ) {
				$row['sort_order'] = (int) $card['sort_order'];
			}

			if ( ! empty( $card['meta'] ) && is_array( $card['meta'] ) ) {
				$row['meta'] = $card['meta'];
			}

			$normalized[] = $row;
		}

		usort(
			$normalized,
			static function ( $a, $b ) {
				$order = (int) ( $a['sort_order'] ?? 0 ) <=> (int) ( $b['sort_order'] ?? 0 );
				if ( 0 !== $order ) {
					return $order;
				}
				return strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
			}
		);

		return $normalized;
	}

	/**
	 * Attach card counts to domain rows for landing stats.
	 *
	 * @param array<int,array<string,string>>                           $cards      Cards.
	 * @param array<string,array{key:string,label:string,order:int}> $domain_map Domain map.
	 * @return array<int,array<string,mixed>>
	 */
	private static function build_domain_stats( array $cards, array $domain_map ) {
		$counts = array();
		foreach ( $cards as $card ) {
			$key = (string) ( $card['domain'] ?? 'general' );
			if ( ! isset( $counts[ $key ] ) ) {
				$counts[ $key ] = 0;
			}
			++$counts[ $key ];
		}

		$domains = array();
		foreach ( $domain_map as $key => $domain ) {
			$domains[] = array(
				'key'   => (string) $domain['key'],
				'label' => (string) $domain['label'],
				'order' => (int) $domain['order'],
				'count' => (int) ( $counts[ $key ] ?? 0 ),
			);
		}

		// Include domains inferred only from cards not listed in JSON domains array.
		foreach ( $counts as $key => $count ) {
			if ( isset( $domain_map[ $key ] ) ) {
				continue;
			}
			$domains[] = array(
				'key'   => $key,
				'label' => ucwords( str_replace( array( '-', '_' ), ' ', $key ) ),
				'order' => 999,
				'count' => (int) $count,
			);
		}

		usort(
			$domains,
			static function ( $a, $b ) {
				$order = (int) $a['order'] <=> (int) $b['order'];
				if ( 0 !== $order ) {
					return $order;
				}
				return strnatcasecmp( (string) $a['label'], (string) $b['label'] );
			}
		);

		return $domains;
	}

	/**
	 * Resolve a normalized domain key for a card row.
	 *
	 * @param array<string,mixed> $card Raw card.
	 * @return string
	 */
	private static function resolve_card_domain_key( array $card ) {
		$domain_key = sanitize_key( (string) ( $card['domain'] ?? $card['category'] ?? '' ) );
		if ( '' !== $domain_key ) {
			return $domain_key;
		}

		$tag = trim( (string) ( $card['tag'] ?? '' ) );
		if ( '' === $tag ) {
			return '';
		}

		if ( preg_match( '/^Workbook\s+(\d{1,2}):\s*/i', $tag, $matches ) ) {
			$workbook = (int) $matches[1];
			$mapped   = self::amftrb_workbook_domain_map();
			if ( isset( $mapped[ $workbook ] ) ) {
				return (string) $mapped[ $workbook ]['key'];
			}
		}

		// Never treat a full legacy import tag string as a unique domain key.
		return '';
	}

	/**
	 * @param string              $domain_key Domain key.
	 * @param array<string,mixed> $card       Raw card.
	 * @return string
	 */
	private static function default_domain_label( $domain_key, array $card ) {
		foreach ( self::amftrb_domain_definitions() as $domain ) {
			if ( $domain_key === $domain['key'] ) {
				return (string) $domain['label'];
			}
		}

		$tag = trim( (string) ( $card['tag'] ?? '' ) );
		if ( preg_match( '/^Workbook\s+(\d{1,2}):\s*(.+?)\s*\|\s*/i', $tag, $matches ) ) {
			return 'Workbook ' . (int) $matches[1];
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', (string) $domain_key ) );
	}

	/**
	 * Official AMFTRB six-domain taxonomy (Workbook 1 reference).
	 *
	 * @return array<int,array{key:string,label:string,order:int}>
	 */
	private static function amftrb_domain_definitions() {
		return array(
			array(
				'key'   => 'practice-of-systemic-therapy',
				'label' => 'The Practice of Systemic Therapy',
				'order' => 1,
			),
			array(
				'key'   => 'assessing-hypothesizing-and-diagnosing',
				'label' => 'Assessing, Hypothesizing, and Diagnosing',
				'order' => 2,
			),
			array(
				'key'   => 'designing-and-conducting-treatment',
				'label' => 'Designing and Conducting Treatment',
				'order' => 3,
			),
			array(
				'key'   => 'evaluating-process-and-terminating-treatment',
				'label' => 'Evaluating Ongoing Process and Terminating Treatment',
				'order' => 4,
			),
			array(
				'key'   => 'managing-crisis-situations',
				'label' => 'Managing Crisis Situations',
				'order' => 5,
			),
			array(
				'key'   => 'ethical-legal-and-professional-standards',
				'label' => 'Maintaining Ethical, Legal, and Professional Standards',
				'order' => 6,
			),
		);
	}

	/**
	 * Primary AMFTRB exam domain per workbook (program workbook emphasis).
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	private static function amftrb_workbook_domain_map() {
		$defs = self::amftrb_domain_definitions();
		$by_key = array();
		foreach ( $defs as $def ) {
			$by_key[ $def['key'] ] = $def;
		}

		$keys = array(
			1  => 'practice-of-systemic-therapy',
			2  => 'practice-of-systemic-therapy',
			3  => 'assessing-hypothesizing-and-diagnosing',
			4  => 'assessing-hypothesizing-and-diagnosing',
			5  => 'designing-and-conducting-treatment',
			6  => 'managing-crisis-situations',
			7  => 'managing-crisis-situations',
			8  => 'designing-and-conducting-treatment',
			9  => 'designing-and-conducting-treatment',
			10 => 'evaluating-process-and-terminating-treatment',
			11 => 'ethical-legal-and-professional-standards',
			12 => 'evaluating-process-and-terminating-treatment',
		);

		$map = array();
		foreach ( $keys as $workbook => $domain_key ) {
			if ( isset( $by_key[ $domain_key ] ) ) {
				$map[ $workbook ] = $by_key[ $domain_key ];
			}
		}

		return $map;
	}

	/**
	 * Sanitize flashcard text while preserving intentional line breaks.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function sanitize_card_text( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}

		if ( function_exists( 'cta_lms_sanitize_utf8_text' ) ) {
			$text = cta_lms_sanitize_utf8_text( $text );
		}

		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/<\s*br\s*\/?\s*>/i', "\n", $text );
		$text = preg_replace( '/<\/\s*p\s*>/i', "\n", $text );
		$text = wp_strip_all_tags( $text, false );

		$lines = explode( "\n", $text );
		$lines = array_map(
			static function ( $line ) {
				$line = preg_replace( '/[ \t]+/', ' ', (string) $line );
				return trim( (string) $line );
			},
			$lines
		);

		$out   = array();
		$blank = false;
		foreach ( $lines as $line ) {
			if ( '' === $line ) {
				if ( ! $blank && ! empty( $out ) ) {
					$out[] = '';
					$blank = true;
				}
				continue;
			}
			$out[] = $line;
			$blank = false;
		}

		return trim( implode( "\n", $out ) );
	}

	/**
	 * Course slug → printable flashcard DOCX (used when Study Center JSON is empty).
	 *
	 * @return array<string,string>
	 */
	public static function get_docx_deck_map() {
		return array(
			'california-law-ethics-exam-preparation'      => 'assets/course-materials/lmft-law-ethics/study-tools/CTA_LMFT_Law_and_Ethics_EP_Master_Flashcard_Library_Printable_Single_Sided_Study_Edition_v1.1.docx',
			'lmft-california-clinical-exam-preparation'   => 'assets/course-materials/lmft-clinical/study-tools/CTA_LMFT_Clinical_Exam_Preparation_Flashcard_Collection_v1.0.docx',
			'lmft-amftrb-national-exam-preparation'       => 'assets/course-materials/lmft-amftrb/study-tools/CTA_LMFT_AMFTRB_WB1-12_120_Card_Flashcard_Study_Collection_v1.0.docx',
			'lcsw-aswb-clinical-exam-preparation'         => 'assets/course-materials/lcsw-aswb/study-tools/CTA_LCSW_Clinical_Exam_Preparation_Flashcard_Collection_v1.0.docx',
			'lcsw-california-clinical-exam-preparation'   => 'assets/course-materials/lcsw-aswb/study-tools/CTA_LCSW_Clinical_Exam_Preparation_Flashcard_Collection_v1.0.docx',
			'lpcc-ncmhce-exam-preparation'                => 'assets/course-materials/lpcc-ncmhce/study-tools/CTA_LPCC_Clinical_Exam_Preparation_Flashcard_Collection_v1.0.docx',
			'lpcc-california-clinical-exam-preparation'   => 'assets/course-materials/lpcc-ncmhce/study-tools/CTA_LPCC_Clinical_Exam_Preparation_Flashcard_Collection_v1.0.docx',
			'lpcc-california-law-ethics-exam-preparation' => 'assets/course-materials/lpcc-law-ethics/start-here/CTA_LPCC_Law_and_Ethics_EP_License_Specific_Module_25_Card_Flashcards_and_Remediation_v1.0.docx',
		);
	}

	/**
	 * Build a Study Center payload from printable DOCX or (last resort) workbook quiz banks.
	 * Never pulls Form A/B / checkpoints / full simulations into flashcards.
	 *
	 * @param object|null $course Course row.
	 * @return array<string,mixed>|null
	 */
	public static function build_fallback_deck_data( $course ) {
		if ( $course && ! empty( $course->slug ) && self::program_requires_study_center_deck( (string) $course->slug ) ) {
			// DOCX only — never quiz banks for programs with an approved Study Center deck.
			return self::parse_docx_deck_for_course( $course );
		}

		$docx = self::parse_docx_deck_for_course( $course );
		if ( is_array( $docx ) && ! empty( $docx['cards'] ) ) {
			return $docx;
		}

		return self::build_quiz_bank_deck_for_course( $course );
	}

	/**
	 * Parse the program flashcard DOCX into Study Center cards.
	 *
	 * @param object|null $course Course row.
	 * @return array<string,mixed>|null
	 */
	public static function parse_docx_deck_for_course( $course ) {
		if ( ! $course || empty( $course->slug ) || ! class_exists( 'ZipArchive' ) ) {
			return null;
		}

		$slug = sanitize_title( (string) $course->slug );
		$map  = self::get_docx_deck_map();
		if ( ! isset( $map[ $slug ] ) ) {
			return null;
		}

		$path = CTA_PLUGIN_DIR . ltrim( $map[ $slug ], '/' );
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$mtime = (int) filemtime( $path );
		$cache_key = 'cta_fsc_docx_' . md5( $path . '|' . $mtime );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && ! empty( $cached['cards'] ) ) {
			return $cached;
		}

		$text = self::extract_docx_plain_text( $path );
		if ( '' === $text ) {
			return null;
		}

		$cards = self::parse_question_answer_cards( $text );
		if ( empty( $cards ) ) {
			return null;
		}

		$payload = array(
			'title'  => sprintf(
				/* translators: %s: program title */
				__( '%s — Flashcard Study Center', 'cta-lms' ),
				function_exists( 'cta_lms_get_course_display_title' )
					? cta_lms_get_course_display_title( $course )
					: (string) $course->title
			),
			'cards'  => $cards,
			'domains'=> array(),
		);

		set_transient( $cache_key, $payload, WEEK_IN_SECONDS );

		return $payload;
	}

	/**
	 * Use workbook practice-bank items as study cards when no dedicated deck exists.
	 * Excludes Form A/B, checkpoints, and other Practice Exam simulations.
	 *
	 * @param object|null $course Course row.
	 * @return array<string,mixed>|null
	 */
	public static function build_quiz_bank_deck_for_course( $course ) {
		if ( ! $course || empty( $course->id ) || ! class_exists( 'CTA_Database' ) ) {
			return null;
		}

		if ( ! empty( $course->slug ) && self::program_requires_study_center_deck( (string) $course->slug ) ) {
			return null;
		}

		$blocked = self::practice_exam_domain_keys();
		$cards   = array();
		$n       = 0;

		foreach ( CTA_Database::get_quizzes_by_course( (int) $course->id, true ) as $quiz ) {
			$quiz_type = sanitize_key( (string) ( $quiz->quiz_type ?? '' ) );
			$quiz_title = strtolower( (string) ( $quiz->title ?? '' ) );
			if ( in_array( $quiz_type, $blocked, true ) ) {
				continue;
			}
			// Title safety net when quiz_type is blank/legacy but Practice Exam labels remain.
			if ( preg_match( '/\bcheckpoint\b/', $quiz_title )
				|| preg_match( '/\bform\s*[ab]\b/', $quiz_title )
				|| preg_match( '/comprehensive\s+simulation/', $quiz_title ) ) {
				continue;
			}

			// Only workbook practice banks — never program-level exams.
			if ( class_exists( 'CTA_Exam_Prep_Workbooks' ) ) {
				if ( CTA_Exam_Prep_Workbooks::is_full_simulation_quiz( $quiz )
					|| CTA_Exam_Prep_Workbooks::is_cumulative_quiz( $quiz )
					|| CTA_Exam_Prep_Workbooks::is_program_level_quiz( $quiz ) ) {
					continue;
				}
				if ( ! CTA_Exam_Prep_Workbooks::is_workbook_quiz( $quiz ) ) {
					continue;
				}
			} elseif ( ! preg_match( '/^wb\d+_bank$/', $quiz_type ) ) {
				continue;
			}

			$questions = CTA_Database::get_quiz_questions( (int) $quiz->id );
			if ( empty( $questions ) ) {
				continue;
			}

			$domain = $quiz_type;
			if ( '' === $domain ) {
				$domain = 'general';
			}

			// AMFTRB: map workbook banks onto the six official exam domains.
			if ( preg_match( '/^wb(\d+)_bank$/', $domain, $wm ) ) {
				$mapped = self::amftrb_workbook_domain_map();
				$wb_num = (int) $wm[1];
				if ( isset( $mapped[ $wb_num ]['key'] ) ) {
					$domain = (string) $mapped[ $wb_num ]['key'];
				}
			}

			foreach ( $questions as $question ) {
				$front = self::sanitize_card_text( (string) ( $question->question_text ?? '' ) );
				if ( '' === $front ) {
					continue;
				}

				$correct = strtolower( (string) ( $question->correct_option ?? '' ) );
				$option  = '';
				if ( in_array( $correct, array( 'a', 'b', 'c', 'd' ), true ) ) {
					$key    = 'option_' . $correct;
					$option = self::sanitize_card_text( (string) ( $question->{$key} ?? '' ) );
				}

				$explanation = self::sanitize_card_text( (string) ( $question->explanation ?? '' ) );
				$back_parts  = array();
				if ( '' !== $option ) {
					$back_parts[] = strtoupper( $correct ) . '. ' . $option;
				}
				if ( '' !== $explanation ) {
					$lines = preg_split( '/\n+/', $explanation );
					$back_parts[] = is_array( $lines ) && isset( $lines[0] ) ? $lines[0] : $explanation;
				}
				$back = self::sanitize_card_text( implode( "\n", $back_parts ) );
				if ( '' === $back ) {
					continue;
				}

				++$n;
				$cards[] = array(
					'id'     => 'q-' . (int) $quiz->id . '-' . (int) ( $question->id ?? $n ),
					'front'  => $front,
					'back'   => $back,
					'domain' => $domain,
				);

				if ( $n >= 180 ) {
					break 2;
				}
			}
		}

		if ( empty( $cards ) ) {
			return null;
		}

		return array(
			'title'   => sprintf(
				/* translators: %s: program title */
				__( '%s — Flashcard Study Center', 'cta-lms' ),
				function_exists( 'cta_lms_get_course_display_title' )
					? cta_lms_get_course_display_title( $course )
					: (string) $course->title
			),
			'cards'   => $cards,
			'domains' => array(),
		);
	}

	/**
	 * Extract concatenated paragraph text from a DOCX file.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function extract_docx_plain_text( $path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return '';
		}

		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();
		if ( ! is_string( $xml ) || '' === $xml ) {
			return '';
		}

		$xml = preg_replace( '/<\/w:p>/i', "\n", $xml );
		$xml = preg_replace( '/<w:tab\b[^>]*\/?>/i', ' ', $xml );
		$text = wp_strip_all_tags( $xml );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( (string) $text );
	}

	/**
	 * Split QUESTION / ANSWER blocks from printable flashcard text.
	 *
	 * @param string $text Plain text.
	 * @return array<int,array<string,string>>
	 */
	private static function parse_question_answer_cards( $text ) {
		$parts = preg_split( '/(?:^|\n)\s*QUESTION\s*(?:\n|$)/i', $text );
		if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
			return array();
		}

		array_shift( $parts );
		$cards = array();
		$n     = 0;

		foreach ( $parts as $part ) {
			$chunk = trim( (string) $part );
			if ( '' === $chunk ) {
				continue;
			}

			$chunk = preg_replace( '/^[–—\-]+\s*COVER OR FOLD HERE\s*[–—\-]+\s*/im', "\n", $chunk );
			if ( ! preg_match( '/^(.*?)\n\s*ANSWER\s*\n(.*)$/is', $chunk, $m ) ) {
				if ( ! preg_match( '/^(.*?)\s+ANSWER\s+(.*)$/is', $chunk, $m ) ) {
					continue;
				}
			}

			$front = self::sanitize_card_text( (string) $m[1] );
			$back  = self::sanitize_card_text( (string) $m[2] );

			$back = preg_replace(
				'/\n(?:LMFT|LCSW|LPCC|AMFTRB|CORE|WB\s*\d+).*$/is',
				'',
				$back
			);
			$back = self::sanitize_card_text( (string) $back );

			if ( '' === $front || '' === $back ) {
				continue;
			}

			++$n;
			$domain = 'general';
			if ( preg_match( '/\bWB\s*(\d{1,2})\b/i', $front . ' ' . $back, $wb ) ) {
				$domain = 'workbook-' . absint( $wb[1] );
			} elseif ( preg_match( '/\bCORE\b/i', $front . ' ' . $back ) ) {
				$domain = 'core';
			}

			$cards[] = array(
				'id'     => (string) $n,
				'front'  => $front,
				'back'   => $back,
				'domain' => $domain,
			);
		}

		return $cards;
	}
}
}
