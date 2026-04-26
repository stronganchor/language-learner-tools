<?php
declare(strict_types=1);

final class OrphanMediaAdminTest extends LL_Tools_TestCase
{
    public function test_snapshot_storage_caps_items_without_changing_summary_totals(): void
    {
        $limit_filter = static function (): int {
            return 2;
        };
        add_filter('ll_tools_orphan_media_stored_item_limit', $limit_filter);

        try {
            $stored_snapshot = ll_tools_orphan_media_prepare_snapshot_for_storage([
                'generated_at_gmt' => '2026-04-26 12:00:00',
                'summary' => [
                    'total_count' => 3,
                    'total_bytes' => 600,
                ],
                'items' => [
                    ['kind' => 'audio', 'filename' => 'first.mp3', 'size_bytes' => 300],
                    ['kind' => 'image', 'filename' => 'second.jpg', 'size_bytes' => 200],
                    ['kind' => 'audio', 'filename' => 'third.mp3', 'size_bytes' => 100],
                ],
            ]);
        } finally {
            remove_filter('ll_tools_orphan_media_stored_item_limit', $limit_filter);
        }

        $this->assertCount(2, $stored_snapshot['items']);
        $this->assertTrue((bool) ($stored_snapshot['items_truncated'] ?? false));
        $this->assertSame(2, (int) ($stored_snapshot['stored_item_count'] ?? 0));
        $this->assertSame(2, (int) (($stored_snapshot['summary'] ?? [])['stored_item_count'] ?? 0));
        $this->assertSame(3, (int) (($stored_snapshot['summary'] ?? [])['total_count'] ?? 0));
        $this->assertSame('first.mp3', (string) (($stored_snapshot['items'][0] ?? [])['filename'] ?? ''));
        $this->assertSame('second.jpg', (string) (($stored_snapshot['items'][1] ?? [])['filename'] ?? ''));
    }

    public function test_snapshot_storage_limit_can_be_disabled(): void
    {
        $limit_filter = static function (): int {
            return 0;
        };
        add_filter('ll_tools_orphan_media_stored_item_limit', $limit_filter);

        try {
            $stored_snapshot = ll_tools_orphan_media_prepare_snapshot_for_storage([
                'generated_at_gmt' => '2026-04-26 12:00:00',
                'summary' => [
                    'total_count' => 2,
                ],
                'items' => [
                    ['kind' => 'audio', 'filename' => 'first.mp3'],
                    ['kind' => 'audio', 'filename' => 'second.mp3'],
                ],
            ]);
        } finally {
            remove_filter('ll_tools_orphan_media_stored_item_limit', $limit_filter);
        }

        $this->assertCount(2, $stored_snapshot['items']);
        $this->assertArrayNotHasKey('items_truncated', $stored_snapshot);
        $this->assertArrayNotHasKey('stored_item_count', $stored_snapshot);
    }
}
