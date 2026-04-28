# v0.2.6
## 2026-04-28

1. [](#bugfix)
    * Markdown link stripping now handles nested or broken patterns iteratively. A source like `[[Kdenlive](/x)](/x)` (which can appear after a re-accepted suggestion) used to leave dangling `](/url)` fragments that re-introduced the inner phrase as a phantom token. The stripper now peels the innermost match first and repeats until clean. Same fix in the frontend context preview's skip-zones.
    * The phrase-length slider is now authoritative for every target type. Previously taxonomy targets bypassed it (so single-word values surfaced even at slider=2+), which contradicted the user's intent. To see single-word taxonomy matches, drag the slider to 1.
2. [](#improved)
    * Taxonomy-routes admin help text uses `tag` as the example instead of the more specific `distro`.

# v0.2.5
## 2026-04-27

1. [](#new)
    * **Taxonomy targets.** Each unique `(key, value)` pair across the index becomes a virtual link target whose URL is built from a per-key template. Configure via the new `taxonomy_routes` setting (e.g. `distro: '/artigos/distro:{value}'`). Single-word taxonomy values bypass the modal's exact-phrase-length filter so they surface even when the slider is at 2+ words. Taxonomy suggestions get a small `tag` badge in the modal so editors can tell them apart from page suggestions.
    * **`skip_headings` setting.** Toggle whether phrases inside Markdown headings (`#`, `##`, `###`...) and HTML `<h1>`–`<h6>` blocks are excluded from suggestions. Defaults to enabled (preserves v0.2.3 behavior); disable to receive suggestions for words that appear inside headings.
    * **Show-more for long target lists.** Each suggestion's target list is capped at 10 visible options with a "Show N more" button. Cleans up noisy single-word matches that have hundreds of candidate pages.
    * **Refresh after Accept.** When a link is inserted, the modal refetches suggestions so other matches that overlap the just-linked region disappear automatically. A spinner overlays the matches list during the refresh.
2. [](#improved)
    * `keyword_field` (single string) becomes `keyword_fields` (array). Admin field is now a selectize tag input with predefined options (`focus_keyword`, `keywords`, `seo_title`, `meta_title`, `aliases`, `tags`, `meta_description`) plus free-form add. Legacy single-string config is auto-migrated to array form on read.
    * Indexer now uses Symfony YAML (when available) so nested taxonomy front-matter (`taxonomy: { distro: [...] }`) is parsed correctly.
    * IIFE survives being inlined late by the asset pipeline — the editor-button observer starts immediately when `document.readyState !== 'loading'`.
3. [](#bugfix)
    * `insertLink` is now case-insensitive and word-boundary-aware. The wrapped text preserves the source's original casing. Fixes the `Phrase no longer found in content` error when the title-derived phrase had different casing than the source occurrence (e.g. *Kernel Linux 7.0* vs *kernel Linux 7.0*).

# v0.2.3
## 2026-04-27

1. [](#improved)
    * Source content is now tokenized with the same rules as target titles before phrase lookup. Compound tokens like `22.3`, `RISC-V`, `5.18`, `front-end` stay atomic on both sides — a phrase only matches when it appears as a contiguous sequence of *whole* tokens. Fixes false positives like "Linux Mint 22" matching inside "Linux Mint 22.3".
    * Markdown headings (`#`, `##`, `###` … `######`), Setext headings (text + `===` / `---` underline), and HTML `<h1>`–`<h6>` blocks are stripped from the source before matching. Phrases that only appear inside a heading are no longer suggested. The in-modal context preview also skips heading content.
    * Toolbar button shows a spinning icon (`fa-circle-o-notch fa-spin`) during analysis instead of the text "Analyzing…", preserving the compact icon-only toolbar layout.

# v0.2.2
## 2026-04-27

1. [](#new)
    * **Phrase length slider** in the suggestions modal — drag to filter results by exact phrase length (1 to 6 words). Defaults to the configured `min_phrase_words`.
    * Modal height is now fixed at 80vh so the sliders don't shift while you drag.
2. [](#improved)
    * Per-target dedup now subsumes only by overlap. Unrelated short matches in the same target's title (e.g. *Software* and *QEMU*) are both kept; only fragments contained inside a longer matching phrase are dropped.
    * Backend always generates phrases from 1 word up; the `min_phrase_words` config is the slider's default value rather than a hard backend cutoff.
    * Internal result cap raised from 30 → 500 so single-word matches aren't silently truncated.
    * Stopword list expanded with Portuguese articles, prepositions, conjunctions, pronouns, and common short English words.
    * Tokenizer preserves dots and underscores between alphanumerics — `6.1.4` is one token, *not* `6`, `1`, `4`.
    * Suggestions inside markdown links, autolinks, HTML anchors, bare URLs, code blocks, and inline code are now ignored.
    * Context preview lands on a real plain-text occurrence — never on a substring inside a longer word inside a markdown link.
3. [](#bugfix)
    * Self-suggestion exclusion fixed for pages nested under the home alias. The current page route is now derived from the URL (which always reflects the page being edited) instead of the form's first `data[route]` input (which is the parent route).
    * Radio buttons inside a target group share the same `name` attribute, so picking a different target deselects the previous one.
    * Pure-number 1-word phrases (`5.18`, `40`) no longer surface as standalone suggestions.

# v0.2.1
## 2026-04-26

1. [](#bugfix)
    * Removed the unwired "Rebuild Index" button from the settings panel — Grav admin forms do not support `type: button`, so it was rendering as an unlabeled empty input. The index is rebuilt automatically when missing and via `onPageSave` / `onPageDelete` hooks.

# v0.2.0
## 2026-04-25

1. [](#new)
    * Title-driven matching: target-page titles drive the search instead of source-content phrases. Suggestions are now guaranteed to be visible in the target's title (no more phantom matches from coincidental body-text overlap).
    * Per-target dedup: each candidate page surfaces at most once, with the longest matching title-fragment as the suggested anchor text.
    * Optional `keyword_field` setting to pull additional candidate phrases from a front-matter field (e.g. `focus_keyword`).
    * `min_phrase_words` setting (default 2) — minimum n-gram length for title fragments. Range 1–5.
    * `ignored_terms` setting — site-specific filler words (e.g. `linux`) that count as stopwords for phrase generation.
2. [](#improved)
    * Confidence score is now coverage-based (matched phrase length as % of full target title), making the in-modal slider meaningful.
    * Default `match_threshold` lowered to 0; the in-modal slider is the primary live filter.
    * Robust self-suggestion exclusion: route comparison now normalizes the home alias on both sides.
    * Frontend derives the current page's route from the URL when no route input is present.
3. [](#removed)
    * Dropped `match_fields` setting (replaced by `keyword_field`).
    * Dropped content-body matching and the title/field/content score weighting (everything is title-derived now).

# v0.1.0
## 2026-04-24

1. [](#new)
    * Initial release
    * Toolbar icon injected into the Grav page editor
    * SEO-driven phrase matching (title / custom fields / content) with word-count boost
    * Grouped suggestions with selectable target list per phrase
    * Context preview around each matched phrase (configurable length)
    * Live confidence threshold slider in the review modal
    * Cached JSON index with hooks for incremental updates on page save/delete
    * Respects `system.home.hide_in_urls` when generating target routes
