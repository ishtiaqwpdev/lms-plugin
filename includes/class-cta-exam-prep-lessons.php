<?php
/**
 * Exam Prep in-LMS workbook lessons (HTML converted from printable DOCX).
 *
 * Printable DOCX downloads remain available. HTML lessons power readable
 * Previous/Next module pages inside the course player.
 *
 * @package CTA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CTA_Exam_Prep_Lessons
 */
if ( ! class_exists( 'CTA_Exam_Prep_Lessons' ) ) {

class CTA_Exam_Prep_Lessons {

	/**
	 * Course slug → materials program folder key.
	 *
	 * @return array<string,string>
	 */
	public static function get_program_map() {
		return array(
			'california-law-ethics-exam-preparation'         => 'lmft-law-ethics',
			'lpcc-ncmhce-exam-preparation'                   => 'lpcc-ncmhce',
			'lpcc-california-clinical-exam-preparation'      => 'lpcc-ncmhce',
			'lpcc-california-law-ethics-exam-preparation'    => 'lpcc-law-ethics',
			'lcsw-california-law-ethics-exam-preparation'    => 'lcsw-law-ethics',
			'lcsw-aswb-clinical-exam-preparation'            => 'lcsw-aswb',
			'lcsw-california-clinical-exam-preparation'      => 'lcsw-aswb',
			'lmft-california-clinical-exam-preparation'      => 'lmft-clinical',
			'lmft-amftrb-national-exam-preparation'          => 'lmft-amftrb',
		);
	}

	/**
	 * Extract workbook number from a module title like "Workbook 3: …".
	 *
	 * @param object|string $module Module row or title string.
	 * @return int
	 */
	public static function workbook_number_from_module( $module ) {
		$title = is_object( $module ) ? (string) ( $module->title ?? '' ) : (string) $module;
		if ( preg_match( '/^\s*Workbook\s+(\d+)\s*:/i', $title, $m ) ) {
			return absint( $m[1] );
		}
		return 0;
	}

	/**
	 * Resolve program folder key for a course.
	 *
	 * @param object|null $course Course row.
	 * @return string
	 */
	public static function program_key_for_course( $course ) {
		if ( ! $course || empty( $course->slug ) ) {
			return '';
		}
		$map  = self::get_program_map();
		$slug = sanitize_title( (string) $course->slug );
		return isset( $map[ $slug ] ) ? $map[ $slug ] : '';
	}

	/**
	 * Absolute path to a lesson HTML file.
	 *
	 * @param string $program_key Program folder.
	 * @param int    $workbook_num Workbook number.
	 * @return string
	 */
	public static function lesson_path( $program_key, $workbook_num ) {
		$program_key  = sanitize_title( (string) $program_key );
		$workbook_num = absint( $workbook_num );
		if ( '' === $program_key || $workbook_num < 1 ) {
			return '';
		}
		return CTA_PLUGIN_DIR . 'assets/course-materials/' . $program_key . '/lessons/wb' . sprintf( '%02d', $workbook_num ) . '.html';
	}

	/**
	 * Load sanitized lesson HTML for a course module.
	 *
	 * @param object|null $course Course row.
	 * @param object|null $module Module row.
	 * @return array{html:string,workbook_num:int,program:string}|null
	 */
	public static function get_lesson_for_module( $course, $module ) {
		if ( ! $course || ! $module ) {
			return null;
		}
		if ( class_exists( 'CTA_Exam_Access' ) && ! CTA_Exam_Access::is_exam_prep( $course ) ) {
			return null;
		}

		$program = self::program_key_for_course( $course );
		if ( '' === $program ) {
			return null;
		}

		$title = is_object( $module ) ? (string) ( $module->title ?? '' ) : '';
		$num   = self::workbook_number_from_module( $module );
		$path  = '';

		// Start Here orientation lesson (non-workbook module).
		if ( preg_match( '/^\s*Start\s+Here\s*:/i', $title ) ) {
			$path = CTA_PLUGIN_DIR . 'assets/course-materials/' . $program . '/lessons/start-here.html';
			$num  = 0;
		} elseif ( preg_match( '/^\s*Program\s+Close\b/i', $title ) ) {
			$path = CTA_PLUGIN_DIR . 'assets/course-materials/' . $program . '/lessons/program-close.html';
			$num  = 0;
		} elseif ( class_exists( 'CTA_Exam_Prep_Workbooks' ) && CTA_Exam_Prep_Workbooks::is_license_module( $module ) ) {
			$path = CTA_PLUGIN_DIR . 'assets/course-materials/' . $program . '/lessons/license-module.html';
			$num  = 0;
		} else {
			$path = self::lesson_path( $program, $num );
		}

		$raw = '';
		if ( '' !== $path && is_readable( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$raw = (string) file_get_contents( $path );
		}

		if ( '' === trim( $raw ) && $num > 0 ) {
			$raw = self::html_from_workbook_docx( $program, $num );
		}

		if ( '' === trim( $raw ) ) {
			return null;
		}

		$html = self::sanitize_lesson_html( $raw );
		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			return null;
		}

		return array(
			'html'         => $html,
			'workbook_num' => $num,
			'program'      => $program,
		);
	}

	/**
	 * Convert the printable student workbook DOCX into lesson HTML when wbNN.html is missing.
	 *
	 * @param string $program_key Program folder key.
	 * @param int    $workbook_num Workbook number.
	 * @return string
	 */
	public static function html_from_workbook_docx( $program_key, $workbook_num ) {
		$program_key  = sanitize_title( (string) $program_key );
		$workbook_num = absint( $workbook_num );
		if ( '' === $program_key || $workbook_num < 1 || ! class_exists( 'ZipArchive' ) ) {
			return '';
		}

		$docx = self::find_workbook_docx_path( $program_key, $workbook_num );
		if ( '' === $docx || ! is_readable( $docx ) ) {
			return '';
		}

		$mtime     = (int) filemtime( $docx );
		$cache_key = 'cta_wb_html_' . md5( $docx . '|' . $mtime );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== trim( $cached ) ) {
			return $cached;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $docx ) ) {
			return '';
		}
		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();
		if ( ! is_string( $xml ) || '' === $xml ) {
			return '';
		}

		$html = self::word_xml_to_lesson_html( $xml, $program_key, $workbook_num );
		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			return '';
		}

		set_transient( $cache_key, $html, WEEK_IN_SECONDS );

		return $html;
	}

	/**
	 * Locate the student workbook DOCX for a program + workbook number.
	 *
	 * @param string $program_key Program folder.
	 * @param int    $workbook_num Workbook number.
	 * @return string Absolute path or empty.
	 */
	public static function find_workbook_docx_path( $program_key, $workbook_num ) {
		$dir = CTA_PLUGIN_DIR . 'assets/course-materials/' . $program_key . '/workbooks';
		if ( ! is_dir( $dir ) ) {
			return '';
		}

		$files = glob( $dir . '/*.docx' );
		if ( ! is_array( $files ) ) {
			return '';
		}

<<<<<<< HEAD
		$needle   = '_WB' . (int) $workbook_num . '_';
		$needle_z = '_WB' . sprintf( '%02d', (int) $workbook_num ) . '_';
		foreach ( $files as $file ) {
			$name = basename( (string) $file );
			if ( false !== stripos( $name, $needle ) || false !== stripos( $name, $needle_z ) ) {
=======
		$num = (int) $workbook_num;
		// Match both `_WB1_` and zero-padded `_WB01_` without grabbing `_WB10_`.
		$pattern = ( $num >= 10 )
			? '/_WB' . $num . '_/i'
			: '/_WB0?' . $num . '_/i';
		foreach ( $files as $file ) {
			$name = basename( (string) $file );
			if ( preg_match( $pattern, $name ) ) {
>>>>>>> 1dcdd55b430ec7b912f0b502b3878173ec976d47
				return (string) $file;
			}
		}

		return '';
	}

	/**
	 * Convert WordprocessingML into allowlisted lesson HTML.
	 *
	 * @param string $xml          document.xml contents.
	 * @param string $program_key  Program key.
	 * @param int    $workbook_num Workbook number.
	 * @return string
	 */
	private static function word_xml_to_lesson_html( $xml, $program_key, $workbook_num ) {
		$xml = preg_replace( '/<w:tab\b[^>]*\/?>/i', ' ', (string) $xml );
		$skip_answers = false;
		$html         = '<article class="cta-lesson-article" data-program="' . esc_attr( $program_key ) . '" data-workbook="' . (int) $workbook_num . '">';

		if ( preg_match_all( '/<(w:tbl|w:p)\b[\s\S]*?<\/\1>/i', $xml, $blocks, PREG_SET_ORDER ) ) {
			foreach ( $blocks as $block ) {
				$chunk = (string) $block[0];
				$tag   = strtolower( (string) $block[1] );

				if ( 'w:tbl' === $tag ) {
					if ( $skip_answers ) {
						continue;
					}
					$html .= self::word_table_to_html( $chunk );
					continue;
				}

				$style = '';
				if ( preg_match( '/<w:pStyle\b[^>]*w:val="([^"]+)"/i', $chunk, $sm ) ) {
					$style = (string) $sm[1];
				}
				$text = self::word_paragraph_text( $chunk );
				if ( '' === $text ) {
					continue;
				}

				$is_bold = (bool) preg_match( '/<w:b\s*\/?>|<w:b\s[^>]*>/i', $chunk );
				$is_h1   = (bool) preg_match( '/^(Heading1|Title)$/i', $style )
<<<<<<< HEAD
					|| (bool) preg_match( '/^(How to Use This Workbook|Workbook Learning Objectives|Learning Objectives|Why This Topic Matters|Workbook Roadmap|Chapter Summary|Chapter\s+\d+\s+Summary|Knowledge Check|Workbook\s+\d+\s+Knowledge Check|Common Exam Traps|Workbook Close|Study Planning(?:\s+and\s+Next Step)?|Current AMFTRB Alignment|Workbook\s+\d+\s+Emphasis)\b/i', $text );
=======
					|| (bool) preg_match( '/^(How to Use This Workbook|Workbook Learning Objectives|Learning Objectives|Why This Topic Matters|Workbook Roadmap|Chapter Map|Chapter Summary|Chapter\s+\d+\s+Summary|Knowledge Check|Workbook\s+\d+\s+Knowledge Check|Common Exam Traps|Workbook Close|Study Planning(?:\s+and\s+Next Step)?|Current AMFTRB Alignment|Workbook\s+\d+\s+Emphasis)\b/i', $text );
>>>>>>> 1dcdd55b430ec7b912f0b502b3878173ec976d47
				$is_h2   = (bool) preg_match( '/^Heading2$/i', $style );
				$is_h3   = (bool) preg_match( '/^Heading3$/i', $style );

				// AMFTRB packages often mark chapter titles as bold body text, not Heading styles.
				if ( ! $is_h1 && ! $is_h2 && ! $is_h3 && $is_bold && strlen( $text ) <= 110
					&& preg_match( '/^(Domain\s+\d+|EXAM CONTENT CONTROL|EDUCATIONAL USE NOTICE|MATERIAL NOTICE|BIG IDEA|CHAPTER BIG IDEA)\b/i', $text ) ) {
					$is_h2 = true;
				}

				if ( $is_h1 && preg_match( '/^Answer Key/i', $text ) ) {
					$skip_answers = true;
					continue;
				}
				if ( $skip_answers ) {
					if ( $is_h1 ) {
						$skip_answers = false;
					} else {
						continue;
					}
				}

				if ( $is_h1 ) {
					$html .= '<h2 class="cta-lesson-h2">' . esc_html( $text ) . '</h2>';
				} elseif ( $is_h2 ) {
					$html .= '<h3 class="cta-lesson-h3">' . esc_html( $text ) . '</h3>';
				} elseif ( $is_h3 ) {
					$html .= '<h4 class="cta-lesson-h4">' . esc_html( $text ) . '</h4>';
				} elseif ( preg_match( '/<w:numPr\b/i', $chunk ) ) {
					$html .= '<p class="cta-lesson-p cta-lesson-p--list">' . esc_html( $text ) . '</p>';
				} else {
					$html .= '<p class="cta-lesson-p">' . esc_html( $text ) . '</p>';
				}
			}
		}

		$html .= '</article>';
		return $html;
	}

	/**
	 * Plain text from a Word paragraph or table-cell node.
	 *
	 * Multi-paragraph cells join with a space so AMFTRB callout tables do not glue lines.
	 *
	 * @param string $chunk w:p or w:tc XML.
	 * @return string
	 */
	private static function word_paragraph_text( $chunk ) {
		if ( preg_match_all( '/<w:p\b[\s\S]*?<\/w:p>/i', $chunk, $paras ) && count( $paras[0] ) > 1 ) {
			$lines = array();
			foreach ( $paras[0] as $para ) {
				$line = self::word_run_text( $para );
				if ( '' !== $line ) {
					$lines[] = $line;
				}
			}
			return trim( implode( ' ', $lines ) );
		}

		return self::word_run_text( $chunk );
	}

	/**
	 * Concatenate w:t runs from a Word XML fragment.
	 *
	 * @param string $chunk XML fragment.
	 * @return string
	 */
	private static function word_run_text( $chunk ) {
		$parts = array();
		if ( preg_match_all( '/<w:t\b[^>]*>([^<]*)<\/w:t>/i', $chunk, $tm ) ) {
			foreach ( $tm[1] as $t ) {
				$parts[] = html_entity_decode( (string) $t, ENT_QUOTES | ENT_XML1, 'UTF-8' );
			}
		}
		$text = trim( preg_replace( '/\s+/u', ' ', implode( '', $parts ) ) );
		return $text;
	}

	/**
	 * Convert a Word table to HTML.
	 *
	 * @param string $chunk w:tbl XML.
	 * @return string
	 */
	private static function word_table_to_html( $chunk ) {
		$rows = array();
		if ( preg_match_all( '/<w:tr\b[\s\S]*?<\/w:tr>/i', $chunk, $tr_m ) ) {
			foreach ( $tr_m[0] as $tr ) {
				$cells = array();
				if ( preg_match_all( '/<w:tc\b[\s\S]*?<\/w:tc>/i', $tr, $tc_m ) ) {
					foreach ( $tc_m[0] as $tc ) {
						$cells[] = self::word_paragraph_text( $tc );
					}
				}
				if ( implode( '', $cells ) !== '' ) {
					$rows[] = $cells;
				}
			}
		}
		if ( empty( $rows ) ) {
			return '';
		}

		$out = '<div class="cta-lesson-table-wrap"><table class="cta-lesson-table"><tbody>';
		foreach ( $rows as $row ) {
			$out .= '<tr>';
			foreach ( $row as $cell ) {
				$out .= '<td>' . esc_html( $cell ) . '</td>';
			}
			$out .= '</tr>';
		}
		$out .= '</tbody></table></div>';
		return $out;
	}

	/**
	 * Allowlisted HTML for lesson body.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	public static function sanitize_lesson_html( $html ) {
		if ( function_exists( 'cta_lms_sanitize_utf8_text' ) ) {
			$html = cta_lms_sanitize_utf8_text( (string) $html );
		}

		// Safety net for legacy glued banner labels already stored in HTML.
		$html = preg_replace(
			'/\b(MATERIAL|NOTICE|WELCOME|LOCKS|PROGRAM|IMPORTANT|REPAIR|CONTROL|SEQUENCE|REASONING|LAB)(?=[A-Z][a-z])/u',
			'$1 ',
			(string) $html
		);

		// Learner lesson files may include authoring labels immediately before
		// knowledge-check questions (ID, cognitive type, difficulty, and primary
		// concept). A primary concept can reveal the answer, so omit only the
		// rendered label. The original lesson/package files remain untouched for
		// production and content-audit use.
		$html = preg_replace(
			'#<p\b[^>]*>\s*(?:<strong>)?\s*[^<]*?\|\s*(?:Recall|Application|Analysis|Comprehension|Knowledge)\s*\|\s*(?:Easy|Moderate|Medium|Hard)\s*\|\s*Primary\s+concept\s*:\s*[^<]+(?:</strong>)?\s*</p>#iu',
			'',
			(string) $html
		);

		// Strip embedded answer-key / rationale chapters from every learner path
		// (tabbed and non-tabbed). Source HTML packages keep these sections for
		// staff QA; online assessments reveal keyed feedback only after submit.
		$html = preg_replace(
			'#<h2\b[^>]*>\s*Answer\s+Key(?:\s+and\s+Detailed\s+Rationales)?\s*</h2>.*?(?=<h2\b|</article>|$)#isu',
			'',
			(string) $html
		);

		$allowed = array(
			'article' => array(
				'class'         => true,
				'data-program'  => true,
				'data-workbook' => true,
			),
			'div'     => array( 'class' => true ),
			'h2'      => array( 'class' => true ),
			'h3'      => array( 'class' => true ),
			'h4'      => array( 'class' => true ),
			'p'       => array( 'class' => true ),
			'ul'      => array( 'class' => true ),
			'ol'      => array( 'class' => true ),
			'li'      => array( 'class' => true ),
			'table'   => array( 'class' => true ),
			'tbody'   => array(),
			'thead'   => array(),
			'tr'      => array(),
			'th'      => array( 'colspan' => true, 'rowspan' => true ),
			'td'      => array( 'colspan' => true, 'rowspan' => true ),
			'br'      => array(),
			'hr'      => array( 'class' => true ),
			'strong'  => array(),
			'em'      => array(),
			'b'       => array(),
			'i'       => array(),
		);

		return wp_kses( $html, $allowed );
	}

	/**
	 * Find the printable workbook download resource for the current module.
	 *
	 * @param array       $resources Course resources.
	 * @param object|null $module    Module row.
	 * @return object|null
	 */
	public static function find_workbook_resource( array $resources, $module ) {
		if ( empty( $resources ) || ! $module ) {
			return null;
		}

		$module_id = absint( $module->id );
		$num       = self::workbook_number_from_module( $module );

		foreach ( $resources as $resource ) {
			if ( empty( $resource->title ) ) {
				continue;
			}
			$title = (string) $resource->title;
			$file  = isset( $resource->file_name ) ? (string) $resource->file_name : '';
			$is_wb = ( false !== stripos( $title, 'workbook' ) || false !== stripos( $file, 'workbook' ) )
				&& false === stripos( $title, 'remediation' )
				&& empty( $resource->is_practice_test );

			if ( ! $is_wb ) {
				continue;
			}

			if ( $module_id && ! empty( $resource->module_id ) && absint( $resource->module_id ) === $module_id ) {
				return $resource;
			}

			if ( $num > 0 && preg_match( '/\bWB\s*' . $num . '\b|Workbook\s+' . $num . '\b/i', $title . ' ' . $file ) ) {
				return $resource;
			}
		}

		return null;
	}
}
}
