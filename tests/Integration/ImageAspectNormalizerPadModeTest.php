<?php
declare(strict_types=1);

final class ImageAspectNormalizerPadModeTest extends LL_Tools_TestCase
{
    public function test_normalize_apply_operation_defaults_to_crop(): void
    {
        $this->assertSame('crop', ll_tools_aspect_normalizer_normalize_apply_operation(''));
        $this->assertSame('crop', ll_tools_aspect_normalizer_normalize_apply_operation('anything'));
        $this->assertSame('crop', ll_tools_aspect_normalizer_normalize_apply_operation('CROP'));
    }

    public function test_normalize_apply_operation_accepts_pad(): void
    {
        $this->assertSame('pad', ll_tools_aspect_normalizer_normalize_apply_operation('pad'));
        $this->assertSame('pad', ll_tools_aspect_normalizer_normalize_apply_operation('PAD'));
    }

    public function test_compute_padding_box_adds_left_and_right_padding_for_wider_ratio(): void
    {
        $box = ll_tools_aspect_normalizer_compute_padding_box(600, 800, '4:3');

        $this->assertSame(1067, (int) ($box['canvas_width'] ?? 0));
        $this->assertSame(800, (int) ($box['canvas_height'] ?? 0));
        $this->assertSame(233, (int) ($box['offset_x'] ?? 0));
        $this->assertSame(0, (int) ($box['offset_y'] ?? 0));
    }

    public function test_compute_padding_box_adds_top_and_bottom_padding_for_taller_ratio(): void
    {
        $box = ll_tools_aspect_normalizer_compute_padding_box(1600, 900, '1:1');

        $this->assertSame(1600, (int) ($box['canvas_width'] ?? 0));
        $this->assertSame(1600, (int) ($box['canvas_height'] ?? 0));
        $this->assertSame(0, (int) ($box['offset_x'] ?? 0));
        $this->assertSame(350, (int) ($box['offset_y'] ?? 0));
    }

    public function test_compute_padding_box_keeps_size_when_ratio_matches(): void
    {
        $box = ll_tools_aspect_normalizer_compute_padding_box(800, 600, '4:3');

        $this->assertSame(800, (int) ($box['canvas_width'] ?? 0));
        $this->assertSame(600, (int) ($box['canvas_height'] ?? 0));
        $this->assertSame(0, (int) ($box['offset_x'] ?? 0));
        $this->assertSame(0, (int) ($box['offset_y'] ?? 0));
    }

    public function test_parse_attachment_id_list_normalizes_and_filters_values(): void
    {
        $ids = ll_tools_aspect_normalizer_parse_attachment_id_list([0, 5, '7', 'invalid', 5, -1]);
        $this->assertSame([5, 7], array_values($ids));

        $ids_from_json = ll_tools_aspect_normalizer_parse_attachment_id_list('[9, "11", 0, "bad", 9]');
        $this->assertSame([9, 11], array_values($ids_from_json));
    }
}
