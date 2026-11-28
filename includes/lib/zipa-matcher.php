<?php

/**
 * Match a recognizer's IPA to a deck using an explicit wordset profile.
 */
final class LL_Tools_Zipa_Matcher {
    private const MAX_BURST_SEGMENTS = 8;

    private array $profile;
    private string $confusables_pattern;
    private string $joined_confusables_pattern;
    private string $rounded_vowels_pattern;
    private array $rounded_vowels_longest_first;

    public function __construct(array $profile) {
        $this->profile = $profile + [
            'inputSymbols' => [],
            'ignorableIpaMarks' => [],
            'ipaReplacements' => [],
            'lowercaseMap' => [],
            'uppercaseMap' => [],
            'closeForms' => [],
            'confusableGraphemes' => [],
            'joinedConfusables' => [],
            'internalJoinedConfusables' => [],
            'repairableConsonants' => '',
            'repairableVowels' => '',
            'openVowelAlternatives' => [],
            'roundedVowels' => [],
            'roundedVowelOnsets' => '',
            'displayRules' => [],
        ];
        $this->confusables_pattern = self::alternation_pattern(array_keys($this->profile['confusableGraphemes']), '|.');
        $this->joined_confusables_pattern = self::alternation_pattern($this->profile['joinedConfusables']);
        $this->rounded_vowels_longest_first = self::longest_first($this->profile['roundedVowels']);
        $this->rounded_vowels_pattern = self::alternation_pattern($this->rounded_vowels_longest_first);
    }

    /** Match configured multi-letter units before their component letters. */
    private static function longest_first(array $units): array {
        usort($units, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
        return $units;
    }

    private static function alternation_pattern(array $units, string $suffix = ''): string {
        if ($units === []) {
            return $suffix === '' ? '' : '/./u';
        }
        $quoted = array_map(static fn(string $unit): string => preg_quote($unit, '/'), self::longest_first($units));
        return '/' . implode('|', $quoted) . $suffix . '/u';
    }

    private function rounded_vowel_at(string $text): ?string {
        foreach ($this->rounded_vowels_longest_first as $unit) {
            if (str_starts_with($text, $unit)) {
                return $unit;
            }
        }
        return null;
    }

    public function normalize(string $ipa): string {
        if (strlen($ipa) > 256 || preg_match('//u', $ipa) !== 1) {
            return '';
        }

        $ipa = trim($ipa);
        if ((str_starts_with($ipa, '[') && str_ends_with($ipa, ']'))
            || (str_starts_with($ipa, '/') && str_ends_with($ipa, '/'))) {
            $ipa = trim(substr($ipa, 1, -1));
        }

        $ipa = strtr($ipa, $this->profile['inputSymbols']);
        $ipa = trim((string) preg_replace('/\s+/u', ' ', $ipa));
        // U+0295 is an IPA letter classified as uncased by newer Unicode versions.
        if (preg_match('/\A[\p{Ll}\p{Lm}\p{M}ʕːˑ. ]+\z/u', $ipa) !== 1
            || preg_match('/[\p{Ll}ʕ]/u', $ipa) !== 1) {
            return '';
        }

        $ipa = str_replace($this->profile['ignorableIpaMarks'], '', $ipa);
        if (isset($this->profile['tiedPairs'])) {
            $ipa = (string) preg_replace_callback(
                '/([\p{Ll}\p{Lm}ʕ])((?:(?!͡)\p{M})*)͡+(?=([\p{Ll}\p{Lm}ʕ]))/u',
                fn(array $match): string => $match[1] . $match[2]
                    . (in_array($match[1] . $match[3], $this->profile['tiedPairs'], true) ? '͡' : ''),
                $ipa
            );
        }
        foreach ($this->profile['ipaReplacements'] as $rule) {
            $ipa = self::apply_ipa_replacement($ipa, $rule);
            if (strlen($ipa) > 256) {
                return '';
            }
        }
        return strlen($ipa) <= 256 ? $ipa : '';
    }

    /** Apply one literal replacement, inspecting the original neighbors at each occurrence. */
    private static function apply_ipa_replacement(string $text, array $rule): string {
        $source = $rule['from'];
        if ($source === '') {
            return $text;
        }
        $left = $rule['precededBy'] ?? [];
        $right = $rule['followedBy'] ?? [];
        if ($left === [] && $right === []) {
            return str_replace($source, $rule['to'], $text);
        }

        $ignored = $rule['ignorePreceding'] ?? '';
        $result = '';
        $length = strlen($source);
        $offset = 0;
        while (($start = strpos($text, $source, $offset)) !== false) {
            $end = $start + $length;
            $result .= substr($text, $offset, $start - $offset);
            $prefix = substr($text, 0, $start);
            if ($ignored !== '' && $left !== []) {
                $prefix = (string) preg_replace('/[' . preg_quote($ignored, '/') . ']+\z/u', '', $prefix);
            }
            $eligible = self::literal_context_matches($prefix, $left, true)
                && self::literal_context_matches(substr($text, $end), $right, false);
            $result .= $eligible ? $rule['to'] : $source;
            $offset = $end;
        }
        return $result . substr($text, $offset);
    }

    private static function literal_context_matches(string $text, array $contexts, bool $at_end): bool {
        if ($contexts === []) {
            return true;
        }
        foreach ($contexts as $context) {
            if ($context === '' ? $text === '' : ($at_end ? str_ends_with($text, $context) : str_starts_with($text, $context))) {
                return true;
            }
        }
        return false;
    }

    /** Build a deck-aware display renderer once for an entire scoring attempt. */
    public function create_display(array $cards): Closure {
        $titles = [];
        foreach ($cards as $card) {
            $title = $card['title'] ?? '';
            foreach ([$card['recording_ipa'] ?? $card['ipa'] ?? '', ...($card['ipaVariants'] ?? [])] as $raw) {
                $ipa = $this->normalize($raw);
                if ($ipa === '') {
                    continue;
                }
                if (!array_key_exists($ipa, $titles)) {
                    $titles[$ipa] = $title;
                } elseif ($titles[$ipa] !== $title) {
                    $titles[$ipa] = null;
                }
            }
        }

        $rules = $this->profile['displayRules'];
        return function (string $transcript) use ($titles, $rules): string {
            $ipa = $this->normalize($transcript);
            if ($ipa === '') {
                return '';
            }
            if (!empty($titles[$ipa])) {
                return $titles[$ipa];
            }

            $words = [];
            foreach (explode(' ', $ipa) as $word) {
                $spelling = self::spell_word($word, $rules);
                if ($spelling === '') {
                    return '';
                }
                $words[] = $spelling;
            }
            return (string) preg_replace_callback('/\p{L}/u', function (array $match): string {
                $letter = $match[0];
                return $this->profile['uppercaseMap'][$letter]
                    ?? (function_exists('mb_strtoupper') ? mb_strtoupper($letter, 'UTF-8') : strtoupper($letter));
            }, implode(' ', $words), 1);
        };
    }

    public function display(string $transcript, array $cards): string {
        return $this->create_display($cards)($transcript);
    }

    private static function spell_word(string $word, array $rules): string {
        $ipa = str_replace($rules['ignoredMarks'] ?? [], '', $word);
        $phones = $rules['phones'] ?? [];
        $final_phones = $rules['finalPhones'] ?? [];
        $spelling = '';
        for ($offset = 0, $length = strlen($ipa); $offset < $length;) {
            foreach ($rules['clusters'] ?? [] as [$unit, $output]) {
                if ($unit !== '' && str_starts_with(substr($ipa, $offset), $unit)) {
                    $spelling .= $output;
                    $offset += strlen($unit);
                    continue 2;
                }
            }
            if (preg_match('/./us', $ipa, $matches, 0, $offset) !== 1) {
                return '';
            }
            $phone = $matches[0];
            $offset += strlen($phone);
            $output = ($offset === $length ? ($final_phones[$phone] ?? null) : null) ?? ($phones[$phone] ?? null);
            if ($output === null) {
                return '';
            }
            $spelling .= $output;
        }
        return $spelling;
    }

    private function normalize_spelling(string $spelling): string {
        $spelling = strtr(trim($spelling), $this->profile['lowercaseMap'] + ['‘' => "'", '’' => "'", 'ʼ' => "'"]);
        $spelling = function_exists('mb_strtolower') ? mb_strtolower($spelling, 'UTF-8') : strtolower($spelling);
        return preg_match("/\A[\p{L}\p{M}'-]+\z/u", $spelling) === 1 ? $spelling : '';
    }

    private function spelling_neighbors(string $spelling): array {
        preg_match_all($this->confusables_pattern, $spelling, $matches);
        $neighbors = [];
        foreach ($matches[0] as $index => $grapheme) {
            if (isset($this->profile['confusableGraphemes'][$grapheme])) {
                $variant = $matches[0];
                $variant[$index] = $this->profile['confusableGraphemes'][$grapheme];
                $neighbors[implode('', $variant)] = true;
            }
        }
        return $neighbors;
    }

    private function duplicate_spelling_repairs(string $spelling): array {
        preg_match_all('/./u', $spelling, $matches);
        $repairs = [];
        $letters = $matches[0];
        foreach ($letters as $index => $letter) {
            if ($letter !== ($letters[$index + 1] ?? null)) {
                continue;
            }
            $reason = match (true) {
                str_contains($this->profile['repairableVowels'], $letter) => 'duplicate_vowel_match',
                str_contains($this->profile['repairableConsonants'], $letter) => 'duplicate_consonant_match',
                default => '',
            };
            if ($reason !== '') {
                $variant = $letters;
                array_splice($variant, $index, 1);
                $repairs[implode('', $variant)] = $reason;
            }
        }
        return $repairs;
    }

    private function vowel_spelling_repairs(string $spelling): array {
        preg_match_all('/./u', $spelling, $matches);
        $letters = $matches[0];
        $repairs = [];
        foreach ($letters as $index => $letter) {
            $unit = $this->rounded_vowel_at(implode('', array_slice($letters, $index))) ?? $letter;
            $rounds_after_consonant = $index > 0 && str_contains($this->profile['roundedVowelOnsets'], $letters[$index - 1])
                && in_array($unit, $this->profile['roundedVowels'], true);
            $alternatives = $rounds_after_consonant ? $this->profile['roundedVowels'] : ($this->profile['openVowelAlternatives'][$letter] ?? []);
            foreach ($alternatives as $replacement) {
                if ($replacement === $unit) {
                    continue;
                }
                $variant = $letters;
                array_splice($variant, $index, mb_strlen($unit, 'UTF-8'), [$replacement]);
                $repairs[implode('', $variant)] = true;
            }
        }
        return $repairs;
    }

    /**
     * @param array<int, list<string>> $references Reviewed IPA variants keyed by card ID.
     * Resolve exact forms across the lesson before considering specific model confusions.
     */
    public function score(
        string $transcript,
        array $references,
        bool $allow_close = false,
        string $heard_spelling = '',
        array $spellings = []
    ): array {
        $normalized = $this->normalize($transcript);
        $heard = $normalized === '' ? '' : $this->normalize_spelling($heard_spelling);
        $neighbors = $allow_close ? $this->spelling_neighbors($heard) : [];
        $repairs = $this->duplicate_spelling_repairs($heard);
        $vowel_repairs = $this->vowel_spelling_repairs($heard);
        $phonemic_targets = [];
        $spelling_targets = [];
        $repaired_targets = [];
        $repair_reasons = [];
        $close_targets = [];
        $candidate_count = 0;

        foreach ($references as $raw_id => $variants) {
            $word_id = (int) $raw_id;
            if (preg_match('/\A[1-9][0-9]*\z/D', (string) $raw_id) !== 1
                || (float) $raw_id > 9007199254740991 || !is_array($variants)) {
                continue;
            }
            $forms = [];
            foreach ($variants as $variant) {
                if (is_string($variant)) {
                    $form = $this->normalize($variant);
                    if ($form !== '') {
                        $forms[$form] = true;
                    }
                }
            }
            if ($forms === []) {
                continue;
            }
            ++$candidate_count;
            if (isset($forms[$normalized])) {
                $phonemic_targets[$word_id] = $normalized;
            }
            $target = array_key_first($forms);
            $spelling = $heard === '' ? '' : $this->normalize_spelling($spellings[$word_id] ?? '');
            if ($spelling !== '' && $spelling === $heard) {
                $spelling_targets[$word_id] = $target;
            }
            if (isset($repairs[$spelling]) || isset($vowel_repairs[$spelling])) {
                $repaired_targets[$word_id] = $target;
                $repair_reasons[$word_id] = $repairs[$spelling] ?? 'vowel_variant_match';
            }
            if (isset($neighbors[$spelling])) {
                $close_targets[$word_id] = $target;
            }
            $close_form = $allow_close ? ($this->profile['closeForms'][$word_id] ?? null) : null;
            if ($close_form !== null && isset($forms[$close_form['target']]) && in_array($normalized, $close_form['heard'], true)) {
                $close_targets[$word_id] = $close_form['target'];
            }
        }

        // An exact inactive card or a tie at a higher tier blocks all lower tiers.
        [$tier, $targets] = match (true) {
            $phonemic_targets !== [] => ['phonemic_match', $phonemic_targets],
            $spelling_targets !== [] => ['orthographic_match', $spelling_targets],
            $repaired_targets !== [] => ['spelling_repair', $repaired_targets],
            default => ['close_match', $close_targets],
        };
        $matching_ids = array_keys($targets);
        $close = $tier === 'close_match';
        $matched = count($matching_ids) === 1;
        $reason = match (true) {
            $normalized === '' => 'invalid_transcript',
            $candidate_count === 0 => 'no_references',
            $matched => $tier === 'spelling_repair' ? $repair_reasons[$matching_ids[0]] : $tier,
            $matching_ids !== [] => 'ambiguous',
            default => 'no_match',
        };
        return [
            'matched' => $matched,
            'word_id' => $matched ? $matching_ids[0] : null,
            'bucket' => $matched ? ($close ? 'close' : 'right') : 'wrong',
            'score' => $matched ? ($close ? 85.0 : 100.0) : 0.0,
            'reason' => $reason,
            'normalized_transcript_text' => $normalized,
            'normalized_target_text' => $matched ? $targets[$matching_ids[0]] : '',
            'candidate_count' => $candidate_count,
            'matching_word_ids' => $matching_ids,
        ];
    }

    private function split_spaced_burst(string $normalized, array $forms): array {
        $words = explode(' ', $normalized);
        $phrases_by_first_word = [];
        foreach (array_keys($forms) as $form) {
            if (str_contains($form, ' ')) {
                $phrase = explode(' ', $form);
                $phrases_by_first_word[$phrase[0]][] = $phrase;
            }
        }

        $segments = [];
        for ($start = 0; $start < count($words); $start = $end) {
            $end = $start + 1;
            // Keep known phrases intact, including overlaps that cannot safely clear separate cards.
            for ($cursor = $start; $cursor < $end; ++$cursor) {
                foreach ($phrases_by_first_word[$words[$cursor]] ?? [] as $phrase) {
                    if (array_slice($words, $cursor, count($phrase)) === $phrase) {
                        $end = max($end, $cursor + count($phrase));
                    }
                }
            }
            $segments[] = implode(' ', array_slice($words, $start, $end - $start));
            if (count($segments) > self::MAX_BURST_SEGMENTS) {
                return [];
            }
        }
        return count($segments) >= 2 ? $segments : [];
    }

    private function index_ipa_forms(array $references): array {
        $forms = [];
        $owners = [];
        foreach ($references as $raw_id => $variants) {
            if (preg_match('/\A[1-9][0-9]*\z/D', (string) $raw_id) !== 1
                || (float) $raw_id > 9007199254740991 || !is_array($variants)) {
                continue;
            }
            $card_forms = [];
            foreach ($variants as $variant) {
                $form = is_string($variant) ? $this->normalize($variant) : '';
                if ($form !== '') {
                    $card_forms[$form] = true;
                }
            }
            foreach (array_keys($card_forms) as $form) {
                $forms[$form] = ($forms[$form] ?? 0) + 1;
                $owners[$form] = (int) $raw_id;
            }
        }
        return [$forms, $owners];
    }

    /** Split a failed whole-utterance match into bounded IPA, or return null if a joined split is ambiguous. */
    public function split_burst(string $transcript, array $references): ?array {
        $normalized = $this->normalize($transcript);
        if ($normalized === '') {
            return [];
        }
        [$forms] = $this->index_ipa_forms($references);
        if (str_contains($normalized, ' ')) {
            return $this->split_spaced_burst($normalized, $forms);
        }

        $segments = $this->split_joined_burst($normalized, $forms);
        if ($segments === null) {
            return null;
        }
        return is_array($segments) && count($segments) >= 2 && count($segments) <= self::MAX_BURST_SEGMENTS ? $segments : [];
    }

    /** Index exact partitions from an edge. A present null means ambiguous ownership or segmentation. */
    private function joined_partitions(string $normalized, array $forms, bool $leading): array {
        $length = strlen($normalized);
        $edge = $leading ? 0 : $length;
        $step = $leading ? 1 : -1;
        $partitions = [$edge => []];
        for ($offset = $edge + $step; $offset >= 0 && $offset <= $length; $offset += $step) {
            $found = false;
            foreach ($forms as $form => $owners) {
                $previous = $offset - $step * strlen($form);
                $start = $leading ? $previous : $offset;
                if (!array_key_exists($previous, $partitions) || substr_compare($normalized, $form, $start, strlen($form)) !== 0) {
                    continue;
                }
                $rest = $partitions[$previous];
                if ($found || $owners > 1 || $rest === null) {
                    $partitions[$offset] = null;
                    break;
                }
                $partitions[$offset] = $leading ? [...$rest, $form] : [$form, ...$rest];
                $found = true;
            }
        }
        return $partitions;
    }

    /** Find a complete partition. False means no coverage and null means an ambiguous partition. */
    private function split_joined_burst(string $normalized, array $forms): array|false|null {
        $partitions = $this->joined_partitions($normalized, $forms, false);
        return array_key_exists(0, $partitions) ? $partitions[0] : false;
    }

    private function splits_known_phrase(string $text, array $phrases, array $boundaries): bool {
        foreach ($phrases as $phrase) {
            for ($start = strpos($text, $phrase); $start !== false; $start = strpos($text, $phrase, $start + 1)) {
                foreach ($boundaries as $boundary) {
                    if ($start < $boundary && $boundary < $start + strlen($phrase)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** Recover exact runs anchored at opposite edges with a single, bounded middle. */
    private function split_around_unknown_middle(
        string $text, array $forms, array $owners, array $phrases, string $letter_pattern, Closure $valid_middle
    ): array|false|null {
        $prefixes = $this->joined_partitions($text, $forms, true);
        $suffixes = $this->joined_partitions($text, $forms, false);
        $left = array_key_last($prefixes);
        $right = array_key_last($suffixes);
        if ($left === 0 || $right === strlen($text) || $left >= $right) {
            return false;
        }
        $before = $prefixes[$left];
        $after = $suffixes[$right];
        if ($before === null || $after === null) {
            return null;
        }

        $middle = substr($text, $left, $right - $left);
        $longest = max(array_map(static fn(string $form): int => mb_strlen($form, 'UTF-8'), array_keys($forms)));
        $left_anchor = end($before);
        $right_anchor = $after[0];
        if (!$valid_middle($middle) || mb_strlen($middle, 'UTF-8') > $longest || count($before) + count($after) >= self::MAX_BURST_SEGMENTS
            || $owners[$left_anchor] === $owners[$right_anchor]
            || preg_match_all($letter_pattern, $left_anchor) < 2 || preg_match_all($letter_pattern, $right_anchor) < 2
            || preg_match_all($letter_pattern, $middle) < 2) {
            return false;
        }

        $boundaries = [$right];
        $offset = 0;
        foreach ($before as $form) {
            $boundaries[] = $offset += strlen($form);
        }
        $offset = $right;
        foreach ($after as $form) {
            $boundaries[] = $offset += strlen($form);
        }
        if ($this->splits_known_phrase($text, $phrases, $boundaries)) {
            return null;
        }
        return [$before, $after, $middle, $left, $right];
    }

    private function split_ipa_around_unknown_middle(string $transcript, array $references): ?array {
        $normalized = $this->normalize($transcript);
        if ($normalized === '' || str_contains($normalized, ' ')) {
            return [];
        }
        [$forms, $owners] = $this->index_ipa_forms($references);
        $phrases = [];
        foreach (array_keys($forms) as $form) {
            if (str_contains($form, ' ')) {
                $phrases[] = str_replace(' ', '', $form);
            }
        }
        $spans = $this->split_around_unknown_middle($normalized, $forms, $owners, $phrases, '/[\p{Ll}ʕ]/u',
            static fn(string $middle): bool => preg_match('/\A[\p{Ll}ʕ]/u', $middle) === 1 && preg_match('/[͜͡]\z/u', $middle) !== 1
        );
        if ($spans === false) {
            return [];
        }
        if ($spans === null) {
            return null;
        }
        [$before, $after, $middle] = $spans;
        $scored = static fn(string $ipa): array => ['word_id' => $owners[$ipa], 'repaired' => false, 'ipa' => $ipa];
        return [...array_map($scored, $before), ['word_id' => null, 'ipa' => $middle], ...array_map($scored, $after)];
    }

    /** Recover a joined answer from spelling or from two exact anchors around one unknown middle. */
    public function split_spelling_burst(string $heard, array $spellings, string $transcript = '', array $references = []): array {
        if (strlen($heard) > 256 || preg_match('//u', $heard) !== 1) {
            return [];
        }
        $normalized = $this->normalize_spelling($heard);
        if ($normalized === '') {
            return $this->split_ipa_around_unknown_middle($transcript, $references) ?? [];
        }

        $counts = [];
        $owners = [];
        $phrases = [];
        foreach ($spellings as $raw_id => $title) {
            if (preg_match('/\A[1-9][0-9]*\z/D', (string) $raw_id) !== 1
                || (float) $raw_id > 9007199254740991 || !is_string($title) || strlen($title) > 256) {
                continue;
            }
            $form = $this->normalize_spelling($title);
            if ($form !== '') {
                $counts[$form] = ($counts[$form] ?? 0) + 1;
                $owners[$form] = (int) $raw_id;
            } else {
                $words = array_map([$this, 'normalize_spelling'], preg_split('/\s+/u', trim($title)) ?: []);
                if (count($words) > 1 && !in_array('', $words, true)) {
                    $phrases[] = implode('', $words);
                }
            }
        }
        $exact = $this->split_joined_burst($normalized, $counts);
        if ($exact !== false) {
            // Exact spelling can preserve the intended words even when the phones differ from their references.
            if ($exact === null || count($exact) < 2 || count($exact) > self::MAX_BURST_SEGMENTS) {
                return [];
            }
            return array_map(static fn(string $title): array => [
                'word_id' => $owners[$title],
                'repaired' => false,
            ], $exact);
        }

        $corrected = $this->split_corrected_spelling($normalized, $counts, $owners);
        if ($corrected !== []) {
            return $corrected ?? [];
        }
        $display = trim($heard);
        $partial = $this->split_spelling_with_vowel_miss($normalized, $display, $counts, $owners);
        if ($partial !== []) {
            return $partial ?? [];
        }
        $ipa_middle = $this->split_ipa_around_unknown_middle($transcript, $references);
        if ($ipa_middle !== []) {
            return $ipa_middle ?? [];
        }
        $vowels = $this->profile['repairableVowels'];
        $spans = $this->split_around_unknown_middle($normalized, $counts, $owners, $phrases, '/\p{L}/u',
            static fn(string $middle): bool => preg_match("/\A'?\p{L}(?:[\p{L}\p{M}'-]*[\p{L}\p{M}])?\z/u", $middle) === 1
                && ($vowels === '' || preg_match('/[' . preg_quote($vowels, '/') . ']/u', $middle) === 1)
        );
        if ($spans === null) {
            return [];
        }
        if ($spans !== false) {
            [$before, $after, $middle, $left] = $spans;
            if (mb_strlen($display, 'UTF-8') === mb_strlen($normalized, 'UTF-8')) {
                $offset = mb_strlen(substr($normalized, 0, $left), 'UTF-8');
                $middle = mb_substr($display, $offset, mb_strlen($middle, 'UTF-8'), 'UTF-8');
            }
            $scored = static fn(string $form): array => ['word_id' => $owners[$form], 'repaired' => false];
            return [...array_map($scored, $before), ['word_id' => null, 'display' => $middle], ...array_map($scored, $after)];
        }
        return $this->split_spelling_with_unmatched_edge($normalized, $display, $counts, $owners);
    }

    private function joined_spelling_edits(string $heard): Generator {
        if ($this->rounded_vowels_pattern !== '' && $this->profile['roundedVowelOnsets'] !== '') {
            preg_match_all($this->rounded_vowels_pattern, $heard, $vowels, PREG_OFFSET_CAPTURE);
            $onset_pattern = '/[' . preg_quote($this->profile['roundedVowelOnsets'], '/') . ']\z/u';
            foreach ($vowels[0] as [$unit, $offset]) {
                if (preg_match($onset_pattern, substr($heard, 0, $offset), $onset) !== 1) {
                    continue;
                }
                foreach ($this->profile['roundedVowels'] as $replacement) {
                    if ($replacement !== $unit) {
                        yield [$unit, $offset, $replacement, $offset - strlen($onset[0]), false];
                    }
                }
            }
        }

        if ($this->joined_confusables_pattern === '') {
            return;
        }
        preg_match_all($this->joined_confusables_pattern, $heard, $confusions, PREG_OFFSET_CAPTURE);
        foreach ($confusions[0] as [$unit, $offset]) {
            $replacement = $this->profile['confusableGraphemes'][$unit] ?? null;
            if ($replacement !== null && $replacement !== $unit) {
                yield [$unit, $offset, $replacement, $offset, true];
            }
        }
    }

    /** Null marks an ambiguous full correction, which also blocks partial recovery. */
    private function split_corrected_spelling(string $heard, array $counts, array $owners): ?array {
        $match = [];
        foreach ($this->joined_spelling_edits($heard) as [$unit, $offset, $replacement, $edit_start, $close]) {
            // A complete full-credit vowel repair takes precedence over a Close result.
            if ($close && $match !== [] && !in_array(true, array_column($match, 'repaired'), true)) {
                return $match;
            }

            $edited = substr_replace($heard, $replacement, $offset, strlen($unit));
            $forms = $this->split_joined_burst($edited, $counts);
            if ($forms === null) {
                return null;
            }
            if ($forms === false || count($forms) < 2 || count($forms) > self::MAX_BURST_SEGMENTS) {
                continue;
            }

            $segments = [];
            $found_edit = false;
            $start = 0;
            $edit_end = $offset + strlen($replacement);
            foreach ($forms as $form) {
                $end = $start + strlen($form);
                // Keep the vowel's onset in the same word, and keep configured internal edits away from word boundaries.
                $edited_segment = $edit_start >= $start && $edit_end <= $end
                    && (!in_array($unit, $this->profile['internalJoinedConfusables'], true)
                        || ($offset > $start && $edit_end < $end));
                $segments[] = ['word_id' => $owners[$form], 'repaired' => $edited_segment && $close];
                $found_edit = $found_edit || $edited_segment;
                $start = $end;
            }
            if ($found_edit) {
                if ($match !== []) {
                    return null;
                }
                $match = $segments;
            }
        }
        return $match;
    }

    private function differs_by_one_vowel(string $heard, string $title): bool {
        $letters = preg_split('//u', $heard, -1, PREG_SPLIT_NO_EMPTY);
        $title_letters = preg_split('//u', $title, -1, PREG_SPLIT_NO_EMPTY);
        if (count($letters) !== count($title_letters)) {
            return false;
        }
        $different = false;
        foreach ($title_letters as $index => $letter) {
            if ($letter === $letters[$index]) {
                continue;
            }
            if ($different || !str_contains($this->profile['repairableVowels'], $letter)
                || !str_contains($this->profile['repairableVowels'], $letters[$index])) {
                return false;
            }
            $different = true;
        }
        return $different;
    }

    /** A known title with a vowel miss can anchor a boundary but never clears a box. */
    private function split_spelling_with_vowel_miss(string $heard, string $display, array $counts, array $owners): ?array {
        $match = [];
        $heard_length = mb_strlen($heard, 'UTF-8');
        foreach ($counts as $title => $owner_count) {
            $length = mb_strlen($title, 'UTF-8');
            if ($length + 2 > $heard_length || preg_match_all('/\p{L}/u', $title) < 4) {
                continue;
            }
            foreach ([true, false] as $at_start) {
                $offset = $at_start ? 0 : $heard_length - $length;
                $edge = mb_substr($heard, $offset, $length, 'UTF-8');
                if (isset($counts[$edge]) || !$this->differs_by_one_vowel($edge, $title)) {
                    continue;
                }
                $rest = $at_start
                    ? mb_substr($heard, $length, null, 'UTF-8')
                    : mb_substr($heard, 0, $offset, 'UTF-8');
                $forms = $this->split_joined_burst($rest, $counts);
                $parts = is_array($forms)
                    ? array_map(static fn(string $form): array => ['word_id' => $owners[$form], 'repaired' => false], $forms)
                    : ($forms === false ? $this->split_corrected_spelling($rest, $counts, $owners) : null);
                if ($parts === null) {
                    return null;
                }
                if (count($parts) >= self::MAX_BURST_SEGMENTS || count(array_unique(array_column($parts, 'word_id'))) < 2) {
                    continue;
                }
                if ($owner_count > 1 || $match !== []) {
                    return null;
                }
                $unscored = ['word_id' => null, 'repaired' => false, 'display' => mb_substr($display, $offset, $length, 'UTF-8')];
                $match = $at_start ? [$unscored, ...$parts] : [...$parts, $unscored];
            }
        }
        return $match;
    }

    /** An unknown edge needs two distinct exact words and one word-sized remainder. */
    private function split_spelling_with_unmatched_edge(string $heard, string $display, array $counts, array $owners): array {
        $length = mb_strlen($heard, 'UTF-8');
        $max_word_length = 0;
        foreach (array_keys($counts) as $title) {
            $max_word_length = max($max_word_length, mb_strlen($title, 'UTF-8'));
        }
        $max_edge_length = min($length - 2, $max_word_length);
        if ($this->profile['repairableVowels'] === '') {
            return [];
        }
        $vowels = '[' . preg_quote($this->profile['repairableVowels'], '/') . ']';
        $match = [];
        foreach ([true, false] as $at_start) {
            for ($edge_length = 1; $edge_length <= $max_edge_length; ++$edge_length) {
                $offset = $at_start ? 0 : $length - $edge_length;
                $rest = $at_start
                    ? mb_substr($heard, $edge_length, null, 'UTF-8')
                    : mb_substr($heard, 0, $offset, 'UTF-8');
                $forms = $this->split_joined_burst($rest, $counts);
                if ($forms === null) {
                    return [];
                }
                if ($forms === false || count(array_unique($forms)) < 2) {
                    continue;
                }

                // Keep the longest exact run even when its leftover is too small to be a word.
                $edge = mb_substr($heard, $offset, $edge_length, 'UTF-8');
                if (count($forms) >= self::MAX_BURST_SEGMENTS
                    || preg_match("/\\A'?\\p{L}{3,}\\z/u", $edge) !== 1
                    || preg_match("/$vowels/u", $edge) !== 1
                    || preg_match("/(?!$vowels)\\p{L}/u", $edge) !== 1) {
                    break;
                }
                if ($match !== []) {
                    return [];
                }
                $parts = array_map(static fn(string $form): array => ['word_id' => $owners[$form], 'repaired' => false], $forms);
                $unscored = ['word_id' => null, 'repaired' => false, 'display' => mb_substr($display, $offset, $edge_length, 'UTF-8')];
                $match = $at_start ? [$unscored, ...$parts] : [...$parts, $unscored];
                break;
            }
        }
        return $match;
    }
}
