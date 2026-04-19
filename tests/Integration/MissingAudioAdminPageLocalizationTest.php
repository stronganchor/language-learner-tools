<?php
declare(strict_types=1);

final class MissingAudioAdminPageLocalizationTest extends LL_Tools_TestCase
{
    public function test_missing_audio_admin_page_renders_turkish_controls(): void
    {
        $messages = require LL_TOOLS_BASE_PATH . 'languages/ll-tools-text-domain-tr_TR.l10n.php';
        $this->assertIsArray($messages);
        $this->assertArrayHasKey('messages', $messages);
        $this->assertSame('Tüm Yazıları Eksik Ses İçin Tara', $messages['messages']['Scan All Posts for Missing Audio'] ?? null);
        $this->assertSame('Etiket / yorum', $messages['messages']['Label / comment'] ?? null);
        $this->assertSame('Henüz kaydedilmiş kalıp yok.', $messages['messages']['No saved patterns yet.'] ?? null);
        $this->assertSame('Geçerli Eksik Sesler', $messages['messages']['Current Missing Audio'] ?? null);
        $this->assertSame('Doğrudan medya URL\'sini buraya yapıştır.', $messages['messages']['Paste the direct media URL here.'] ?? null);
    }
}
