<?php
if (!defined('WPINC')) { die; }

$display_name = isset($display_name) ? trim((string) $display_name) : '';
$print_items = isset($print_items) && is_array($print_items) ? array_values($print_items) : [];
$print_request_allowed = !empty($print_request_allowed);
$print_error_status = isset($print_error_status) ? (int) $print_error_status : 403;
$auto_print = !empty($auto_print);
$items_per_page = (int) apply_filters('ll_tools_vocab_lesson_print_items_per_page', 12, $lesson_id ?? 0, $wordset_id ?? 0, $category_id ?? 0);
if ($items_per_page < 1) {
    $items_per_page = 12;
}
$pages = !empty($print_items) ? array_chunk($print_items, $items_per_page) : [];
$document_title = ($display_name !== '')
    ? sprintf(
        /* translators: %s is the lesson/category title. */
        __('%s - Print Images', 'll-tools-text-domain'),
        $display_name
    )
    : __('Print Images', 'll-tools-text-domain');
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
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html($document_title); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class($print_request_allowed ? ['ll-vocab-lesson-print-body'] : ['ll-vocab-lesson-print-body', 'll-vocab-lesson-print-body--error']); ?>>
    <?php wp_body_open(); ?>
    <main class="ll-vocab-lesson-print-page">
        <?php if (!$print_request_allowed) : ?>
            <?php
            $error_title = ($print_error_status === 404)
                ? __('Lesson not found.', 'll-tools-text-domain')
                : __('Print view unavailable.', 'll-tools-text-domain');
            $error_message = ($print_error_status === 404)
                ? __('This lesson is not available.', 'll-tools-text-domain')
                : __('You do not have permission to print this lesson image sheet.', 'll-tools-text-domain');
            ?>
            <section class="ll-vocab-lesson-print-state ll-vocab-lesson-print-state--error">
                <h1 class="ll-vocab-lesson-print-state__title"><?php echo esc_html($error_title); ?></h1>
                <p class="ll-vocab-lesson-print-state__message"><?php echo esc_html($error_message); ?></p>
            </section>
        <?php elseif (empty($pages)) : ?>
            <section class="ll-vocab-lesson-print-state ll-vocab-lesson-print-state--empty">
                <h1 class="ll-vocab-lesson-print-state__title"><?php echo esc_html($display_name !== '' ? $display_name : __('Print Images', 'll-tools-text-domain')); ?></h1>
                <p class="ll-vocab-lesson-print-state__message"><?php echo esc_html__('No printable images were found for this lesson yet.', 'll-tools-text-domain'); ?></p>
            </section>
        <?php else : ?>
            <?php foreach ($pages as $page_index => $page_items) : ?>
                <section class="ll-vocab-lesson-print-sheet">
                    <h1 class="ll-vocab-lesson-print-sheet__title">
                        <?php echo esc_html($display_name !== '' ? $display_name : __('Print Images', 'll-tools-text-domain')); ?>
                    </h1>
                    <div class="ll-vocab-lesson-print-grid" data-ll-vocab-lesson-print-grid data-page-index="<?php echo esc_attr($page_index + 1); ?>">
                        <?php foreach ($page_items as $item) : ?>
                            <?php
                            $attachment_id = isset($item['attachment_id']) ? (int) $item['attachment_id'] : 0;
                            $label = isset($item['label']) ? trim((string) $item['label']) : '';
                            if ($attachment_id <= 0 || $label === '') {
                                continue;
                            }
                            $alt = isset($item['alt']) ? trim((string) $item['alt']) : $label;
                            $image_html = wp_get_attachment_image($attachment_id, $print_image_size, false, [
                                'class' => 'll-vocab-lesson-print-image',
                                'loading' => 'eager',
                                'decoding' => 'sync',
                                'fetchpriority' => 'high',
                                'alt' => $alt,
                                'data-ll-vocab-lesson-print-image' => '1',
                            ]);
                            if (!is_string($image_html) || $image_html === '') {
                                continue;
                            }
                            ?>
                            <article class="ll-vocab-lesson-print-card" data-word-id="<?php echo esc_attr((int) ($item['word_id'] ?? 0)); ?>">
                                <?php echo $image_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

    <?php if ($auto_print) : ?>
        <script>
        (function () {
            var didPrint = false;
            var images = Array.prototype.slice.call(document.querySelectorAll('[data-ll-vocab-lesson-print-image]'));

            function triggerPrint() {
                if (didPrint) {
                    return;
                }
                didPrint = true;
                window.setTimeout(function () {
                    window.print();
                }, 160);
            }

            if (!images.length) {
                if (document.readyState === 'complete') {
                    triggerPrint();
                } else {
                    window.addEventListener('load', triggerPrint, { once: true });
                }
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
                if (document.readyState === 'complete') {
                    triggerPrint();
                } else {
                    window.addEventListener('load', triggerPrint, { once: true });
                }
                return;
            }

            window.addEventListener('load', function () {
                window.setTimeout(function () {
                    if (!didPrint) {
                        triggerPrint();
                    }
                }, 1400);
            }, { once: true });
        })();
        </script>
    <?php endif; ?>
</body>
</html>
