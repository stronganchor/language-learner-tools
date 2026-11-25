<?php
/** Ordinary published recording fixtures, generated locally; no pronunciation claim. */
require '/var/www/wordboat/wp-load.php';
$state = json_decode(file_get_contents('/evidence/wordpress-state.json'), true);
wp_set_current_user(get_user_by('login', 'interop_admin')->ID);
if (!term_exists('isolation', 'recording_type')) { wp_insert_term('Isolation', 'recording_type', ['slug' => 'isolation']); }
$uploads = wp_upload_dir();
wp_mkdir_p($uploads['basedir'] . '/ll-moodle-interop');
foreach ($state['word_ids'] as $word) {
    $existing = get_posts(['post_type' => 'word_audio', 'post_status' => 'publish', 'post_parent' => $word, 'fields' => 'ids', 'posts_per_page' => 1]);
    if ($existing) { continue; }
    $samples = '';
    for ($i = 0; $i < 2000; $i++) { $samples .= pack('v', ((int) (sin(2 * M_PI * (440 + $word * 20) * $i / 8000) * 2000)) & 65535); }
    $wav = 'RIFF' . pack('V', 36 + strlen($samples)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', strlen($samples)) . $samples;
    $name = '/ll-moodle-interop/fixture-' . $word . '.wav';
    file_put_contents($uploads['basedir'] . $name, $wav);
    $audio = wp_insert_post(['post_type' => 'word_audio', 'post_status' => 'publish', 'post_parent' => $word, 'post_title' => 'Non-speech interoperability fixture ' . $word], true);
    if (is_wp_error($audio)) { throw new RuntimeException($audio->get_error_code()); }
    update_post_meta($audio, 'audio_file_path', $uploads['baseurl'] . $name);
    update_post_meta($audio, 'audio_credit', 'Generated non-speech test tone; disposable fixture only');
    wp_set_object_terms($audio, ['isolation'], 'recording_type');
}
ll_tools_bump_category_cache_version([$state['category_id']]);
echo "Five local fixture WAV recordings are available through normal published recording posts.\n";
