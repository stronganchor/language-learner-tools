<?php
declare(strict_types=1);

final class VocabPrivacyVisibilityTest extends LL_Tools_TestCase
{
    public function test_private_wordset_is_hidden_from_public_queries_and_rest_routes(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'REST Private Wordset', 'rest-private-wordset');
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');

        $category_id = $this->ensure_term('word-category', 'REST Visible Category', 'rest-visible-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'REST Hidden Wordset Word', 'REST Hidden Wordset Translation');

        wp_set_current_user(0);
        $this->assertFalse(ll_tools_user_can_view_wordset($wordset_id));
        $this->assertFalse(ll_tools_user_can_view_vocab_post($word_id));

        $wordset_collection = $this->dispatch_rest_request('GET', '/wp/v2/wordsets');
        $this->assertSame(200, $wordset_collection->get_status());
        $this->assertNotContains($wordset_id, $this->response_ids($wordset_collection));

        $word_collection = $this->dispatch_rest_request('GET', '/wp/v2/words');
        $this->assertSame(200, $word_collection->get_status());
        $this->assertNotContains($word_id, $this->response_ids($word_collection));

        $wordset_item = $this->dispatch_rest_request('GET', '/wp/v2/wordsets/' . $wordset_id);
        $this->assertSame(404, $wordset_item->get_status());

        $word_item = $this->dispatch_rest_request('GET', '/wp/v2/words/' . $word_id);
        $this->assertSame(404, $word_item->get_status());
    }

    public function test_private_category_is_hidden_from_public_queries_and_rest_routes(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'REST Public Wordset', 'rest-public-wordset');
        $category_id = $this->ensure_term('word-category', 'REST Private Category', 'rest-private-category');
        update_term_meta($category_id, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');

        $word_id = $this->create_word($wordset_id, [$category_id], 'REST Hidden Category Word', 'REST Hidden Category Translation');

        wp_set_current_user(0);
        $this->assertFalse(ll_tools_user_can_view_category($category_id));
        $this->assertFalse(ll_tools_user_can_view_vocab_post($word_id));

        $category_collection = $this->dispatch_rest_request('GET', '/wp/v2/word-category');
        $this->assertSame(200, $category_collection->get_status());
        $this->assertNotContains($category_id, $this->response_ids($category_collection));

        $word_item = $this->dispatch_rest_request('GET', '/wp/v2/words/' . $word_id);
        $this->assertSame(404, $word_item->get_status());

        $category_item = $this->dispatch_rest_request('GET', '/wp/v2/word-category/' . $category_id);
        $this->assertSame(404, $category_item->get_status());
    }

    public function test_rest_word_payload_redacts_inaccessible_category_ids_when_word_remains_public(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'REST Mixed Wordset', 'rest-mixed-wordset');
        $public_category_id = $this->ensure_term('word-category', 'REST Mixed Public Category', 'rest-mixed-public-category');
        $private_category_id = $this->ensure_term('word-category', 'REST Mixed Private Category', 'rest-mixed-private-category');
        update_term_meta($private_category_id, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');

        $word_id = $this->create_word(
            $wordset_id,
            [$public_category_id, $private_category_id],
            'REST Mixed Category Word',
            'REST Mixed Category Translation'
        );

        wp_set_current_user(0);

        $word_item = $this->dispatch_rest_request('GET', '/wp/v2/words/' . $word_id);
        $this->assertSame(200, $word_item->get_status());

        $data = $word_item->get_data();
        $this->assertIsArray($data);
        $category_ids = array_map('intval', (array) ($data['word-category'] ?? []));
        sort($category_ids, SORT_NUMERIC);

        $debug = sprintf(
            'public=%d private=%d actual=%s',
            $public_category_id,
            $private_category_id,
            wp_json_encode($category_ids)
        );
        $this->assertNotContains($private_category_id, $category_ids, $debug);
        $this->assertCount(1, $category_ids, $debug);

        $visible_term = get_term((int) ($category_ids[0] ?? 0), 'word-category');
        $this->assertInstanceOf(WP_Term::class, $visible_term, $debug);
        $this->assertTrue(ll_tools_user_can_view_category($visible_term), $debug);
    }

    private function dispatch_rest_request(string $method, string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        $response = rest_get_server()->dispatch($request);
        $this->assertNotWPError($response);

        return rest_ensure_response($response);
    }

    /**
     * @return int[]
     */
    private function response_ids(WP_REST_Response $response): array
    {
        $data = $response->get_data();
        $ids = [];
        foreach ((array) $data as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function ensure_term(string $taxonomy, string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertFalse(is_wp_error($created));
        $this->assertIsArray($created);

        return (int) $created['term_id'];
    }

    /**
     * @param int[] $category_ids
     */
    private function create_word(int $wordset_id, array $category_ids, string $title, string $translation): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        update_post_meta($word_id, 'word_translation', $translation);
        wp_set_post_terms($word_id, $category_ids, 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        return (int) $word_id;
    }
}
