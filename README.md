# Smart Interlinker — Grav Admin Plugin

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Grav 1.7+](https://img.shields.io/badge/Grav-1.7%2B-brown.svg)](https://getgrav.org)

SEO-driven internal linking for the Grav page editor. Scans your draft content for phrases that match other published pages on the site, then lets you insert one-click internal links from a review modal.

> **Note on terminology:** *internal links* connect pages within the same site. *Backlinks* are inbound links from external sites. This plugin is for the former.

## Demo

![Smart Interlinker demo](docs/demo.gif)

![Review modal with grouped suggestions](docs/screenshot.png)

## Features

- **Toolbar icon** (sitemap) injected next to the code-view button in the Grav page editor
- **SEO-first matching** — multi-word phrases ranked above single words; title matches scored higher than content-only hits
- **Grouped suggestions** — one row per unique phrase with a selectable list of all candidate target pages
- **Context preview** — shows the matched phrase with surrounding text so you can judge the match in-place
- **Configurable threshold** — live-filter matches by confidence via a slider in the modal
- **Wrap in place** — accepted matches become `[text](/target-url)` without disturbing surrounding content
- **Cached index** — pages are indexed once and queried in-memory; hooks keep the cache in sync on save/delete

## Install

### Via GPM (recommended, once accepted into the Grav plugin store)

```bash
bin/gpm install smart-interlinker
```

### Manual install

```bash
cd user/plugins
git clone https://github.com/jonata/grav-plugin-smart-interlinker.git smart-interlinker
```

Or download a zip from [the releases page](https://github.com/jonata/grav-plugin-smart-interlinker/releases) and extract it into `user/plugins/smart-interlinker/`.

Then enable it:

```yaml
# user/config/plugins/smart-interlinker.yaml
enabled: true
```

Admin → Plugins → Smart Interlinker exposes all settings graphically.

## Requirements

- Grav ≥ 1.7
- Admin plugin ≥ 1.10

## Configuration

| Setting | Default | Description |
|---|---|---|
| `enabled` | `true` | Master toggle |
| `match_fields` | `[summary, category]` | Front-matter fields to include in matching alongside title + content |
| `match_threshold` | `70` | Minimum confidence (0-100) for a candidate to be considered a match |
| `context_length` | `80` | Characters of surrounding text shown before/after the matched phrase |

## How matching works

### Phrase extraction

From the current page's markdown, the plugin strips images, fenced code, inline code, HTML comments, and markdown syntax, then splits on whitespace/punctuation. For every position it emits unigrams (≥5 chars, non-stopword), bigrams, and trigrams (≤60 chars). A Portuguese + English stopword list filters out phrases made entirely of filler words.

### Scoring

Each candidate phrase is compared against every indexed page. Score depends on *where* the phrase was found:

| Location | Base score |
|---|---|
| Page title | 100 |
| Custom front-matter field | 85 |
| Body content only | 70 |

An **SEO boost** is added to reward longer anchor text:

- Bigram match: +10
- Trigram match: +20

The boosted score is capped at 100. Results are sorted by word count (trigrams first), then by score.

### Grouping

Results are grouped by unique phrase. If three different articles all match *"Shotcut 19.05"*, the modal shows one row for *"Shotcut 19.05"* with a list of the three candidate targets — pick one, click Accept.

## Index cache

- Stored as JSON at `user/plugins/smart-interlinker/cache/interlinks-index.json`
- Auto-built on first analyze request if missing
- Updated incrementally via Grav hooks:
  - `onPageSave` → refresh entry
  - `onPageDelete` → remove entry
  - Unpublished pages are pruned on save

For large sites, iterating the pages directory takes seconds; querying the cache takes milliseconds.

## URL handling

The plugin respects `system.home.hide_in_urls` + `system.home.alias`. Pages under the home alias directory (e.g. `/home/...`) are rewritten to their public routes (e.g. `/article-slug`) in both the target URL and the inserted markdown link.

## Files

```
smart-interlinker/
├── smart-interlinker.php          # plugin class, hooks, task handlers, index logic
├── smart-interlinker.yaml         # defaults
├── blueprints.yaml                # admin UI
├── assets/
│   ├── smart-interlinker.js       # editor button, modal, fetch, link insertion
│   └── smart-interlinker.css
└── cache/
    └── interlinks-index.json      # generated on first run
```

## Tasks (internal)

- `task=smart-interlinker.analyze` — POST content + route, returns grouped matches
- `task=smart-interlinker.rebuild` — force rebuild the index

Both are registered via the `onTask.*` event on the plugin class.

## Contributing

Bug reports and pull requests welcome at [github.com/jonata/grav-plugin-smart-interlinker](https://github.com/jonata/grav-plugin-smart-interlinker/issues).

## License

Released under the [MIT License](LICENSE).
