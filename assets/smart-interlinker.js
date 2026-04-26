(function() {
    'use strict';

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

    document.addEventListener('DOMContentLoaded', function() {
        if (initButton()) return;
        const observer = new MutationObserver(() => {
            if (initButton()) observer.disconnect();
        });
        observer.observe(document.body, { childList: true, subtree: true });
    });

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

        const btn = e.target;
        btn.style.pointerEvents = 'none';
        btn.style.opacity = '0.6';
        const originalText = btn.innerHTML;
        btn.innerHTML = '⏳ Analyzing...';

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
            btn.style.pointerEvents = '';
            btn.style.opacity = '';
            btn.innerHTML = originalText;
            if (!data.matches || data.matches.length === 0) {
                showToast('No link suggestions found (index size: ' + (data.index_size || 0) + ')', 'info');
                return;
            }
            showModal(data.matches, editor, content);
        })
        .catch(err => {
            console.error(err);
            showToast('Error analyzing internal links', 'error');
            btn.style.pointerEvents = '';
            btn.style.opacity = '';
            btn.innerHTML = originalText;
        });
    }

    function deriveCurrentRoute() {
        const fromInput = document.querySelector('input[name="route"]')?.value
            || document.querySelector('input[name="data[route]"]')?.value;
        if (fromInput) return fromInput.startsWith('/') ? fromInput : '/' + fromInput;

        // Fall back to URL parsing: /admin/pages/<route>
        const m = window.location.pathname.match(/\/admin\/pages\/(.+?)(?:\.json)?\/?$/);
        if (m && m[1]) {
            const slug = m[1].replace(/^\/+|\/+$/g, '');
            return '/' + slug;
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

    function buildContextSnippet(source, phrase, radius) {
        if (!source || !phrase) return '';
        radius = radius || 40;
        const lcSource = source.toLowerCase();
        const idx = lcSource.indexOf(phrase.toLowerCase());
        if (idx === -1) return '';
        const start = Math.max(0, idx - radius);
        const end = Math.min(source.length, idx + phrase.length + radius);
        const before = (start > 0 ? '…' : '') + source.substring(start, idx);
        const matched = source.substring(idx, idx + phrase.length);
        const after = source.substring(idx + phrase.length, end) + (end < source.length ? '…' : '');
        return `<span class="ctx-before">${escapeHtml(before)}</span><span class="ctx-match">${escapeHtml(matched)}</span><span class="ctx-after">${escapeHtml(after)}</span>`;
    }

    function showModal(matches, editor, sourceContent) {
        if (!matches || matches.length === 0) {
            showToast('No link suggestions found', 'info');
            return;
        }

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
        const thresholdSlider = document.createElement('div');
        thresholdSlider.className = 'smart-interlinker-threshold';
        thresholdSlider.innerHTML = `
            <label>Filter by confidence: <span id="threshold-value">${threshold}</span>%</label>
            <input type="range" id="threshold-slider" min="0" max="100" value="${threshold}">
        `;

        const slider = thresholdSlider.querySelector('#threshold-slider');
        slider.addEventListener('input', (e) => {
            threshold = parseInt(e.target.value);
            document.getElementById('threshold-value').textContent = threshold;
            updateMatchesList(matches, matchesContainer, threshold, editor, sourceContent);
        });

        list.appendChild(thresholdSlider);

        const matchesContainer = document.createElement('div');
        matchesContainer.className = 'smart-interlinker-matches';
        matchesContainer.id = 'matches-container';
        list.appendChild(matchesContainer);

        updateMatchesList(matches, matchesContainer, threshold, editor, sourceContent);

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

    function updateMatchesList(groups, container, threshold, editor, sourceContent) {
        const filtered = groups.filter(g => g.best_score >= threshold);
        container.innerHTML = '';

        if (filtered.length === 0) {
            container.innerHTML = '<p class="no-matches">No matches at this confidence level</p>';
            return;
        }

        filtered.forEach(group => {
            const item = document.createElement('div');
            item.className = 'smart-interlinker-item';

            const groupName = 'sil-target-' + Math.random().toString(36).slice(2, 10);
            const targetsHtml = group.targets.map((t, idx) =>
                `<label class="match-target-option">
                    <input type="radio" name="${groupName}" value="${escapeHtml(t.url)}"${idx === 0 ? ' checked' : ''}>
                    <span class="match-target-title">${escapeHtml(t.title)}</span>
                    <span class="match-target-score">${Math.round(t.score)}%</span>
                </label>`
            ).join('');

            const cfg = window.SmartInterlinkerConfig || {};
            const context = buildContextSnippet(sourceContent, group.phrase, cfg.context_length || 80);
            item.innerHTML = `
                ${context ? `<div class="match-context">${context}</div>` : ''}
                <div class="match-target-list">${targetsHtml}</div>
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

            const getSelected = () => item.querySelector('input[type="radio"]:checked')?.value;
            item.querySelector('.accept-btn').addEventListener('click', () => {
                const url = getSelected();
                if (!url) return;
                const ok = insertLink(editor, group.phrase, url);
                if (ok) {
                    showToast('Link inserted', 'success');
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
        const link = `[${phrase}](${url})`;
        const regex = new RegExp(escapeRegex(phrase), '');

        if (editor.type === 'codemirror') {
            const doc = editor.cm.getDoc();
            const content = doc.getValue();
            if (!regex.test(content)) return false;
            doc.setValue(content.replace(regex, link));
            return true;
        } else {
            const content = editor.el.value;
            if (!regex.test(content)) return false;
            editor.el.value = content.replace(regex, link);
            editor.el.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        }
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
