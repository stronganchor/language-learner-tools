# AI Crawler Support

LL Tools exposes generated, read-only discovery files for AI agents and
crawlers:

- `/llms.txt`
- `/ll-tools/llms.txt`
- `/ll-tools/index.md`
- `/ll-tools/dictionary.md`
- `/ll-tools/wordsets.md`
- `/ll-tools/content-lessons.md`
- `/ll-tools/ai-crawler.md`

These files are served by `includes/lib/ai-crawler-support.php` during
`template_redirect`.

## Invariants

- Exports must only include anonymous public content.
- Use explicit anonymous visibility checks, not the current logged-in user, when
  deciding whether wordsets, categories, dictionary entries, vocab lessons, or
  content lessons appear.
- Keep exports bounded with filterable caps. Do not dump all words,
  dictionary entries, transcript cues, generated media, or large wordsets.
- Canonical HTML URLs remain the source of record. Markdown exports are compact
  discovery/context surfaces.
- Do not expose admin pages, editor workflows, recording tools, nonces, REST
  mutation endpoints, or private training/control policy through these files.

## Follow-Up Areas

- Site owners may still need explicit `robots.txt`, response header, or
  Cloudflare Content Signals policy for crawl/training preferences.
- WebMCP annotations should be limited to read-only public forms first, such as
  dictionary search, and should not describe mutation/admin forms as public
  tools.
