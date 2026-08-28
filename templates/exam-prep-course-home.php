<?php
/**
 * Exam Prep Course Home Dashboard (Phase 2 landing view).
 *
 * Getting Started / Exam Strategy is the first section; additional dashboard
 * sections (Workbooks, Flashcards, Practice Exams, etc.) will be added below.
 *
 * @package CTA_LMS
 *
 * @var object                $course           Course row.
 * @var array                 $modules          Course modules.
 * @var object                $enrollment       Enrollment row.
 * @var array                 $completed_ids    Completed module IDs.
 * @var int                   $progress         Progress percentage.
 * @var array                 $getting_started  Getting started config.
 * @var string                $workbooks_list_url URL to workbooks list.
 * @var string                $course_home_url    Course home URL.
 * @var string                $player_base      Player page base URL.
 * @var string                $dashboard_url    Student dashboard URL.
 * @var string                $logout_url       Logout URL.
 * @var string                $home_url         Site home URL.
 * @var array                 $dashboard_user   Sidebar user data.
 * @var CTA_Student_Dashboard $dashboard        Dashboard instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$display_title = function_exists( 'cta_lms_get_course_display_title' )
	? cta_lms_get_course_display_title( $course )
	: (string) $course->title;

$home_url_player = $course_home_url ?? add_query_arg(
	array(
		'course_id' => (int) $course->id,
		'view'      => 'home',
	),
	$player_base
);
$workbooks_list_url = $workbooks_list_url ?? ( class_exists( 'CTA_Exam_Prep_Workbooks' )
	? CTA_Exam_Prep_Workbooks::get_workbooks_list_url( (int) $course->id, $player_base )
	: $home_url_player );
?>
<div class="cta-plugin-wrapper">
<div class="cta-lms cta-exam-prep-home dashboard-layout" data-exam-prep-home data-course-id="<?php echo esc_attr( (int) $course->id ); ?>">

	<?php include CTA_PLUGIN_DIR . 'templates/partials/exam-prep-dashboard-sidebar.php'; ?>

	<?php include CTA_PLUGIN_DIR . 'templates/partials/dashboard-mobile-bar.php'; ?>

	<div class="dashboard-main">
		<?php if ( $dashboard_url ) : ?>
			<p class="course-player__back">
				<a href="<?php echo esc_url( $dashboard_url ); ?>">&larr; <?php echo esc_html__( 'Back to My Courses', 'cta-lms' ); ?></a>
			</p>
		<?php endif; ?>

		<header class="cta-exam-prep-home__hero">
			<p class="cta-exam-prep-home__badge"><?php esc_html_e( 'Exam Preparation Program', 'cta-lms' ); ?></p>
			<h1 class="cta-exam-prep-home__title"><?php echo esc_html( $display_title ); ?></h1>
			<div class="cta-exam-prep-home__meta">
				<div class="progress cta-exam-prep-home__progress">
					<div class="progress__label">
						<span><?php echo esc_html__( 'Program progress', 'cta-lms' ); ?></span>
						<span class="progress__percent"><?php echo esc_html( (string) (int) $progress ); ?>%</span>
					</div>
					<div class="progress__track">
						<div class="progress__bar" style="width: <?php echo esc_attr( (string) (int) $progress ); ?>%;"></div>
					</div>
				</div>
			</div>
		</header>

		<?php
		include CTA_PLUGIN_DIR . 'templates/partials/exam-prep-getting-started.php';
		include CTA_PLUGIN_DIR . 'templates/partials/learner-syllabus.php';
		?>

		<section class="cta-ep-home-section" aria-labelledby="cta-ep-home-workbooks-title">
			<div class="cta-ep-home-section__head">
				<div>
					<h2 class="dashboard-section__title" id="cta-ep-home-workbooks-title"><?php esc_html_e( 'Workbooks / Learning Modules', 'cta-lms' ); ?></h2>
					<p class="cta-ep-home-section__lede"><?php esc_html_e( 'Open any workbook to read online, download the printable file, and take the paired practice bank.', 'cta-lms' ); ?></p>
				</div>
				<?php if ( ! empty( $workbooks_list_url ) ) : ?>
					<a class="btn btn-outline btn--sm" href="<?php echo esc_url( $workbooks_list_url ); ?>"><?php esc_html_e( 'View all workbooks', 'cta-lms' ); ?></a>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $workbook_items ) ) : ?>
				<div class="cta-ep-workbooks-grid" role="list">
					<?php foreach ( (array) $workbook_items as $item ) : ?>
						<article class="cta-ep-workbooks-grid__card<?php echo ! empty( $item['is_complete'] ) ? ' is-complete' : ''; ?>" role="listitem">
							<div class="cta-ep-workbooks-grid__card-head">
								<span class="cta-ep-workbooks-grid__label"><?php echo esc_html( (string) $item['label'] ); ?></span>
								<?php if ( ! empty( $item['is_complete'] ) ) : ?>
									<span class="cta-ep-workbooks-grid__status"><?php esc_html_e( 'Complete', 'cta-lms' ); ?></span>
								<?php endif; ?>
							</div>
							<h3 class="cta-ep-workbooks-grid__card-title">
								<a href="<?php echo esc_url( (string) $item['url'] ); ?>"><?php echo esc_html( (string) $item['title'] ); ?></a>
							</h3>
							<?php if ( ! empty( $item['description'] ) ) : ?>
								<p class="cta-ep-workbooks-grid__desc"><?php echo esc_html( (string) $item['description'] ); ?></p>
							<?php endif; ?>
							<a class="btn btn-primary btn--sm cta-ep-workbooks-grid__open" href="<?php echo esc_url( (string) $item['url'] ); ?>">
								<?php echo ! empty( $item['is_complete'] ) ? esc_html__( 'Review Workbook', 'cta-lms' ) : esc_html__( 'Open Workbook', 'cta-lms' ); ?>
							</a>
						</article>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="cta-ep-home-section__lede"><?php esc_html_e( 'Workbooks for this program will appear here as soon as they are published.', 'cta-lms' ); ?></p>
			<?php endif; ?>
		</section>

		<section class="cta-ep-home-section" aria-labelledby="cta-ep-home-flashcards-title">
			<h2 class="dashboard-section__title" id="cta-ep-home-flashcards-title"><?php esc_html_e( 'Flashcard Study Center', 'cta-lms' ); ?></h2>
			<?php include CTA_PLUGIN_DIR . 'templates/partials/exam-prep-flashcard-center.php'; ?>
		</section>

		<section class="cta-ep-home-section" aria-labelledby="cta-ep-home-exams-title">
			<h2 class="dashboard-section__title" id="cta-ep-home-exams-title"><?php esc_html_e( 'Practice Exams', 'cta-lms' ); ?></h2>
			<?php include CTA_PLUGIN_DIR . 'templates/partials/exam-prep-exam-center.php'; ?>
		</section>

		<?php
		do_action( 'cta_exam_prep_course_home_after_getting_started', $course, $modules, $enrollment );
		?>
	</div>
</div>
</div>
