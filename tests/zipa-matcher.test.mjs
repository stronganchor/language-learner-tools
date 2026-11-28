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
