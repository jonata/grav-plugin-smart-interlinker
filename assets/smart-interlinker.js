(function() {
    'use strict';
    // taxonomy-rendering v1

    function initButton() {
        const editorContainer = document.querySelector('.grav-editor');
        if (!editorContainer) return false;
        if (editorContainer.querySelector('.smart-interlinker-button')) return true;

        const codeBtn = editorContainer.querySelector('.grav-editor-toolbar .fa-code')?.closest('a, button');
        if (!codeBtn) return false;

        const button = document.createElement('a');
        button.className = 'hint--top smart-interlinker-button';
        button.setAttribute('data-hint', 'Review internal links');
        button.innerHTML = '<i class="fa fa-fw fa-sitemap"></i>';

        codeBtn.parentNode.insertBefore(button, codeBtn);
        button.addEventListener('click', analyzeInternalLinks);
        return true;
    }

    function startObservingForButton() {
        if (initButton()) return;
        const observer = new MutationObserver(() => {
            if (initButton()) observer.disconnect();
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startObservingForButton);
    } else {
        // Script was inlined after DOMContentLoaded — start observing immediately.
        startObservingForButton();
    }

    function showToast(message, type) {
        const existing = document.querySelector('.smart-interlinker-toast');
        if (existing) existing.remove();
        const toast = document.createElement('div');
        toast.className = 'smart-interlinker-toast smart-interlinker-toast-' + (type || 'info');
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.classList.add('visible'), 10);
        setTimeout(() => {
            toast.classList.remove('visible');
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    }

    function analyzeInternalLinks(e) {
        e.preventDefault();

        const editor = getEditor();
        if (!editor) {
            showToast('Could not find editor', 'error');
            return;
        }

        const content = editor.type === 'codemirror' ? editor.cm.getValue() : editor.el.value;
        const currentRoute = deriveCurrentRoute();

        const btn = e.target.closest('.smart-interlinker-button') || e.target;
        const icon = btn.querySelector('i');
        const originalIconClass = icon ? icon.className : '';
        btn.style.pointerEvents = 'none';
        btn.style.opacity = '0.6';
        if (icon) icon.className = 'fa fa-fw fa-circle-o-notch fa-spin';

        const restore = () => {
            btn.style.pointerEvents = '';
            btn.style.opacity = '';
            if (icon) icon.className = originalIconClass;
        };

        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                'task': 'smart-interlinker.analyze',
                'content': content,
                'route': currentRoute,
                'admin-nonce': document.querySelector('input[name="admin-nonce"]')?.value || ''
            })
        })
        .then(r => r.json())
        .then(data => {
            restore();
            if (!data.matches || data.matches.length === 0) {
                showToast('No link suggestions found (index size: ' + (data.index_size || 0) + ')', 'info');
                return;
            }
            showModal(data.matches, editor, content);
        })
        .catch(err => {
            console.error(err);
            showToast('Error analyzing internal links', 'error');
            restore();
        });
    }

    function deriveCurrentRoute() {
        // The URL is the only reliable source: /admin/pages/<route> always reflects the
        // page being edited. The form has multiple data[route] inputs (parent, current,
        // aliases) and querySelector picks the first one, which is usually the parent —
        // so we don't trust it as the primary signal.
        const m = window.location.pathname.match(/\/admin\/pages\/(.+?)(?:\.json)?\/?$/);
        if (m && m[1]) {
            const slug = m[1].replace(/^\/+|\/+$/g, '');
            return '/' + slug;
        }

        // Last-resort fallback: scan all data[route] inputs and pick the longest non-empty one
        // (the deepest path is most likely to be the current page).
        const candidates = Array.from(document.querySelectorAll('input[name="data[route]"], input[name="route"]'))
            .map(i => (i.value || '').trim())
            .filter(v => v && v !== '/');
        if (candidates.length) {
            candidates.sort((a, b) => b.length - a.length);
            const v = candidates[0];
            return v.startsWith('/') ? v : '/' + v;
        }

        return '/';
    }

    function getEditor() {
        const cmEl = document.querySelector('.CodeMirror');
        if (cmEl && cmEl.CodeMirror) {
            return { type: 'codemirror', cm: cmEl.CodeMirror };
        }
        const textarea = document.querySelector('textarea[name="data[content]"]');
        if (textarea) return { type: 'textarea', el: textarea };
        return null;
    }

    /**
     * Returns the index in `source` of the first word-bounded occurrence of `phrase`
     * that is NOT inside a skip-zone (markdown link, URL, heading, code, etc.), or -1
     * if no such occurrence exists. Used by both the context preview and insertLink so
     * the displayed location and the actually-replaced location are guaranteed to match.
     */
    function findFreeOccurrence(source, phrase) {
        if (!source || phrase === undefined || phrase === null) return -1;
        phrase = String(phrase);
        if (!phrase) return -1;

        const skipZones = [];
        const cfg = window.SmartInterlinkerConfig || {};
        const skipHeadings = cfg.skip_headings !== false;
        const collectors = [
            ...(skipHeadings ? [
                /^[ \t]{0,3}#{1,6}[ \t]+.*$/gm,        // ATX heading line
                /<h[1-6]\b[^>]*>[\s\S]*?<\/h[1-6]>/gi, // HTML heading
            ] : []),
            /!?\[[^\[\]]*\]\([^\)]*\)/g,       // markdown image/link (innermost)
            /\[[^\[\]]*\]\[[^\[\]]*\]/g,       // reference link
            /<https?:\/\/[^>]+>/gi,            // autolink
            /<a\b[^>]*>[\s\S]*?<\/a>/gi,       // HTML anchor
            /\bhttps?:\/\/[^\s<>()\[\]"']+/gi, // bare URL
            /\bwww\.[^\s<>()\[\]"']+/gi,       // bare www URL
            /```[\s\S]*?```/g,                 // fenced code
            /`[^`]*`/g,                        // inline code
            /<!--[\s\S]*?-->/g,                // HTML comment
        ];
        for (const rx of collectors) {
            let m;
            while ((m = rx.exec(source)) !== null) skipZones.push([m.index, m.index + m[0].length]);
        }
        // Pick up dangling ](url) fragments that sit right after a stripped link.
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

    function buildContextSnippet(source, phrase, radius) {
        const idx = findFreeOccurrence(source, phrase);
        if (idx === -1) return '';
        radius = radius || 40;
        const phraseStr = String(phrase);
        const start = Math.max(0, idx - radius);
        const end = Math.min(source.length, idx + phraseStr.length + radius);
        const beforeStr = (start > 0 ? '…' : '') + source.substring(start, idx);
        const matched = source.substring(idx, idx + phraseStr.length);
        const afterStr = source.substring(idx + phraseStr.length, end) + (end < source.length ? '…' : '');
        return `<span class="ctx-before">${escapeHtml(beforeStr)}</span><span class="ctx-match">${escapeHtml(matched)}</span><span class="ctx-after">${escapeHtml(afterStr)}</span>`;
    }

    function showModal(initialMatches, editor, initialSource) {
        if (!initialMatches || initialMatches.length === 0) {
            showToast('No link suggestions found', 'info');
            return;
        }

        // Mutable so we can refresh after each Accept.
        let matches = initialMatches;
        let sourceContent = initialSource;

        const modal = document.createElement('div');
        modal.className = 'smart-interlinker-modal-overlay';
        modal.id = 'smart-interlinker-modal';

        const content = document.createElement('div');
        content.className = 'smart-interlinker-modal-content';

        const header = document.createElement('div');
        header.className = 'smart-interlinker-modal-header';
        header.innerHTML = `
            <h2>Internal Link Suggestions</h2>
            <button class="smart-interlinker-close">&times;</button>
        `;

        const list = document.createElement('div');
        list.className = 'smart-interlinker-list';

        const cfgInit = window.SmartInterlinkerConfig || {};
        let threshold = Math.max(0, Math.min(100, parseInt(cfgInit.match_threshold) || 50));
        let minWords = Math.max(1, Math.min(6, parseInt(cfgInit.min_phrase_words) || 2));

        const filters = document.createElement('div');
        filters.className = 'smart-interlinker-filters';
        filters.innerHTML = `
            <div class="smart-interlinker-filter">
                <label>Minimum confidence: <span id="sil-threshold-value">${threshold}</span>%</label>
                <input type="range" id="sil-threshold-slider" min="0" max="100" value="${threshold}">
            </div>
            <div class="smart-interlinker-filter">
                <label>Phrase length: <span id="sil-minwords-value">${minWords}</span> <span id="sil-minwords-unit">word${minWords === 1 ? '' : 's'}</span> exactly</label>
                <input type="range" id="sil-minwords-slider" min="1" max="6" value="${minWords}">
            </div>
        `;

        filters.querySelector('#sil-threshold-slider').addEventListener('input', (e) => {
            threshold = parseInt(e.target.value);
            document.getElementById('sil-threshold-value').textContent = threshold;
            renderMatches();
        });

        filters.querySelector('#sil-minwords-slider').addEventListener('input', (e) => {
            minWords = parseInt(e.target.value);
            document.getElementById('sil-minwords-value').textContent = minWords;
            document.getElementById('sil-minwords-unit').textContent = 'word' + (minWords === 1 ? '' : 's');
            renderMatches();
        });

        list.appendChild(filters);

        const matchesContainer = document.createElement('div');
        matchesContainer.className = 'smart-interlinker-matches';
        matchesContainer.id = 'matches-container';
        list.appendChild(matchesContainer);

        // Render fn closes over the current matches/sourceContent so refresh can update them.
        const renderMatches = () => {
            updateMatchesList(matches, matchesContainer, threshold, minWords, editor, sourceContent, refreshAfterAccept);
        };

        // Refetch suggestions from the backend with the latest editor content. Used after
        // an Accept so that suggestions overlapping the just-inserted link disappear.
        const refreshAfterAccept = () => {
            matchesContainer.classList.add('refreshing');
            const newContent = editor.type === 'codemirror' ? editor.cm.getValue() : editor.el.value;
            sourceContent = newContent;
            const route = deriveCurrentRoute();
            return fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    'task': 'smart-interlinker.analyze',
                    'content': newContent,
                    'route': route,
                    'admin-nonce': document.querySelector('input[name="admin-nonce"]')?.value || ''
                })
            })
            .then(r => r.json())
            .then(data => {
                matches = data.matches || [];
                renderMatches();
            })
            .catch(err => {
                console.error(err);
                showToast('Refresh failed', 'error');
            })
            .finally(() => {
                matchesContainer.classList.remove('refreshing');
            });
        };

        renderMatches();

        content.appendChild(header);
        content.appendChild(list);
        modal.appendChild(content);
        document.body.appendChild(modal);

        const closeBtn = header.querySelector('.smart-interlinker-close');
        closeBtn.addEventListener('click', () => modal.remove());

        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.remove();
        });
    }

    function updateMatchesList(groups, container, threshold, exactWords, editor, sourceContent, onAccepted) {
        // A group is shown when its best_score is at or above the threshold AND its
        // matched-phrase word count matches the slider's exact length. The phrase-length
        // filter is authoritative for every target type (page or taxonomy).
        const cfg = window.SmartInterlinkerConfig || {};
        const ctxLen = cfg.context_length || 80;
        const filtered = groups
            .filter(g => g.best_score >= threshold && g.word_count === exactWords)
            // Drop groups whose phrase has no plain-text occurrence the user can see —
            // every match is inside a skip-zone (markdown link, URL, heading, code...).
            // Without a context preview the suggestion is misleading.
            .map(g => ({...g, _context: buildContextSnippet(sourceContent, g.phrase, ctxLen)}))
            .filter(g => g._context !== '');
        container.innerHTML = '';

        if (filtered.length === 0) {
            container.innerHTML = '<p class="no-matches">No matches with the current filters</p>';
            return;
        }

        filtered.forEach(group => {
            const item = document.createElement('div');
            item.className = 'smart-interlinker-item';

            const groupName = 'sil-target-' + Math.random().toString(36).slice(2, 10);
            const initialLimit = 10;
            const totalTargets = group.targets.length;
            const targetsHtml = group.targets.map((t, idx) => {
                const isTax = t.type === 'taxonomy';
                const badge = isTax
                    ? `<span class="match-target-badge" title="taxonomy: ${escapeHtml(t.taxonomy_key || '')}"><i class="fa fa-tag"></i> ${escapeHtml(t.taxonomy_key || 'tag')}</span>`
                    : '';
                const hidden = idx >= initialLimit ? ' is-hidden' : '';
                return `<label class="match-target-option${isTax ? ' is-taxonomy' : ''}${hidden}">
                    <input type="radio" name="${groupName}" value="${escapeHtml(t.url)}"${idx === 0 ? ' checked' : ''}>
                    ${badge}
                    <span class="match-target-title">${escapeHtml(t.title)}</span>
                    <span class="match-target-score">${Math.round(t.score)}%</span>
                </label>`;
            }).join('');
            const showMoreHtml = totalTargets > initialLimit
                ? `<button type="button" class="match-show-more">Show ${totalTargets - initialLimit} more</button>`
                : '';

            const context = group._context;
            item.innerHTML = `
                <div class="match-context">${context}</div>
                <div class="match-target-list">${targetsHtml}${showMoreHtml}</div>
                <div class="match-actions">
                    <div class="match-meta">
                        <span class="match-wc">${group.word_count}-word</span>
                        <span class="match-meta-sep">•</span>
                        <span>Best: ${Math.round(group.best_score)}%</span>
                    </div>
                    <div class="match-buttons">
                        <button class="btn btn-sm btn-success accept-btn">Accept</button>
                        <button class="btn btn-sm btn-secondary decline-btn">Decline</button>
                    </div>
                </div>
            `;

            const showMoreBtn = item.querySelector('.match-show-more');
            if (showMoreBtn) {
                showMoreBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    item.querySelectorAll('.match-target-option.is-hidden').forEach(el => el.classList.remove('is-hidden'));
                    showMoreBtn.remove();
                });
            }

            const getSelected = () => item.querySelector('input[type="radio"]:checked')?.value;
            item.querySelector('.accept-btn').addEventListener('click', () => {
                const url = getSelected();
                if (!url) return;
                const ok = insertLink(editor, group.phrase, url);
                if (ok) {
                    showToast('Link inserted', 'success');
                    if (typeof onAccepted === 'function') {
                        onAccepted();
                        return;
                    }
                } else {
                    showToast('Phrase no longer found in content', 'error');
                }
                item.remove();
                if (container.children.length === 0) {
                    container.innerHTML = '<p class="no-matches">All suggestions reviewed</p>';
                }
            });

            item.querySelector('.decline-btn').addEventListener('click', () => {
                item.remove();
                if (container.children.length === 0) {
                    container.innerHTML = '<p class="no-matches">All suggestions reviewed</p>';
                }
            });
            container.appendChild(item);
        });
    }

    function insertLink(editor, phrase, url) {
        // Use the same skip-zone-aware lookup as the context preview so the replacement
        // happens at the exact occurrence the user saw highlighted, not at the first
        // lexical match (which could be inside an existing markdown link).
        const getContent = () => editor.type === 'codemirror' ? editor.cm.getValue() : editor.el.value;
        const setContent = (v) => {
            if (editor.type === 'codemirror') {
                editor.cm.getDoc().setValue(v);
            } else {
                editor.el.value = v;
                editor.el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        };

        const content = getContent();
        const idx = findFreeOccurrence(content, phrase);
        if (idx === -1) return false;

        const phraseStr = String(phrase);
        const matchedText = content.substring(idx, idx + phraseStr.length);
        const wrapped = `[${matchedText}](${url})`;
        const next = content.substring(0, idx) + wrapped + content.substring(idx + phraseStr.length);
        setContent(next);
        return true;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function escapeRegex(text) {
        return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
})();
