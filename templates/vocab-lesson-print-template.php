<?php
if (!defined('WPINC')) { die; }

$display_name = isset($display_name) ? trim((string) $display_name) : '';
$print_items = isset($print_items) && is_array($print_items) ? array_values($print_items) : [];
$print_settings = isset($print_settings) && is_array($print_settings) ? $print_settings : [];
$show_text = !empty($print_settings['show_text']);
$show_translations = !empty($print_settings['show_translations']);
$print_request_allowed = !empty($print_request_allowed);
$print_error_status = isset($print_error_status) ? (int) $print_error_status : 404;
$auto_print = !empty($auto_print);
$items_per_page = (int) apply_filters('ll_tools_vocab_lesson_print_items_per_page', 12, $lesson_id ?? 0, $wordset_id ?? 0, $category_id ?? 0);
if ($items_per_page < 1) {
    $items_per_page = 12;
}
$pages = !empty($print_items) ? array_chunk($print_items, $items_per_page) : [];
$document_title = ($display_name !== '')
    ? sprintf(
        /* translators: %s is the lesson/category title. */
        __('%s - Print Lesson', 'll-tools-text-domain'),
        $display_name
    )
    : __('Print Lesson', 'll-tools-text-domain');
$print_image_size = apply_filters('ll_tools_vocab_lesson_print_image_size', 'large', $wordset_id ?? 0, $category ?? null);
if (!is_string($print_image_size) && !is_array($print_image_size)) {
    $print_image_size = 'large';
}
if (is_string($print_image_size)) {
    $print_image_size = trim($print_image_size);
    if ($print_image_size === '') {
        $print_image_size = 'large';
    }
}
$viewport_content = function_exists('ll_tools_get_locked_viewport_content')
    ? ll_tools_get_locked_viewport_content()
    : 'width=device-width, initial-scale=1';

$render_print_card = static function (array $item) use ($print_image_size, $show_text, $show_translations): void {
    $attachment_id = isset($item['attachment_id']) ? (int) $item['attachment_id'] : 0;
    $label = isset($item['label']) ? trim((string) $item['label']) : '';
    if ($attachment_id <= 0 || $label === '') {
        return;
    }

    $alt = isset($item['alt']) ? trim((string) $item['alt']) : $label;
    $word_text = isset($item['word_text']) ? trim((string) $item['word_text']) : $label;
    $translation_text = isset($item['translation_text']) ? trim((string) $item['translation_text']) : '';
    $show_captions = ($show_text && $word_text !== '') || ($show_translations && $translation_text !== '');
    $image_html = wp_get_attachment_image($attachment_id, $print_image_size, false, [
        'class' => 'll-vocab-lesson-print-image',
        'loading' => 'eager',
        'decoding' => 'sync',
        'fetchpriority' => 'high',
        'alt' => $alt,
        'data-ll-vocab-lesson-print-image' => '1',
    ]);
    if (!is_string($image_html) || $image_html === '') {
        return;
    }
    ?>
    <article
        class="ll-vocab-lesson-print-card"
        data-word-id="<?php echo esc_attr((int) ($item['word_id'] ?? 0)); ?>"
        data-label="<?php echo esc_attr($label); ?>"
        data-word-text="<?php echo esc_attr($word_text); ?>"
        data-translation-text="<?php echo esc_attr($translation_text); ?>">
        <div class="ll-vocab-lesson-print-card__media">
            <?php echo $image_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <div class="ll-vocab-lesson-print-card__captions" <?php if (!$show_captions) : ?>hidden<?php endif; ?>>
            <div class="ll-vocab-lesson-print-card__text" dir="auto" <?php if (!$show_text || $word_text === '') : ?>hidden<?php endif; ?>>
                <?php echo esc_html($word_text); ?>
            </div>
            <div class="ll-vocab-lesson-print-card__translation" dir="auto" <?php if (!$show_translations || $translation_text === '') : ?>hidden<?php endif; ?>>
                <?php echo esc_html($translation_text); ?>
            </div>
        </div>
    </article>
    <?php
};
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="<?php echo esc_attr($viewport_content); ?>">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html($document_title); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class($print_request_allowed ? ['ll-vocab-lesson-print-body'] : ['ll-vocab-lesson-print-body', 'll-vocab-lesson-print-body--error']); ?>>
    <?php wp_body_open(); ?>
    <main
        class="ll-vocab-lesson-print-page"
        data-ll-vocab-lesson-print-root
        data-items-per-page="<?php echo esc_attr($items_per_page); ?>"
        data-show-text="<?php echo $show_text ? '1' : '0'; ?>"
        data-show-translations="<?php echo $show_translations ? '1' : '0'; ?>"
        data-title="<?php echo esc_attr($display_name !== '' ? $display_name : __('Print Lesson', 'll-tools-text-domain')); ?>">
        <?php if (!$print_request_allowed) : ?>
            <?php
            $error_title = ($print_error_status === 404)
                ? __('Lesson not found.', 'll-tools-text-domain')
                : __('Print view unavailable.', 'll-tools-text-domain');
            $error_message = ($print_error_status === 404)
                ? __('This lesson is not available.', 'll-tools-text-domain')
                : __('This print view is not available right now.', 'll-tools-text-domain');
            ?>
            <section class="ll-vocab-lesson-print-state ll-vocab-lesson-print-state--error">
                <h1 class="ll-vocab-lesson-print-state__title"><?php echo esc_html($error_title); ?></h1>
                <p class="ll-vocab-lesson-print-state__message"><?php echo esc_html($error_message); ?></p>
            </section>
        <?php elseif (empty($pages)) : ?>
            <section class="ll-vocab-lesson-print-state ll-vocab-lesson-print-state--empty">
                <h1 class="ll-vocab-lesson-print-state__title"><?php echo esc_html($display_name !== '' ? $display_name : __('Print Lesson', 'll-tools-text-domain')); ?></h1>
                <p class="ll-vocab-lesson-print-state__message"><?php echo esc_html__('No printable images were found for this lesson yet.', 'll-tools-text-domain'); ?></p>
            </section>
        <?php else : ?>
            <section class="ll-vocab-lesson-print-toolbar" data-ll-vocab-lesson-print-toolbar hidden>
                <div class="ll-vocab-lesson-print-toolbar__group">
                    <label class="ll-vocab-lesson-print-toggle">
                        <input type="checkbox" data-ll-vocab-lesson-print-toggle="text" <?php checked($show_text); ?> />
                        <span><?php echo esc_html__('Text', 'll-tools-text-domain'); ?></span>
                    </label>
                    <label class="ll-vocab-lesson-print-toggle">
                        <input type="checkbox" data-ll-vocab-lesson-print-toggle="translations" <?php checked($show_translations); ?> />
                        <span><?php echo esc_html__('Translations', 'll-tools-text-domain'); ?></span>
                    </label>
                </div>
                <div class="ll-vocab-lesson-print-toolbar__group ll-vocab-lesson-print-toolbar__group--actions">
                    <button type="button" class="ll-vocab-lesson-print-toolbar__button ll-vocab-lesson-print-toolbar__button--secondary" data-ll-vocab-lesson-print-restore-all hidden>
                        <?php echo esc_html__('Restore all', 'll-tools-text-domain'); ?>
                    </button>
                    <button type="button" class="ll-vocab-lesson-print-toolbar__button ll-vocab-lesson-print-toolbar__button--primary" data-ll-vocab-lesson-print-trigger>
                        <?php echo esc_html__('Print', 'll-tools-text-domain'); ?>
                    </button>
                </div>
            </section>

            <section class="ll-vocab-lesson-print-removed" data-ll-vocab-lesson-print-removed hidden>
                <div class="ll-vocab-lesson-print-removed__title"><?php echo esc_html__('Removed', 'll-tools-text-domain'); ?></div>
                <div class="ll-vocab-lesson-print-removed__list" data-ll-vocab-lesson-print-removed-list></div>
            </section>

            <div class="ll-vocab-lesson-print-canvas" data-ll-vocab-lesson-print-canvas>
                <?php foreach ($pages as $page_index => $page_items) : ?>
                    <section class="ll-vocab-lesson-print-sheet">
                        <h1 class="ll-vocab-lesson-print-sheet__title">
                            <?php echo esc_html($display_name !== '' ? $display_name : __('Print Lesson', 'll-tools-text-domain')); ?>
                        </h1>
                        <div class="ll-vocab-lesson-print-grid" data-ll-vocab-lesson-print-grid data-page-index="<?php echo esc_attr($page_index + 1); ?>">
                            <?php foreach ($page_items as $item) : ?>
                                <?php $render_print_card($item); ?>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <?php if ($auto_print) : ?>
        <script>
        (function () {
            var didPrint = false;

            function getImages() {
                return Array.prototype.slice.call(document.querySelectorAll('[data-ll-vocab-lesson-print-image]'));
            }

            function triggerPrint() {
                if (didPrint) {
                    return;
                }
                didPrint = true;
                window.setTimeout(function () {
                    window.print();
                }, 160);
            }

            function waitForImagesAndPrint() {
                var images = getImages();
                if (!images.length) {
                    triggerPrint();
                    return;
                }

                var pending = 0;
                function onDone() {
                    pending -= 1;
                    if (pending <= 0) {
                        triggerPrint();
                    }
                }

                images.forEach(function (image) {
                    if (image.complete) {
                        return;
                    }
                    pending += 1;
                    image.addEventListener('load', onDone, { once: true });
                    image.addEventListener('error', onDone, { once: true });
                });

                if (pending <= 0) {
                    triggerPrint();
                    return;
                }

                window.setTimeout(function () {
                    if (!didPrint) {
                        triggerPrint();
                    }
                }, 1400);
            }

            if (document.readyState === 'complete') {
                waitForImagesAndPrint();
            } else {
                window.addEventListener('load', waitForImagesAndPrint, { once: true });
            }
        })();
        </script>
    <?php endif; ?>
    <?php wp_footer(); ?>
</body>
</html>
