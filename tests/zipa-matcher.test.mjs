import assert from 'node:assert/strict';
import test from 'node:test';
import {createZipaMatcher} from '../js/zipa/zipa-phonemic-matcher.mjs';
import {createOrthographicDisplay} from '../js/zipa/zipa-orthographic-display.mjs';

const references = {31: ['mula'], 32: ['sina'], 33: ['tela']};
const spellings = {31: 'Mula', 32: 'Sina', 33: 'Tela'};
const cards = Object.keys(references).map(id => ({title: spellings[id], ipa: references[id][0]}));

test('exact IPA anchors survive an unrenderable middle or explicit word breaks', () => {
    const matcher = createZipaMatcher();
    const display = createOrthographicDisplay(cards, matcher);
    const joined = 'mulaɬɔŋtela';
    assert.equal(display(joined), '');
    assert.deepEqual(matcher.splitIpaBurst(joined, references), []);
    assert.deepEqual(matcher.splitSpellingBurst(display(joined), spellings, joined, references), [
        {word_id: 31, repaired: false, ipa: 'mula'},
        {word_id: null, ipa: 'ɬɔŋ'},
        {word_id: 33, repaired: false, ipa: 'tela'},
    ]);
    assert.deepEqual(matcher.splitIpaBurst('mula ɬɔŋ tela', references), ['mula', 'ɬɔŋ', 'tela']);
});

test('exact spelling anchors can bracket one misheard word, retaining complete spelling precedence', () => {
    const matcher = createZipaMatcher();
    assert.deepEqual(matcher.splitSpellingBurst('Mulanokatela', spellings, 'mʊlanokatɛla', references), [
        {word_id: 31, repaired: false},
        {word_id: null, display: 'noka'},
        {word_id: 33, repaired: false},
    ]);
    assert.deepEqual(matcher.splitSpellingBurst('Mulasinatela', spellings, 'mulasɪnatela', references), [
        {word_id: 31, repaired: false},
        {word_id: 32, repaired: false},
        {word_id: 33, repaired: false},
    ]);
});

test('unknown-middle fallback rejects tied, overlapping and same-owner anchors and keeps phrases together', () => {
    const matcher = createZipaMatcher();
    for (const [heard, deck] of [
        ['mulaɬɔŋtela', {...references, 34: ['mula']}],
        ['mulaɬɔŋmila', {...references, 31: ['mula', 'mila']}],
        ['mulastela', {31: ['mulas'], 33: ['astela']}],
        ['mulanoʊtela', {...references, 34: ['mula noʊ']}],
        ['mulaŋtela', references],
    ]) {
        assert.deepEqual(matcher.splitSpellingBurst('', {}, heard, deck), [], heard);
    }
    assert.deepEqual(matcher.splitSpellingBurst('Mulanokatela', {...spellings, 34: 'Mula Noka'}), []);
    assert.deepEqual(matcher.splitIpaBurst('mula noʊ tela', {...references, 34: ['mula noʊ']}), ['mula noʊ', 'tela']);
});

test('one configured doubled consonant or vowel can be corrected anywhere in joined speech', () => {
    const matcher = createZipaMatcher({repairableConsonants: 'w', repairableVowels: 'a'});
    const deck = {41: 'Dara', 42: 'Vila', 43: 'Cwel'};
    const matches = ids => ids.map(word_id => ({word_id, repaired: false}));
    assert.deepEqual(matcher.splitSpellingBurst('Daravilacwwel', deck), matches([41, 42, 43]));
    assert.deepEqual(matcher.splitSpellingBurst('Daracwwelvila', deck), matches([41, 43, 42]));
    assert.deepEqual(matcher.splitSpellingBurst('Cwweldaravila', deck), matches([43, 41, 42]));
    assert.deepEqual(matcher.splitSpellingBurst('Daaraavila', deck), []);
    assert.deepEqual(matcher.splitSpellingBurst('Daara', deck), []);
    assert.deepEqual(matcher.splitSpellingBurst('Daaravila', deck), matches([41, 42]));
    assert.deepEqual(createZipaMatcher().splitSpellingBurst('Daracwwel', deck), []);
    assert.equal(matcher.scoreIpa('cwwel', {43: ['cwel']}, false, 'Cwwel', deck).reason, 'duplicate_consonant_match');
});

test('exact spelling keeps priority and a repair needs a unique result', () => {
    const matcher = createZipaMatcher({
        repairableConsonants: 'w', repairableVowels: 'e', roundedVowels: ['wu', 'u'], roundedVowelOnsets: 'w',
    });
    const matches = ids => ids.map(word_id => ({word_id, repaired: false}));
    assert.deepEqual(matcher.splitSpellingBurst('Nawwel', {41: 'Naw', 42: 'Wel', 43: 'Na'}), matches([41, 42]));
    assert.deepEqual(matcher.splitSpellingBurst('Daracwwel', {41: 'Dara', 43: 'Cwel', 44: 'Cwel'}), []);
    assert.deepEqual(matcher.splitSpellingBurst('Daracwweel', {41: 'Dara', 43: 'Cweel', 44: 'Cwwel'}), []);
    assert.deepEqual(matcher.splitSpellingBurst('Daracwwu', {41: 'Dara', 43: 'Cwu'}), matches([41, 43]));
});
