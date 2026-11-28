function spellWord(raw, {clusters = [], phones = {}, finalPhones = {}, ignoredMarks = []}) {
    let ipa = raw;
    for (const mark of ignoredMarks) ipa = ipa.replaceAll(mark, '');

    let spelling = '';
    for (let offset = 0; offset < ipa.length;) {
        const cluster = clusters.find(([unit]) => unit && ipa.startsWith(unit, offset));
        if (cluster) {
            spelling += cluster[1];
            offset += cluster[0].length;
            continue;
        }

        const phone = String.fromCodePoint(ipa.codePointAt(offset));
        offset += phone.length;
        const output = (offset === ipa.length ? finalPhones[phone] : undefined) ?? phones[phone];
        if (output === undefined) return '';
        spelling += output;
    }
    return spelling;
}

/** Render exact card names first and use the wordset's spelling map for other output. */
export function createOrthographicDisplay(cards, {normalizeIpa}, {displayRules = {}, uppercaseMap = {}} = {}) {
    const titlesByIpa = new Map();
    for (const card of cards) {
        for (const raw of [card.recording_ipa ?? card.ipa, ...(card.ipaVariants || [])]) {
            const key = normalizeIpa(raw);
            if (!key) continue;
            if (!titlesByIpa.has(key)) {
                titlesByIpa.set(key, card.title);
            } else if (titlesByIpa.get(key) !== card.title) {
                titlesByIpa.set(key, null);
            }
        }
    }

    return function displayTranscript(transcript) {
        const ipa = normalizeIpa(transcript);
        if (!ipa) return '';
        const title = titlesByIpa.get(ipa);
        if (title) return title;

        const words = ipa.split(' ').map(word => spellWord(word, displayRules));
        if (words.some(word => !word)) return '';
        const text = words.join(' ');
        return text.replace(/\p{L}/u, letter => uppercaseMap[letter] ?? letter.toUpperCase());
    };
}
