# ZIPA speaking games

Open **Tools → ZIPA Speaking Games** in WordPress and choose a wordset. The
Wordsets edit screen also links to this page. Enable ZIPA for the wordset and
choose its audience. A wordset with no saved ZIPA settings has ZIPA disabled.
When enabled, ZIPA supplies the speaking cards on the Games page. The existing
settings for the other speaking providers still apply when ZIPA is disabled.

With the manifest field empty, the browser uses the pinned public ZIPA-CR Small
checkpoint with its full phoneme vocabulary. A learner chooses when to download
the model. The browser checks file hashes and reuses the verified copies after
refresh or account changes in the same browser profile. Microphone audio stays
on that device in browser mode.

A custom checkpoint uses a same-origin HTTPS manifest. Supply `model` and
`tokens` entries with HTTPS `url`, byte `size`, and lowercase `sha256`.
You can include `downloadBytes` for a known compressed transfer estimate.
The custom model uses the same CTC ONNX inputs and outputs as the stock browser
model. The runtime controls the WASM version. See the
[runtime notices](../js/zipa/THIRD_PARTY_NOTICES.md) for the upstream model
and browser engine.

The hosted preview uses the wordset's saved transcription endpoint and token.
Enable the hosted preview and follow its link from the ZIPA settings page while
signed in as a wordset manager. The service must accept an audio upload and
return IPA. Endpoint credentials stay on the WordPress server.

## Configure scoring

- **Wordset rules** apply the same way in both runtimes. They include IPA
  normalization, spelling conversion, symbol confusions, duplicated sounds
  and contextual alternatives. Use them for patterns that apply across
  vocabulary. Empty rules use the stock phoneme comparison.
- **Accepted pronunciations** map each word post ID to an array of IPA strings.
  An accepted variant earns the same result as the reference.
- **Close-only pronunciations** map each word post ID to an object with
  `target` IPA and a `heard` array. A Close-only form applies when its saved
  target is one of that word’s references. It is available in Word Stack.
- **Curated cards** optionally list up to 60 word post IDs. Word overrides can
  select the isolation recording, a reviewed reference IPA, the displayed
  title and picture. The catalog checks publication, wordset membership and
  category visibility. With an empty list, the game selects a bounded pool
  from enabled categories and words with isolation IPA. Word Stack requires
  a supported picture.

Saved settings apply on the next visit to the Games page. Configure aliases
only for the words that need them. Ambiguous matches remain uncredited. A
recognized word clears a card only if that card is active. For speech joined
without spaces, Word Stack can skip one bounded unrecognized middle segment
when distinct, unambiguous words match on both sides. A known multiword card
stays intact.

### Microphone capture

Set `"closeMic": true` in **Word Stack microphone tuning** to try the close
microphone profile. Both browser and hosted Word Stack request noise
suppression, echo cancellation, and automatic gain off. A browser that
advertises voice isolation is also asked to use it. All are preferences
because devices vary in which settings they can apply. Remove the field or
set it to false to return to the device's normal speech settings.

Use a microphone close to the speaker for this profile. Automatic gain
can make distant talk louder during pauses. The profile leaves the existing
start and silence thresholds in place.

### Pronunciation rules

Paste a JSON object in **Advanced ZIPA settings → Pronunciation rules**. This
example accepts one doubled written vowel, treats one `t`/`d` substitution
as Close in Word Stack, and normalizes IPA `ŋ` to `n` only before `ɡ`.

```json
{
  "ipaReplacements": [
    {"from": "ŋ", "to": "n", "followedBy": ["ɡ"]}
  ],
  "repairableVowels": "aeiou",
  "confusableGraphemes": {"t": "d", "d": "t"},
  "joinedConfusables": ["t", "d"]
}
```

| Field | What it controls |
| --- | --- |
| `inputSymbols`, `ignorableIpaMarks`, `tiedPairs` | Initial symbol substitutions, marks to remove, and allowed tie-bar pairs such as `"tʃ"`. Leave out `tiedPairs` to preserve the ties. |
| `ipaReplacements` | Literal rules applied in order. Each rule takes `from` and `to`, with optional `precededBy` and `followedBy` lists. An empty string in either list matches the start or end of the transcript on that side. `ignorePreceding` lists intervening symbols to skip when checking the left context. |
| `confusableGraphemes`, `joinedConfusables`, `internalJoinedConfusables` | Single spelling substitutions eligible for Close, the subset allowed in joined speech, and the subset that must stay away from word boundaries. |
| `repairableVowels`, `repairableConsonants` | Strings listing letters whose accidental doubling can be corrected for full credit. In joined speech, one such edit can recover an otherwise exact, unambiguous answer. |
| `openVowelAlternatives`, `roundedVowels`, `roundedVowelOnsets` | One written vowel substitution for full credit when it produces a unique match. `openVowelAlternatives` maps individual letters to allowed alternatives. `roundedVowels` lists interchangeable written units after a consonant from `roundedVowelOnsets`. |
| `displayRules`, `lowercaseMap`, `uppercaseMap` | The fallback spelling map and optional case overrides. Use ordered `clusters` pairs, a `phones` map, a `finalPhones` map, and an `ignoredMarks` list inside `displayRules`. An exact reviewed IPA match displays the saved card title. |

For a single word, put the word post ID and reviewed forms in **Accepted
pronunciations**, for example `{"123":["tala","tɑla"]}`. For a recognition
error that should only earn Close, use **Close matches**, for example
`{"123":{"target":"tala","heard":["dala"]}}`. Replace `123` with the ID from
the word’s edit page. Both examples use IPA.
