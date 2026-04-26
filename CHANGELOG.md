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
