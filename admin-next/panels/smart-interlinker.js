// Smart Interlinker — Grav 2.0 Admin Next context panel.
//
// Registered server-side by SmartInterlinkerPlugin::onApiContextPanels(); Admin
// Next adds the toolbar button to the page editor and mounts this web component
// in the slide-in panel. Hand-written, no bundler (Grav Admin Next convention).
//
// Editor I/O uses the Admin Next editor event bridge (works for both the
// CodeMirror markdown editor and editor-pro/TipTap):
//   - read : dispatch 'grav:editor:get-content' → receive 'grav:editor:content-response'
//   - write: dispatch 'grav:editor:insert-content' { content, mode: 'replace' }
(function () {
    'use strict';

    const TAG = window.__GRAV_PANEL_TAG || 'grav-smart-interlinker--panel';
    if (customElements.get(TAG)) return;

    // ---------------------------------------------------------------- helpers

    function escapeHtml(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Returns the index of the first word-bounded occurrence of `phrase` in
    // `source` that is NOT inside a skip-zone (markdown link, URL, heading, code,
    // …), or -1. Mirrors the classic-admin assets/smart-interlinker.js so the
    // displayed preview and the actually-replaced position always match.
    function findFreeOccurrence(source, phrase, skipHeadings) {
        if (!source || phrase === undefined || phrase === null) return -1;
        phrase = String(phrase);
        if (!phrase) return -1;

        const skipZones = [];
        const collectors = [
            ...(skipHeadings ? [
                /^[ \t]{0,3}#{1,6}[ \t]+.*$/gm,
                /<h[1-6]\b[^>]*>[\s\S]*?<\/h[1-6]>/gi,
            ] : []),
            /!?\[[^\[\]]*\]\([^\)]*\)/g,
            /\[[^\[\]]*\]\[[^\[\]]*\]/g,
            /<https?:\/\/[^>]+>/gi,
            /<a\b[^>]*>[\s\S]*?<\/a>/gi,
            /\bhttps?:\/\/[^\s<>()\[\]"']+/gi,
            /\bwww\.[^\s<>()\[\]"']+/gi,
            /```[\s\S]*?```/g,
            /`[^`]*`/g,
            /<!--[\s\S]*?-->/g,
        ];
        for (const rx of collectors) {
            let m;
            while ((m = rx.exec(source)) !== null) skipZones.push([m.index, m.index + m[0].length]);
        }
        const leftoverRx = /\]\([^\)]*\)/g;
        let lm;
        while ((lm = leftoverRx.exec(source)) !== null) {
            const start = lm.index;
            const end = lm.index + lm[0].length;
            if (skipZones.some(([s, e]) => e === start) || source.charAt(start) === ']') {
                skipZones.push([start, end]);
            }
        }
        const inSkipZone = (pos) => skipZones.some(([s, e]) => pos >= s && pos < e);
        const isWordChar = (ch) => !!ch && /[\p{L}\p{N}]/u.test(ch);
        const phraseLc = phrase.toLowerCase();
        const sourceLc = source.toLowerCase();

        let searchFrom = 0;
        while (true) {
            const idx = sourceLc.indexOf(phraseLc, searchFrom);
            if (idx === -1) return -1;
            const before = source[idx - 1];
            const after = source[idx + phraseLc.length];
            if (!isWordChar(before) && !isWordChar(after) && !inSkipZone(idx)) return idx;
            searchFrom = idx + 1;
        }
    }

    function buildContextSnippet(source, phrase, radius, skipHeadings) {
        const idx = findFreeOccurrence(source, phrase, skipHeadings);
        if (idx === -1) return '';
        radius = radius || 40;
        const phraseStr = String(phrase);
        const start = Math.max(0, idx - radius);
        const end = Math.min(source.length, idx + phraseStr.length + radius);
        const beforeStr = (start > 0 ? '…' : '') + source.substring(start, idx);
        const matched = source.substring(idx, idx + phraseStr.length);
        const afterStr = source.substring(idx + phraseStr.length, end) + (end < source.length ? '…' : '');
        return `<span class="ctx-before">${escapeHtml(beforeStr)}</span>`
            + `<span class="ctx-match">${escapeHtml(matched)}</span>`
            + `<span class="ctx-after">${escapeHtml(afterStr)}</span>`;
    }

    // ---------------------------------------------------- editor event bridge

    // Resolve the live editor draft. The page-edit route answers
    // 'grav:editor:get-content' with { content, route, title, template }.
    function getEditorContent(timeoutMs) {
        return new Promise((resolve) => {
            let done = false;
            const finish = (detail) => {
                if (done) return;
                done = true;
                window.removeEventListener('grav:editor:content-response', handler);
                resolve(detail || {});
            };
            const handler = (e) => finish(e.detail);
            window.addEventListener('grav:editor:content-response', handler);
            window.dispatchEvent(new CustomEvent('grav:editor:get-content'));
            setTimeout(() => finish(null), timeoutMs || 1200);
        });
    }

    // Replace the whole editor document. 'replace' routes through Svelte state +
    // auto-save, mirroring the classic plugin's "set full content" behavior.
    function replaceEditorContent(fullContent) {
        window.dispatchEvent(new CustomEvent('grav:editor:insert-content', {
            detail: { content: fullContent, mode: 'replace' },
        }));
    }

    // --------------------------------------------------------- web component

    class SmartInterlinkerPanel extends HTMLElement {
        static get observedAttributes() { return ['route', 'lang', 'type']; }

        constructor() {
            super();
            this._cfg = { context_length: 80, match_threshold: 50, min_phrase_words: 2, skip_headings: true };
            this._matches = [];
            this._sourceContent = '';
            this._threshold = 50;
            this._minWords = 2;
            this._connected = false;
            // The slider positions are seeded from server config on the first scan
            // only; after that the user's choices are preserved across re-scans
            // (e.g. the automatic refresh that follows an Accept).
            this._filtersInitialized = false;
        }

        connectedCallback() {
            this.attachShadow({ mode: 'open' });
            this._renderShell();
            this._connected = true;
            this._analyze();
        }

        attributeChangedCallback(name, oldVal, newVal) {
            // Editor switched to a different page while the panel stays open.
            if (this._connected && name === 'route' && oldVal !== null && oldVal !== newVal) {
                this._analyze();
            }
        }

        // ---- API helpers ----
        _apiUrl(path) {
            return (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1') + path;
        }
        _headers() {
            const h = { 'Content-Type': 'application/json' };
            const token = window.__GRAV_API_TOKEN;
            if (token) h['X-API-Token'] = token; // X-* survives FastCGI; Bearer can be stripped
            return h;
        }

        async _fetchSuggestions(content, route) {
            const resp = await fetch(this._apiUrl('/smart-interlinker/analyze'), {
                method: 'POST',
                headers: this._headers(),
                body: JSON.stringify({ content, route }),
            });
            const json = await resp.json();
            return json.data || json; // ApiResponse wraps payload in { data: ... }
        }

        // ---- flow ----
        async _analyze() {
            this._setBusy(true);
            try {
                const ed = await getEditorContent();
                const content = typeof ed.content === 'string' ? ed.content : '';
                const route = ed.route || this.getAttribute('route') || '';
                this._sourceContent = content;

                const data = await this._fetchSuggestions(content, route);
                if (data && data.config) {
                    // Always refresh non-UI settings (context length, heading skipping).
                    this._cfg = Object.assign(this._cfg, data.config);
                    // Seed the slider positions from config only on the first scan;
                    // preserve the user's choices on every subsequent re-scan.
                    if (!this._filtersInitialized) {
                        this._threshold = Math.max(0, Math.min(100, parseInt(this._cfg.match_threshold, 10) || 50));
                        this._minWords = Math.max(1, Math.min(6, parseInt(this._cfg.min_phrase_words, 10) || 2));
                        this._syncFilterInputs();
                        this._filtersInitialized = true;
                    }
                }
                this._matches = (data && data.matches) || [];
                this._indexSize = (data && data.index_size) || 0;
                this._renderMatches();
            } catch (err) {
                console.error('[SmartInterlinker]', err);
                this._renderError('Could not fetch link suggestions.');
            } finally {
                this._setBusy(false);
            }
        }

        async _accept(group, url) {
            // Re-read so we wrap the freshest content (the user may have kept typing).
            const ed = await getEditorContent();
            const content = typeof ed.content === 'string' ? ed.content : this._sourceContent;

            const idx = findFreeOccurrence(content, group.phrase, this._cfg.skip_headings !== false);
            if (idx === -1) return false;

            const phraseStr = String(group.phrase);
            const matchedText = content.substring(idx, idx + phraseStr.length);
            const wrapped = `[${matchedText}](${url})`;
            const next = content.substring(0, idx) + wrapped + content.substring(idx + phraseStr.length);
            replaceEditorContent(next);
            this._sourceContent = next;
            return true;
        }

        // ---- rendering ----
        _renderShell() {
            this.shadowRoot.innerHTML = `
                <style>${SmartInterlinkerPanel._styles()}</style>
                <div class="sil-panel">
                    <header class="sil-head">
                        <h2>Internal Link Suggestions</h2>
                        <div class="sil-head-actions">
                            <button class="sil-refresh" title="Re-scan current content" aria-label="Refresh">↻</button>
                            <button class="sil-close" title="Close" aria-label="Close">&times;</button>
                        </div>
                    </header>
                    <div class="sil-filters">
                        <label>Minimum confidence: <span class="sil-thr-val">${this._threshold}</span>%
                            <input type="range" class="sil-thr" min="0" max="100" value="${this._threshold}">
                        </label>
                        <label>Phrase length: <span class="sil-mw-val">${this._minWords}</span> <span class="sil-mw-unit">word${this._minWords === 1 ? '' : 's'}</span> exactly
                            <input type="range" class="sil-mw" min="1" max="6" value="${this._minWords}">
                        </label>
                    </div>
                    <div class="sil-body"><div class="sil-status">Scanning…</div></div>
                </div>
            `;

            const r = this.shadowRoot;
            r.querySelector('.sil-close').addEventListener('click', () => this.dispatchEvent(new CustomEvent('close')));
            r.querySelector('.sil-refresh').addEventListener('click', () => this._analyze());

            const thr = r.querySelector('.sil-thr');
            thr.addEventListener('input', (e) => {
                this._threshold = parseInt(e.target.value, 10);
                r.querySelector('.sil-thr-val').textContent = this._threshold;
                this._renderMatches();
            });
            const mw = r.querySelector('.sil-mw');
            mw.addEventListener('input', (e) => {
                this._minWords = parseInt(e.target.value, 10);
                r.querySelector('.sil-mw-val').textContent = this._minWords;
                r.querySelector('.sil-mw-unit').textContent = 'word' + (this._minWords === 1 ? '' : 's');
                this._renderMatches();
            });
        }

        _syncFilterInputs() {
            const r = this.shadowRoot;
            if (!r) return;
            const thr = r.querySelector('.sil-thr');
            const mw = r.querySelector('.sil-mw');
            if (thr) { thr.value = this._threshold; r.querySelector('.sil-thr-val').textContent = this._threshold; }
            if (mw) {
                mw.value = this._minWords;
                r.querySelector('.sil-mw-val').textContent = this._minWords;
                r.querySelector('.sil-mw-unit').textContent = 'word' + (this._minWords === 1 ? '' : 's');
            }
        }

        _setBusy(busy) {
            const body = this.shadowRoot && this.shadowRoot.querySelector('.sil-body');
            if (body) body.classList.toggle('is-busy', !!busy);
        }

        _renderError(msg) {
            const body = this.shadowRoot.querySelector('.sil-body');
            body.innerHTML = `<div class="sil-status sil-error">${escapeHtml(msg)}</div>`;
        }

        _renderMatches() {
            const body = this.shadowRoot.querySelector('.sil-body');
            if (!body) return;

            const ctxLen = this._cfg.context_length || 80;
            const skipHeadings = this._cfg.skip_headings !== false;
            const filtered = (this._matches || [])
                .filter((g) => g.best_score >= this._threshold && g.word_count === this._minWords)
                .map((g) => Object.assign({}, g, { _context: buildContextSnippet(this._sourceContent, g.phrase, ctxLen, skipHeadings) }))
                .filter((g) => g._context !== '');

            if (filtered.length === 0) {
                body.innerHTML = `<div class="sil-status">No matches with the current filters`
                    + ` <span class="sil-muted">(index: ${this._indexSize || 0} pages)</span></div>`;
                return;
            }

            body.innerHTML = '';
            filtered.forEach((group) => body.appendChild(this._renderItem(group)));
        }

        _renderItem(group) {
            const item = document.createElement('div');
            item.className = 'sil-item';

            const groupName = 'sil-t-' + Math.random().toString(36).slice(2, 10);
            const initialLimit = 10;
            const total = group.targets.length;
            const targetsHtml = group.targets.map((t, idx) => {
                const isTax = t.type === 'taxonomy';
                const badge = isTax
                    ? `<span class="sil-badge" title="taxonomy: ${escapeHtml(t.taxonomy_key || '')}">${escapeHtml(t.taxonomy_key || 'tag')}</span>`
                    : '';
                const hidden = idx >= initialLimit ? ' is-hidden' : '';
                return `<label class="sil-target${hidden}">
                    <input type="radio" name="${groupName}" value="${escapeHtml(t.url)}"${idx === 0 ? ' checked' : ''}>
                    ${badge}
                    <span class="sil-target-title">${escapeHtml(t.title)}</span>
                    <span class="sil-target-score">${Math.round(t.score)}%</span>
                </label>`;
            }).join('');
            const showMoreHtml = total > initialLimit
                ? `<button type="button" class="sil-more">Show ${total - initialLimit} more</button>`
                : '';

            item.innerHTML = `
                <div class="sil-context">${group._context}</div>
                <div class="sil-targets">${targetsHtml}${showMoreHtml}</div>
                <div class="sil-actions">
                    <div class="sil-meta">${group.word_count}-word · best ${Math.round(group.best_score)}%</div>
                    <div class="sil-buttons">
                        <button class="sil-btn sil-accept">Accept</button>
                        <button class="sil-btn sil-decline">Decline</button>
                    </div>
                </div>
            `;

            const more = item.querySelector('.sil-more');
            if (more) {
                more.addEventListener('click', (e) => {
                    e.preventDefault();
                    item.querySelectorAll('.sil-target.is-hidden').forEach((el) => el.classList.remove('is-hidden'));
                    more.remove();
                });
            }

            const getSelected = () => {
                const r = item.querySelector('input[type="radio"]:checked');
                return r ? r.value : null;
            };

            item.querySelector('.sil-accept').addEventListener('click', async () => {
                const url = getSelected();
                if (!url) return;
                const ok = await this._accept(group, url);
                if (ok) {
                    item.remove();
                    // Refresh so suggestions overlapping the just-inserted link disappear.
                    this._analyze();
                } else {
                    this._toast('Phrase no longer found in content');
                }
            });
            item.querySelector('.sil-decline').addEventListener('click', () => {
                item.remove();
                const body = this.shadowRoot.querySelector('.sil-body');
                if (body && !body.querySelector('.sil-item')) {
                    body.innerHTML = '<div class="sil-status">All suggestions reviewed</div>';
                }
            });

            return item;
        }

        _toast(msg) {
            if (window.__GRAV_TOAST && typeof window.__GRAV_TOAST.info === 'function') {
                window.__GRAV_TOAST.info(msg);
            } else {
                console.warn('[SmartInterlinker]', msg);
            }
        }

        static _styles() {
            return `
                :host { display:block; height:100%; }
                * { box-sizing: border-box; }
                .sil-panel { display:flex; flex-direction:column; height:100%;
                    color: var(--foreground, #e5e7eb); font: 13px/1.5 system-ui, sans-serif; }
                .sil-head { display:flex; align-items:center; justify-content:space-between;
                    padding:14px 16px; border-bottom:1px solid var(--border, #2a2a35); }
                .sil-head h2 { margin:0; font-size:15px; font-weight:600; }
                .sil-head-actions { display:flex; gap:4px; }
                .sil-head-actions button { background:none; border:none; cursor:pointer;
                    color: var(--muted-foreground, #9ca3af); font-size:18px; line-height:1;
                    width:30px; height:30px; border-radius:6px; }
                .sil-head-actions button:hover { background: var(--accent, #2a2a35); color: var(--foreground, #e5e7eb); }
                .sil-filters { padding:12px 16px; border-bottom:1px solid var(--border, #2a2a35);
                    display:flex; flex-direction:column; gap:10px; }
                .sil-filters label { display:flex; flex-direction:column; gap:6px;
                    font-size:12px; color: var(--muted-foreground, #9ca3af); }
                .sil-filters input[type=range] { width:100%; accent-color: var(--primary, #6366f1); }
                .sil-body { flex:1; overflow-y:auto; padding:8px 12px; position:relative; }
                .sil-body.is-busy { opacity:.5; pointer-events:none; }
                .sil-status { padding:24px 8px; text-align:center; color: var(--muted-foreground, #9ca3af); }
                .sil-status.sil-error { color:#f87171; }
                .sil-muted { color: var(--muted-foreground, #9ca3af); opacity:.8; }
                .sil-item { border:1px solid var(--border, #2a2a35); border-radius:8px;
                    padding:12px; margin:8px 4px; background: var(--muted, rgba(255,255,255,.02)); }
                .sil-context { font-size:13px; margin-bottom:10px; padding:8px;
                    background: var(--background, #0f0f14); border-radius:6px; word-break:break-word; }
                .ctx-before, .ctx-after { color: var(--muted-foreground, #9ca3af); }
                .ctx-match { background: var(--primary, #6366f1); color:#fff; padding:1px 3px; border-radius:3px; font-weight:600; }
                .sil-targets { display:flex; flex-direction:column; gap:2px; margin-bottom:10px; }
                .sil-target { display:flex; align-items:center; gap:8px; padding:5px 6px; border-radius:5px; cursor:pointer; }
                .sil-target:hover { background: var(--accent, #2a2a35); }
                .sil-target.is-hidden { display:none; }
                .sil-target input { margin:0; accent-color: var(--primary, #6366f1); }
                .sil-target-title { flex:1; word-break:break-word; }
                .sil-target-score { color: var(--muted-foreground, #9ca3af); font-variant-numeric: tabular-nums; }
                .sil-badge { font-size:10px; text-transform:uppercase; letter-spacing:.04em;
                    padding:1px 5px; border-radius:4px; background: var(--primary, #6366f1); color:#fff; }
                .sil-more { background:none; border:none; cursor:pointer; text-align:left; padding:4px 6px;
                    color: var(--primary, #818cf8); font-size:12px; }
                .sil-actions { display:flex; align-items:center; justify-content:space-between; gap:8px;
                    border-top:1px solid var(--border, #2a2a35); padding-top:8px; }
                .sil-meta { font-size:11px; color: var(--muted-foreground, #9ca3af); }
                .sil-buttons { display:flex; gap:6px; }
                .sil-btn { border:1px solid var(--border, #2a2a35); border-radius:6px; padding:4px 12px;
                    cursor:pointer; font-size:12px; background: var(--background, #0f0f14); color: var(--foreground, #e5e7eb); }
                .sil-accept { background: var(--primary, #6366f1); border-color: var(--primary, #6366f1); color:#fff; }
                .sil-accept:hover { filter: brightness(1.1); }
                .sil-decline:hover { background: var(--accent, #2a2a35); }
            `;
        }
    }

    customElements.define(TAG, SmartInterlinkerPanel);
})();
