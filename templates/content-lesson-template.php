<?php
// /templates/content-lesson-template.php
if (!defined('WPINC')) { die; }

$ll_content_lesson_access_denied = false;
if (is_singular('ll_content_lesson') && function_exists('ll_tools_user_can_view_wordset')) {
    $preflight_post_id = (int) get_queried_object_id();
    if ($preflight_post_id > 0) {
        $preflight_wordset_id = function_exists('ll_tools_get_content_lesson_wordset_id')
            ? ll_tools_get_content_lesson_wordset_id($preflight_post_id)
            : 0;
        if ($preflight_wordset_id > 0 && !ll_tools_user_can_view_wordset($preflight_wordset_id)) {
            $ll_content_lesson_access_denied = true;
            $wp_query = $GLOBALS['wp_query'] ?? null;
            if ($wp_query instanceof WP_Query) {
                $wp_query->set_404();
            } elseif (is_object($wp_query)) {
                $wp_query->is_404 = true;
            }
            status_header(404);
            nocache_headers();
        }
    }
}

get_header();

if ($ll_content_lesson_access_denied) {
    echo '<main class="ll-content-lesson-page"><div class="ll-content-lesson-empty">';
    echo esc_html__('Lesson not found.', 'll-tools-text-domain');
    echo '</div></main>';
    get_footer();
    return;
}

if (!have_posts()) {
    echo '<main class="ll-content-lesson-page"><div class="ll-content-lesson-empty">';
    echo esc_html__('Lesson not found.', 'll-tools-text-domain');
    echo '</div></main>';
    get_footer();
    return;
}

the_post();

$lesson_id = (int) get_the_ID();
$wordset = function_exists('ll_tools_get_content_lesson_wordset_term')
    ? ll_tools_get_content_lesson_wordset_term($lesson_id)
    : null;
$wordset_id = ($wordset instanceof WP_Term) ? (int) $wordset->term_id : 0;
$wordset_name = ($wordset instanceof WP_Term) ? (string) $wordset->name : '';
$wordset_url = ($wordset instanceof WP_Term) ? trailingslashit(home_url($wordset->slug)) : '';
$media_type = function_exists('ll_tools_get_content_lesson_media_type')
    ? ll_tools_get_content_lesson_media_type($lesson_id)
    : 'audio';
$media_url = function_exists('ll_tools_get_content_lesson_media_url')
    ? ll_tools_get_content_lesson_media_url($lesson_id)
    : '';
$cues = function_exists('ll_tools_get_content_lesson_cues')
    ? ll_tools_get_content_lesson_cues($lesson_id)
    : [];
$related_vocab_items = function_exists('ll_tools_get_content_lesson_related_vocab_items')
    ? ll_tools_get_content_lesson_related_vocab_items($lesson_id)
    : [];
$lesson_excerpt = has_excerpt() ? get_the_excerpt() : '';
$media_label = function_exists('ll_tools_content_lesson_media_label')
    ? ll_tools_content_lesson_media_label($media_type)
    : (($media_type === 'video') ? __('Video lesson', 'll-tools-text-domain') : __('Audio lesson', 'll-tools-text-domain'));
$cue_json = wp_json_encode($cues);
$cue_json = is_string($cue_json) ? $cue_json : '[]';
$format_ms = static function (int $ms): string {
    $seconds_total = max(0, (int) floor($ms / 1000));
    $hours = (int) floor($seconds_total / 3600);
    $minutes = (int) floor(($seconds_total % 3600) / 60);
    $seconds = $seconds_total % 60;

    if ($hours > 0) {
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    return sprintf('%02d:%02d', $minutes, $seconds);
};
?>
<main class="ll-content-lesson-page" data-ll-content-lesson>
    <header class="ll-content-lesson-hero">
        <div class="ll-content-lesson-hero__top">
            <?php if ($wordset_url !== '') : ?>
                <a class="ll-content-lesson-back ll-vocab-lesson-back" href="<?php echo esc_url($wordset_url); ?>" aria-label="<?php echo esc_attr($wordset_name !== '' ? sprintf(__('Back to %s', 'll-tools-text-domain'), $wordset_name) : __('Back to Word Set', 'll-tools-text-domain')); ?>">
                    <span class="ll-content-lesson-back__icon ll-vocab-lesson-back__icon" aria-hidden="true">
                        <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true">
                            <path d="M9.8 3.2L5 8l4.8 4.8" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="ll-content-lesson-back__label"><?php echo esc_html($wordset_name !== '' ? $wordset_name : __('Word Set', 'll-tools-text-domain')); ?></span>
                </a>
            <?php endif; ?>
            <span class="ll-content-lesson-pill"><?php echo esc_html($media_label); ?></span>
        </div>
        <div class="ll-content-lesson-hero__content">
            <h1 class="ll-content-lesson-title"><?php the_title(); ?></h1>
            <?php if ($lesson_excerpt !== '') : ?>
                <p class="ll-content-lesson-summary"><?php echo esc_html($lesson_excerpt); ?></p>
            <?php endif; ?>
        </div>
    </header>

    <section class="ll-content-lesson-stage" data-ll-content-lesson-player>
        <div class="ll-content-lesson-stage__media">
            <?php if ($media_url !== '') : ?>
                <?php if ($media_type === 'video') : ?>
                    <video class="ll-content-lesson-media" data-ll-content-lesson-media controls preload="metadata" playsinline>
                        <source src="<?php echo esc_url($media_url); ?>" />
                    </video>
                <?php else : ?>
                    <audio class="ll-content-lesson-media" data-ll-content-lesson-media controls preload="metadata">
                        <source src="<?php echo esc_url($media_url); ?>" />
                    </audio>
                <?php endif; ?>
            <?php else : ?>
                <div class="ll-content-lesson-empty">
                    <?php echo esc_html__('Add a media URL in the lesson editor to play this lesson here.', 'll-tools-text-domain'); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="ll-content-lesson-stage__transcript">
            <div class="ll-content-lesson-stage__heading-row">
                <h2 class="ll-content-lesson-stage__title"><?php echo esc_html__('Transcript', 'll-tools-text-domain'); ?></h2>
                <?php if (!empty($cues)) : ?>
                    <span class="ll-content-lesson-stage__count">
                        <?php
                        echo esc_html(sprintf(
                            _n('%d cue', '%d cues', count($cues), 'll-tools-text-domain'),
                            count($cues)
                        ));
                        ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if (!empty($cues)) : ?>
                <div class="ll-content-lesson-transcript" data-ll-content-lesson-transcript role="list" aria-label="<?php echo esc_attr__('Lesson transcript', 'll-tools-text-domain'); ?>">
                    <?php foreach ($cues as $cue) : ?>
                        <?php
                        $cue_id = isset($cue['id']) ? (int) $cue['id'] : 0;
                        $cue_start_ms = isset($cue['start_ms']) ? (int) $cue['start_ms'] : 0;
                        $cue_end_ms = isset($cue['end_ms']) ? (int) $cue['end_ms'] : 0;
                        $cue_text = isset($cue['text']) ? (string) $cue['text'] : '';
                        if ($cue_text === '' || $cue_end_ms <= $cue_start_ms) {
                            continue;
                        }
                        ?>
                        <button
                            type="button"
                            class="ll-content-lesson-transcript__cue"
                            role="listitem"
                            data-ll-content-lesson-cue
                            data-cue-id="<?php echo esc_attr((string) $cue_id); ?>"
                            data-start-ms="<?php echo esc_attr((string) $cue_start_ms); ?>"
                            data-end-ms="<?php echo esc_attr((string) $cue_end_ms); ?>"
                            aria-pressed="false">
                            <span class="ll-content-lesson-transcript__time"><?php echo esc_html($format_ms($cue_start_ms)); ?></span>
                            <span class="ll-content-lesson-transcript__text"><?php echo esc_html($cue_text); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <script type="application/json" data-ll-content-lesson-cues><?php echo $cue_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
            <?php else : ?>
                <div class="ll-content-lesson-empty">
                    <?php echo esc_html__('Add parsed transcript timing data to highlight the text during playback.', 'll-tools-text-domain'); ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php
    if (function_exists('ll_tools_render_content_lesson_related_vocab_links')) {
        echo ll_tools_render_content_lesson_related_vocab_links($related_vocab_items, [
            'title' => __('Practice This Lesson', 'll-tools-text-domain'),
            'description' => __('Open the related vocab drills for the words from this main lesson.', 'll-tools-text-domain'),
        ]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    ?>

    <?php if (trim((string) get_the_content()) !== '') : ?>
        <section class="ll-content-lesson-notes">
            <h2 class="ll-content-lessons-section__title"><?php echo esc_html__('Notes', 'll-tools-text-domain'); ?></h2>
            <div class="ll-content-lesson-notes__content">
                <?php the_content(); ?>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php

get_footer();
