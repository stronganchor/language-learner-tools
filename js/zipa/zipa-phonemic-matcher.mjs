const encoder = new TextEncoder();
const maxBurstSegments = 8;

function trimPhpWhitespace(text) {
    return text.replace(/^[ \t\r\n\v\0]+|[ \t\r\n\v\0]+$/g, '');
}

function longestFirst(units) {
    return [...units].sort((left, right) => right.length - left.length);
}

function escapeRegex(text) {
    return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function alternationPattern(units, suffix = '') {
    if (units.length === 0) return suffix ? /./gu : null;
    return new RegExp(longestFirst(units).map(escapeRegex).join('|') + suffix, 'gu');
}

function matchesContext(text, contexts, ends) {
    return !contexts.length || contexts.some(context => (
        context === '' ? text === '' : (ends ? text.endsWith(context) : text.startsWith(context))
    ));
}

function applyIpaReplacement(text, {from, to, precededBy = [], followedBy = [], ignorePreceding = ''}) {
    if (!from) return text;
    if (!precededBy.length && !followedBy.length) return text.replaceAll(from, () => to);

    let result = '';
    let offset = 0;
    for (let start = text.indexOf(from, offset); start !== -1; start = text.indexOf(from, offset)) {
        const end = start + from.length;
        result += text.slice(offset, start);
        let prefix = text.slice(0, start);
        if (ignorePreceding && precededBy.length) {
            const letters = Array.from(prefix);
            while (letters.length && ignorePreceding.includes(letters.at(-1))) letters.pop();
            prefix = letters.join('');
        }
        const eligible = matchesContext(prefix, precededBy, true) && matchesContext(text.slice(end), followedBy, false);
        result += eligible ? to : from;
        offset = end;
    }
    return result + text.slice(offset);
}

/** Create an IPA matcher for one wordset's scoring profile. */
export function createZipaMatcher({
    inputSymbols = {},
    tiedPairs,
    ipaReplacements = [],
    ignorableIpaMarks = [],
    lowercaseMap = {},
    closeForms = {},
    confusableGraphemes = {},
    joinedConfusables = [],
    internalJoinedConfusables = [],
    repairableConsonants = '',
    repairableVowels = '',
    openVowelAlternatives = {},
    roundedVowels = [],
    roundedVowelOnsets = '',
} = {}) {
    const validTiedPairs = tiedPairs === undefined ? null : new Set(tiedPairs);
    const confusablesPattern = alternationPattern(Object.keys(confusableGraphemes), '|.');
    const joinedConfusablesPattern = alternationPattern(joinedConfusables);
    const roundedVowelsPattern = alternationPattern(roundedVowels);
    const roundedVowelsLongestFirst = longestFirst(roundedVowels);

    function normalizeIpa(rawIpa) {
        if (typeof rawIpa !== 'string' || rawIpa.length > 256 || /[\uD800-\uDFFF]/u.test(rawIpa)
            || encoder.encode(rawIpa).length > 256) {
            return '';
        }

        let ipa = trimPhpWhitespace(rawIpa);
        if ((ipa.startsWith('[') && ipa.endsWith(']')) || (ipa.startsWith('/') && ipa.endsWith('/'))) {
            ipa = trimPhpWhitespace(ipa.slice(1, -1));
        }

        ipa = Array.from(ipa, symbol => inputSymbols[symbol] ?? symbol).join('');
        ipa = trimPhpWhitespace(ipa.replace(/[\u0009-\u000d\u0085\p{Z}]+/gu, ' '));
        // U+0295 is an IPA letter classified as uncased by newer Unicode versions.
        if (!/^[\p{Ll}\p{Lm}\p{M}ʕːˑ. ]+$/u.test(ipa) || !/[\p{Ll}ʕ]/u.test(ipa)) {
            return '';
        }

        for (const mark of ignorableIpaMarks) ipa = ipa.replaceAll(mark, '');
        if (validTiedPairs) {
            ipa = ipa.replace(/([\p{Ll}\p{Lm}ʕ])((?:(?!͡)\p{M})*)͡+(?=([\p{Ll}\p{Lm}ʕ]))/gu,
                (_tied, left, marks, right) => left + marks + (validTiedPairs.has(left + right) ? '͡' : ''));
        }
        for (const rule of ipaReplacements) {
            ipa = applyIpaReplacement(ipa, rule);
            if (encoder.encode(ipa).length > 256) return '';
        }
        return encoder.encode(ipa).length <= 256 ? ipa : '';
    }

    function normalizeSpelling(spelling) {
        const lowered = Array.from(trimPhpWhitespace(spelling).replace(/[‘’ʼ]/gu, "'"), symbol => lowercaseMap[symbol] ?? symbol).join('').toLowerCase();
        return /^[\p{L}\p{M}'-]+$/u.test(lowered) ? lowered : '';
    }

    function spellingNeighbors(spelling) {
        const graphemes = spelling.match(confusablesPattern) || [];
        const neighbors = new Set();
        graphemes.forEach((grapheme, index) => {
            const replacement = confusableGraphemes[grapheme];
            if (replacement) {
                const variant = graphemes.slice();
                variant[index] = replacement;
                neighbors.add(variant.join(''));
            }
        });
        return neighbors;
    }

    function* duplicateSpellingEdits(spelling) {
        for (const {1: letter, index} of spelling.matchAll(/(.)\1+/gu)) {
            const reason = repairableVowels.includes(letter) ? 'duplicate_vowel_match'
                : repairableConsonants.includes(letter) ? 'duplicate_consonant_match' : '';
            if (reason) yield {unit: letter + letter, index, replacement: letter, start: index, close: false, reason};
        }
    }

    function duplicateSpellingRepairs(spelling) {
        const repairs = new Map();
        for (const {unit, index, replacement, reason} of duplicateSpellingEdits(spelling)) {
            repairs.set(spelling.slice(0, index) + replacement + spelling.slice(index + unit.length), reason);
        }
        return repairs;
    }

    function vowelSpellingRepairs(spelling) {
        const letters = Array.from(spelling);
        const repairs = new Set();
        letters.forEach((letter, index) => {
            const remaining = letters.slice(index).join('');
            const unit = roundedVowelsLongestFirst.find(vowel => remaining.startsWith(vowel)) ?? letter;
            const roundsAfterConsonant = index > 0 && roundedVowelOnsets.includes(letters[index - 1])
                && roundedVowels.includes(unit);
            const alternatives = roundsAfterConsonant ? roundedVowels : (openVowelAlternatives[letter] || []);
            for (const replacement of alternatives) {
                if (replacement === unit) continue;
                const variant = letters.slice();
                variant.splice(index, Array.from(unit).length, replacement);
                repairs.add(variant.join(''));
            }
        });
        return repairs;
    }

    /** Score against a complete lesson's reviewed IPA and saved spellings. */
    function scoreIpa(transcript, references, allowClose = false, heardSpelling = '', spellings = {}) {
        const normalized = normalizeIpa(transcript);
        const heard = normalized ? normalizeSpelling(heardSpelling) : '';
        const neighbors = allowClose ? spellingNeighbors(heard) : new Set();
        const repairs = duplicateSpellingRepairs(heard);
        const vowelRepairs = vowelSpellingRepairs(heard);
        const phonemicTargets = new Map();
        const spellingTargets = new Map();
        const repairedTargets = new Map();
        const repairReasons = new Map();
        const closeTargets = new Map();
        let candidateCount = 0;

        for (const [id, variants] of Object.entries(references ?? {})) {
            const wordId = Number(id);
            if (!/^[1-9][0-9]*$/.test(id) || !Number.isSafeInteger(wordId) || !Array.isArray(variants)) {
                continue;
            }
            const forms = new Set(variants.map(normalizeIpa).filter(Boolean));
            if (forms.size === 0) {
                continue;
            }
            candidateCount += 1;
            if (forms.has(normalized)) {
                phonemicTargets.set(wordId, normalized);
            }
            const target = forms.values().next().value;
            const spelling = heard ? normalizeSpelling(spellings[wordId] || '') : '';
            if (spelling && spelling === heard) {
                spellingTargets.set(wordId, target);
            }
            if (repairs.has(spelling) || vowelRepairs.has(spelling)) {
                repairedTargets.set(wordId, target);
                repairReasons.set(wordId, repairs.get(spelling) || 'vowel_variant_match');
            }
            if (neighbors.has(spelling)) {
                closeTargets.set(wordId, target);
            }
            const closeForm = closeForms[wordId];
            if (allowClose && closeForm?.heard.includes(normalized) && forms.has(closeForm.target)) {
                closeTargets.set(wordId, closeForm.target);
            }
        }

        // An exact inactive card or a tie at a higher tier blocks all lower tiers.
        const [tier, targets] = [
            ['phonemic_match', phonemicTargets],
            ['orthographic_match', spellingTargets],
            ['spelling_repair', repairedTargets],
        ].find(([, matches]) => matches.size) || ['close_match', closeTargets];
        const matchingIds = [...targets.keys()];
        const matched = matchingIds.length === 1;
        const close = tier === 'close_match';
        let reason = 'no_match';
        if (normalized === '') {
            reason = 'invalid_transcript';
        } else if (candidateCount === 0) {
            reason = 'no_references';
        } else if (matched) {
            reason = tier === 'spelling_repair' ? repairReasons.get(matchingIds[0]) : tier;
        } else if (matchingIds.length > 1) {
            reason = 'ambiguous';
        }

        return {
            matched,
            word_id: matched ? matchingIds[0] : null,
            bucket: matched ? (close ? 'close' : 'right') : 'wrong',
            score: matched ? (close ? 85 : 100) : 0,
            reason,
            normalized_transcript_text: normalized,
            normalized_target_text: matched ? targets.get(matchingIds[0]) : '',
            candidate_count: candidateCount,
            matching_word_ids: matchingIds,
        };
    }

    function splitSpacedBurst(normalized, forms) {
        const words = normalized.split(' ');
        const phrasesByFirstWord = new Map();
        for (const form of forms.keys()) {
            if (!form.includes(' ')) continue;
            const phrase = form.split(' ');
            if (!phrasesByFirstWord.has(phrase[0])) phrasesByFirstWord.set(phrase[0], []);
            phrasesByFirstWord.get(phrase[0]).push(phrase);
        }

        const segments = [];
        for (let start = 0; start < words.length;) {
            let end = start + 1;
            // Keep known phrases intact. Group overlapping phrases so their shared words cannot clear separate cards.
            for (let cursor = start; cursor < end; cursor++) {
                for (const phrase of phrasesByFirstWord.get(words[cursor]) || []) {
                    if (phrase.every((word, index) => words[cursor + index] === word)) {
                        end = Math.max(end, cursor + phrase.length);
                    }
                }
            }
            segments.push(words.slice(start, end).join(' '));
            if (segments.length > maxBurstSegments) return [];
            start = end;
        }
        return segments.length >= 2 ? segments : [];
    }

    function joinedPartitions(text, forms, leading) {
        // An absent boundary has no exact partition. Null means multiple partitions or owners.
        const edge = leading ? 0 : text.length;
        const step = leading ? 1 : -1;
        const partitions = new Map([[edge, []]]);
        for (let offset = edge + step; offset >= 0 && offset <= text.length; offset += step) {
            let partition;
            for (const [form, owners] of forms) {
                const previous = offset - step * form.length;
                const rest = partitions.get(previous);
                if (rest === undefined || !text.startsWith(form, leading ? previous : offset)) continue;
                if (partition !== undefined || owners > 1 || rest === null) {
                    partition = null;
                    break;
                }
                partition = leading ? [...rest, form] : [form, ...rest];
            }
            if (partition !== undefined) partitions.set(offset, partition);
        }
        return partitions;
    }

    function splitJoinedBurst(text, forms) {
        return joinedPartitions(text, forms, false).get(0);
    }

    function indexIpaForms(references) {
        const forms = new Map();
        const ids = new Map();
        for (const [id, variants] of Object.entries(references ?? {})) {
            if (!/^[1-9][0-9]*$/.test(id) || !Number.isSafeInteger(Number(id)) || !Array.isArray(variants)) continue;
            for (const form of new Set(variants.map(normalizeIpa).filter(Boolean))) {
                forms.set(form, (forms.get(form) || 0) + 1);
                ids.set(form, Number(id));
            }
        }
        return {forms, ids};
    }

    /** Split into bounded IPA, or return null if a joined split is ambiguous. */
    function splitIpaBurst(transcript, references) {
        const normalized = normalizeIpa(transcript);
        if (!normalized) return [];
        const {forms} = indexIpaForms(references);
        if (normalized.includes(' ')) return splitSpacedBurst(normalized, forms);

        // ZIPA usually omits word boundaries. Accept a joined run only when known forms account for every sound.
        const segments = splitJoinedBurst(normalized, forms);
        if (segments === null) return null;
        return segments && segments.length >= 2 && segments.length <= maxBurstSegments ? segments : [];
    }

    function splitsKnownPhrase(text, phrases, boundaries) {
        for (const phrase of phrases) {
            for (let start = text.indexOf(phrase); start !== -1; start = text.indexOf(phrase, start + 1)) {
                if (boundaries.some(boundary => start < boundary && boundary < start + phrase.length)) return true;
            }
        }
        return false;
    }

    function splitAroundUnknownMiddle(text, forms, ids, phrases, letterPattern, validMiddle) {
        const [left, before] = Array.from(joinedPartitions(text, forms, true)).at(-1);
        const [right, after] = Array.from(joinedPartitions(text, forms, false)).at(-1);
        if (left === 0 || right === text.length || left >= right) return false;
        if (!before || !after) return null;

        const middle = text.slice(left, right);
        const longest = Math.max(...Array.from(forms.keys(), form => Array.from(form).length));
        if (!validMiddle(middle) || Array.from(middle).length > longest || before.length + after.length >= maxBurstSegments
            || ids.get(before.at(-1)) === ids.get(after[0])
            || [middle, before.at(-1), after[0]].some(form => (form.match(letterPattern) || []).length < 2)) return false;

        const boundaries = [right];
        let offset = 0;
        for (const form of before) boundaries.push(offset += form.length);
        offset = right;
        for (const form of after) boundaries.push(offset += form.length);
        if (splitsKnownPhrase(text, phrases, boundaries)) return null;
        return {before, after, middle, left, right};
    }

    function splitIpaAroundUnknownMiddle(transcript, references) {
        const normalized = normalizeIpa(transcript);
        if (!normalized || normalized.includes(' ')) return [];
        const {forms, ids} = indexIpaForms(references);
        const phrases = Array.from(forms.keys()).filter(form => form.includes(' ')).map(form => form.replaceAll(' ', ''));
        const spans = splitAroundUnknownMiddle(normalized, forms, ids, phrases, /[\p{Ll}ʕ]/gu,
            middle => /^[\p{Ll}ʕ]/u.test(middle) && !/[͜͡]$/u.test(middle));
        if (!spans) return spans === null ? null : [];
        const scored = ipa => ({word_id: ids.get(ipa), repaired: false, ipa});
        return [...spans.before.map(scored), {word_id: null, ipa: spans.middle}, ...spans.after.map(scored)];
    }

    function* joinedSpellingEdits(heard) {
        yield* duplicateSpellingEdits(heard);
        for (const {0: unit, index} of roundedVowelsPattern ? heard.matchAll(roundedVowelsPattern) : []) {
            const onset = Array.from(heard.slice(0, index)).at(-1);
            if (!onset || !roundedVowelOnsets.includes(onset)) continue;
            for (const replacement of roundedVowels) {
                if (replacement !== unit) yield {unit, index, replacement, start: index - onset.length, close: false};
            }
        }
        for (const {0: unit, index} of joinedConfusablesPattern ? heard.matchAll(joinedConfusablesPattern) : []) {
            const replacement = confusableGraphemes[unit];
            if (replacement !== undefined && replacement !== unit) yield {unit, index, replacement, start: index, close: true};
        }
    }

    function splitWithSpellingRepair(heard, forms, ids) {
        let recovered = [];
        let recoveredSpelling;
        for (const edit of joinedSpellingEdits(heard)) {
            // A complete full-credit repair takes precedence over a Close result.
            if (edit.close && recovered.length && !recovered.some(part => part.repaired)) return recovered;

            const {unit, index, replacement} = edit;
            const corrected = heard.slice(0, index) + replacement + heard.slice(index + unit.length);
            const segments = splitJoinedBurst(corrected, forms);
            if (segments === null) return null;
            if (!segments || segments.length < 2 || segments.length > maxBurstSegments) continue;

            const replacementEnd = index + replacement.length;
            let start = 0;
            let containsEdit = false;
            const parts = segments.map((form) => {
                const end = start + form.length;
                // Keep the vowel's onset in the same word, and keep configured internal edits away from word boundaries.
                const edited = start <= edit.start && replacementEnd <= end
                    && (!internalJoinedConfusables.includes(unit) || (start < index && replacementEnd < end));
                start = end;
                containsEdit ||= edited;
                return {word_id: ids.get(form), repaired: edited && edit.close};
            });
            if (!containsEdit) continue;
            if (recovered.length && (corrected !== recoveredSpelling
                || parts.some((part, offset) => part.repaired !== recovered[offset].repaired))) return null;
            recovered = parts;
            recoveredSpelling = corrected;
        }
        return recovered;
    }

    function differsByOneVowel(heard, expected) {
        let mismatch = false;
        for (let index = 0; index < expected.length; index++) {
            if (heard[index] === expected[index]) continue;
            if (mismatch || !repairableVowels.includes(heard[index]) || !repairableVowels.includes(expected[index])) {
                return false;
            }
            mismatch = true;
        }
        return mismatch;
    }

    function splitWithUnmatchedEdge(heard, original, forms, ids) {
        const letters = Array.from(heard);
        const rawLetters = Array.from(trimPhpWhitespace(original));
        let recovered = [];
        for (const [form, owners] of forms) {
            const expected = Array.from(form);
            const length = expected.length;
            if ((form.match(/\p{L}/gu) || []).length < 4 || length + 2 > letters.length) continue;

            for (const leading of [true, false]) {
                const start = leading ? 0 : letters.length - length;
                const edge = letters.slice(start, start + length);
                if (forms.has(edge.join('')) || !differsByOneVowel(edge, expected)) continue;

                const remainder = (leading ? letters.slice(length) : letters.slice(0, start)).join('');
                const exact = splitJoinedBurst(remainder, forms);
                const parts = exact === undefined
                    ? splitWithSpellingRepair(remainder, forms, ids)
                    : exact?.map(title => ({word_id: ids.get(title), repaired: false}));
                if (!parts) return null;
                if (parts.length >= maxBurstSegments || new Set(parts.map(part => part.word_id)).size < 2) continue;
                if (owners > 1 || recovered.length) return null;

                const unmatched = {
                    word_id: null,
                    repaired: false,
                    display: rawLetters.slice(start, start + length).join(''),
                };
                recovered = leading ? [unmatched, ...parts] : [...parts, unmatched];
            }
        }
        return recovered;
    }

    function splitWithUnknownEdge(heard, original, forms, ids) {
        const letters = Array.from(heard);
        const rawLetters = Array.from(trimPhpWhitespace(original));
        const longestTitle = Math.max(0, ...Array.from(forms.keys(), title => Array.from(title).length));
        const maxEdgeLength = Math.min(longestTitle, letters.length - 2);
        let recovered = [];

        for (const leading of [true, false]) {
            for (let length = 1; length <= maxEdgeLength; length++) {
                const boundary = leading ? length : letters.length - length;
                const known = (leading ? letters.slice(boundary) : letters.slice(0, boundary)).join('');
                const titles = splitJoinedBurst(known, forms);
                if (titles === null) return [];
                if (!titles || new Set(titles).size < 2) continue;

                // Check the longest recoverable run so extra noise cannot hide a known word.
                const edge = leading ? letters.slice(0, boundary) : letters.slice(boundary);
                if (titles.length >= maxBurstSegments || !/^'?[\p{L}]{3,}$/u.test(edge.join(''))
                    || !edge.some(symbol => repairableVowels.includes(symbol))
                    || !edge.some(symbol => symbol !== "'" && !repairableVowels.includes(symbol))) break;
                if (recovered.length) return [];

                const display = (leading ? rawLetters.slice(0, boundary) : rawLetters.slice(boundary)).join('');
                const unmatched = {word_id: null, repaired: false, display};
                const parts = titles.map(title => ({word_id: ids.get(title), repaired: false}));
                recovered = leading ? [unmatched, ...parts] : [...parts, unmatched];
                break;
            }
        }
        return recovered;
    }

    /** Recover a joined answer from spelling or from two exact anchors around one unknown middle. */
    function splitSpellingBurst(heardSpelling, spellings, transcript = '', references = {}) {
        if (typeof heardSpelling !== 'string' || encoder.encode(heardSpelling).length > 256) return [];
        const heard = normalizeSpelling(heardSpelling);
        if (!heard) return splitIpaAroundUnknownMiddle(transcript, references) ?? [];

        const forms = new Map();
        const ids = new Map();
        const phrases = [];
        for (const [id, title] of Object.entries(spellings ?? {})) {
            const wordId = Number(id);
            if (!/^[1-9][0-9]*$/.test(id) || !Number.isSafeInteger(wordId) || typeof title !== 'string'
                || encoder.encode(title).length > 256) continue;
            const form = normalizeSpelling(title);
            if (!form) {
                const words = trimPhpWhitespace(title).split(/\s+/u).map(normalizeSpelling);
                if (words.length > 1 && words.every(Boolean)) phrases.push(words.join(''));
                continue;
            }
            forms.set(form, (forms.get(form) || 0) + 1);
            ids.set(form, wordId);
        }
        const exact = splitJoinedBurst(heard, forms);
        if (exact !== undefined) {
            // Exact spelling can preserve the intended words even when the phones differ from their references.
            if (!exact || exact.length < 2 || exact.length > maxBurstSegments) return [];
            return exact.map(title => ({word_id: ids.get(title), repaired: false}));
        }
        const repaired = splitWithSpellingRepair(heard, forms, ids);
        if (repaired === null) return [];
        if (repaired.length) return repaired;
        const anchored = splitWithUnmatchedEdge(heard, heardSpelling, forms, ids);
        if (anchored === null) return [];
        if (anchored.length) return anchored;

        const ipaMiddle = splitIpaAroundUnknownMiddle(transcript, references);
        if (ipaMiddle === null) return [];
        if (ipaMiddle.length) return ipaMiddle;

        const spans = splitAroundUnknownMiddle(heard, forms, ids, phrases, /\p{L}/gu,
            middle => /^'?\p{L}(?:[\p{L}\p{M}'-]*[\p{L}\p{M}])?$/u.test(middle)
                && (!repairableVowels || Array.from(middle).some(letter => repairableVowels.includes(letter))));
        if (spans === null) return [];
        if (spans) {
            const raw = Array.from(trimPhpWhitespace(heardSpelling));
            const display = raw.length === Array.from(heard).length
                ? raw.slice(Array.from(heard.slice(0, spans.left)).length, Array.from(heard.slice(0, spans.right)).length).join('')
                : spans.middle;
            const scored = form => ({word_id: ids.get(form), repaired: false});
            return [...spans.before.map(scored), {word_id: null, display}, ...spans.after.map(scored)];
        }

        return splitWithUnknownEdge(heard, heardSpelling, forms, ids);
    }

    return {normalizeIpa, scoreIpa, splitIpaBurst, splitSpellingBurst};
}
