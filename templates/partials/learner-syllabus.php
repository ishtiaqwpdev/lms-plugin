<?php
/**
 * Enrolled-learner syllabus (CE course outline or exam-prep program map).
 *
 * @package CTA_LMS
 *
 * @var object     $course            Course row.
 * @var array      $modules           Module rows.
 * @var array|null $learner_syllabus  Optional pre-built payload from CTA_Syllabus_Sync::get_learner_syllabus().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$syllabus = ( isset( $learner_syllabus ) && is_array( $learner_syllabus ) )
	? $learner_syllabus
	: ( class_exists( 'CTA_Syllabus_Sync' )
		? CTA_Syllabus_Sync::get_learner_syllabus( $course, isset( $modules ) ? (array) $modules : array() )
		: array() );

if ( empty( $syllabus['has_content'] ) ) {
	return;
}

$is_exam   = ! empty( $syllabus['is_exam_prep'] );
$heading   = ! empty( $syllabus['heading'] ) ? (string) $syllabus['heading'] : __( 'Syllabus', 'cta-lms' );
$info_bits = array();
if ( ! $is_exam && (float) ( $syllabus['ce_hours'] ?? 0 ) > 0 ) {
	$hours = rtrim( rtrim( number_format( (float) $syllabus['ce_hours'], 1, '.', '' ), '0' ), '.' );
	$info_bits[] = sprintf(
		/* translators: %s: CE hours */
		__( '%s CE hours', 'cta-lms' ),
		$hours
	);
}
if ( ! empty( $syllabus['classification'] ) ) {
	$info_bits[] = (string) $syllabus['classification'];
}
if ( ! empty( $syllabus['course_level'] ) ) {
	$info_bits[] = (string) $syllabus['course_level'];
}
if ( ! empty( $syllabus['instructional_method'] ) ) {
	$info_bits[] = (string) $syllabus['instructional_method'];
}
if ( ! empty( $syllabus['target_audience'] ) ) {
	$info_bits[] = (string) $syllabus['target_audience'];
}
if ( ! empty( $syllabus['presenter'] ) ) {
	$info_bits[] = sprintf(
		/* translators: %s: presenter name */
		__( 'Presenter: %s', 'cta-lms' ),
		(string) $syllabus['presenter']
	);
}

$outline_label = $is_exam
	? __( 'Program outline', 'cta-lms' )
	: __( 'Module outline', 'cta-lms' );
?>
<section class="cta-learner-syllabus" id="cta-learner-syllabus" aria-labelledby="cta-learner-syllabus-title">
	<h2 class="dashboard-section__title" id="cta-learner-syllabus-title"><?php echo esc_html( $heading ); ?></h2>

	<?php if ( ! empty( $info_bits ) ) : ?>
		<ul class="cta-learner-syllabus__facts">
			<?php foreach ( $info_bits as $bit ) : ?>
				<li><?php echo esc_html( $bit ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( ! empty( $syllabus['educational_notice'] ) ) : ?>
		<p class="cta-learner-syllabus__notice"><?php echo esc_html( (string) $syllabus['educational_notice'] ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $syllabus['description_html'] ) ) : ?>
		<div class="cta-learner-syllabus__block">
			<h3 class="cta-learner-syllabus__heading"><?php esc_html_e( 'Overview', 'cta-lms' ); ?></h3>
			<div class="cta-learner-syllabus__prose"><?php echo wp_kses_post( (string) $syllabus['description_html'] ); ?></div>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $syllabus['what_is_included'] ) ) : ?>
		<div class="cta-learner-syllabus__block">
			<h3 class="cta-learner-syllabus__heading"><?php esc_html_e( 'What is included', 'cta-lms' ); ?></h3>
			<ul class="cta-learner-syllabus__list">
				<?php foreach ( $syllabus['what_is_included'] as $item ) : ?>
					<li><?php echo esc_html( (string) $item ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $syllabus['learning_objectives'] ) ) : ?>
		<div class="cta-learner-syllabus__block">
			<h3 class="cta-learner-syllabus__heading"><?php esc_html_e( 'Learning objectives', 'cta-lms' ); ?></h3>
			<ol class="cta-learner-syllabus__list cta-learner-syllabus__list--numbered">
				<?php foreach ( $syllabus['learning_objectives'] as $item ) : ?>
					<li><?php echo esc_html( (string) $item ); ?></li>
				<?php endforeach; ?>
			</ol>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $syllabus['educational_goals'] ) ) : ?>
		<div class="cta-learner-syllabus__block">
			<h3 class="cta-learner-syllabus__heading"><?php esc_html_e( 'Educational goals', 'cta-lms' ); ?></h3>
			<ul class="cta-learner-syllabus__list">
				<?php foreach ( $syllabus['educational_goals'] as $item ) : ?>
					<li><?php echo esc_html( (string) $item ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $syllabus['who_this_is_for'] ) ) : ?>
		<div class="cta-learner-syllabus__block">
			<h3 class="cta-learner-syllabus__heading"><?php esc_html_e( 'Who this is for', 'cta-lms' ); ?></h3>
			<ul class="cta-learner-syllabus__list">
				<?php foreach ( $syllabus['who_this_is_for'] as $item ) : ?>
					<li><?php echo esc_html( (string) $item ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $syllabus['key_topics'] ) ) : ?>
		<div class="cta-learner-syllabus__block">
			<h3 class="cta-learner-syllabus__heading"><?php esc_html_e( 'Key topics', 'cta-lms' ); ?></h3>
			<ul class="cta-learner-syllabus__list">
				<?php foreach ( $syllabus['key_topics'] as $item ) : ?>
					<li><?php echo esc_html( (string) $item ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $syllabus['completion_requirements'] ) ) : ?>
		<div class="cta-learner-syllabus__block">
			<h3 class="cta-learner-syllabus__heading"><?php esc_html_e( 'Completion requirements', 'cta-lms' ); ?></h3>
			<ul class="cta-learner-syllabus__list">
				<?php foreach ( $syllabus['completion_requirements'] as $item ) : ?>
					<li><?php echo esc_html( (string) $item ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $syllabus['modules'] ) ) : ?>
		<div class="cta-learner-syllabus__block">
			<h3 class="cta-learner-syllabus__heading"><?php echo esc_html( $outline_label ); ?></h3>
			<ol class="cta-learner-syllabus__outline">
				<?php foreach ( $syllabus['modules'] as $index => $mod ) : ?>
					<li class="cta-learner-syllabus__unit">
						<div class="cta-learner-syllabus__unit-head">
							<span class="cta-learner-syllabus__unit-num"><?php echo esc_html( (string) ( (int) $index + 1 ) ); ?></span>
							<div>
								<strong class="cta-learner-syllabus__unit-title"><?php echo esc_html( (string) ( $mod['title'] ?? '' ) ); ?></strong>
								<?php if ( ! empty( $mod['duration_mins'] ) ) : ?>
									<span class="cta-learner-syllabus__unit-meta">
										<?php
										printf(
											/* translators: %d: minutes */
											esc_html__( '%d minutes', 'cta-lms' ),
											(int) $mod['duration_mins']
										);
										?>
									</span>
								<?php endif; ?>
							</div>
						</div>
						<?php if ( ! empty( $mod['description_html'] ) ) : ?>
							<div class="cta-learner-syllabus__unit-body"><?php echo wp_kses_post( (string) $mod['description_html'] ); ?></div>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $syllabus['references'] ) ) : ?>
		<details class="cta-learner-syllabus__refs">
			<summary><?php esc_html_e( 'References', 'cta-lms' ); ?></summary>
			<ul class="cta-learner-syllabus__list">
				<?php foreach ( $syllabus['references'] as $item ) : ?>
					<li><?php echo esc_html( (string) $item ); ?></li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>
</section>
