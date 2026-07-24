'use strict';

(() => {
    const dialog = document.getElementById('template-dialog');
    const tiles = Array.from(document.querySelectorAll('.template-tile'));
    if (!dialog || tiles.length === 0) return;

    const previewCache = new Map();
    const previewRequests = new WeakMap();
    const previewRenders = new WeakMap();
    let dialogTile = null;
    let dialogPreviewValid = false;

    function escapeHtml(value) {
        const element = document.createElement('span');
        element.textContent = String(value ?? '');
        return element.innerHTML;
    }

    const fallbackDocument = (title, message) => `<!doctype html><html><head><meta charset="utf-8"><meta name="color-scheme" content="light"><style>body{margin:0;padding:28px;background:#f4f8fb;color:#09223d;font:15px/1.6 Arial,sans-serif}.email{max-width:620px;margin:auto;background:#fff;border:1px solid #dce8f1;border-radius:12px;overflow:hidden}.brand{padding:18px 22px;background:#09223d;color:#fff;font-weight:700}.body{padding:28px}.body h1{margin:0 0 12px;font-size:23px}.body p{margin:0;color:#49647a}.footer{padding:15px 22px;border-top:1px solid #dce8f1;color:#6c8293;font-size:12px}</style></head><body><div class="email"><div class="brand">POS Printer Emulator</div><div class="body"><h1>${escapeHtml(title)}</h1><p>${escapeHtml(message)}</p></div><div class="footer">EPCOM Ltd. · Customer communication</div></div></body></html>`;

    function previewDocument(html) {
        return `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data: https://buy.posprinteremulator.com https://www.posprinteremulator.com https://posprinteremulator.com https://userportal.posprinteremulator.com; style-src 'unsafe-inline'"><meta name="referrer" content="no-referrer"><meta name="color-scheme" content="light"><style>html{background:#eef4f8}body{box-sizing:border-box;margin:0 auto;min-height:100%;padding:18px;overflow-wrap:anywhere;background:#fff;color:#10233d}img{max-width:100%;height:auto}table{max-width:100%}</style></head><body>${html}</body></html>`;
    }

    function setPreview(frame, loading, documentHtml, statusText) {
        const render = Symbol('preview-render');
        previewRenders.set(frame, render);
        frame.hidden = false;
        loading.textContent = statusText;
        loading.hidden = false;
        frame.addEventListener('load', () => {
            if (previewRenders.get(frame) === render) loading.hidden = true;
        }, {once: true});
        frame.srcdoc = documentHtml;
    }

    function setEnvelope(payload) {
        document.getElementById('template-preview-from').textContent =
            `${payload.sender?.name || 'Unknown sender'} <${payload.sender?.email || 'missing email'}>`;
        document.getElementById('template-preview-to').textContent =
            `${payload.recipient?.name || 'Alex Morgan'} <${payload.recipient?.email || 'alex.morgan@example.com'}>`;
        document.getElementById('template-preview-subject').textContent = payload.subject || 'Missing subject';
        document.getElementById('template-preview-text').textContent = payload.preview_text || 'No preview text';
        const warningBox = document.getElementById('template-preview-warnings');
        const warnings = Array.isArray(payload.warnings) ? payload.warnings : [];
        warningBox.replaceChildren();
        warningBox.hidden = warnings.length === 0;
        if (warnings.length > 0) {
            const heading = document.createElement('strong');
            heading.textContent = 'Preview must be corrected before activation';
            const list = document.createElement('ul');
            warnings.forEach(warning => {
                const item = document.createElement('li');
                item.textContent = warning;
                list.append(item);
            });
            warningBox.append(heading, list);
        }
        dialogPreviewValid = payload.valid === true;
        const note = document.getElementById('template-preview-approval-note');
        note.textContent = dialogPreviewValid
            ? 'Preview passed. This mapping may be enabled.'
            : 'Preview has not passed. Activation remains blocked.';
        note.classList.toggle('valid', dialogPreviewValid);
    }

    async function loadPreview(tile, frame, loading, state, options = {}) {
        const request = Symbol('preview-request');
        previewRequests.set(frame, request);
        const templateId = tile.dataset.templateId || '';
        const name = tile.dataset.templateName || 'Email template';
        const description = tile.dataset.templateDescription || 'Preview this approved email template.';
        loading.hidden = false;
        frame.hidden = true;

        if (!templateId) {
            state.textContent = 'Not mapped';
            setPreview(frame, loading, fallbackDocument(name, 'Save a Brevo mapping while disabled, then generate a preview.'), 'Preview unavailable');
            options.onError?.();
            return;
        }

        state.textContent = 'Loading';
        loading.textContent = 'Validating sender, placeholders, links, and responsive content…';
        try {
            if (options.force) previewCache.delete(templateId);
            let payload = previewCache.get(templateId);
            if (!payload) {
                const response = await fetch(`/api/v1/template-preview.php?template_id=${encodeURIComponent(templateId)}&refresh=${Date.now()}`, {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {'Accept': 'application/json'}
                });
                const responseText = await response.text();
                if (!responseText.trim()) {
                    throw new Error(`The preview service returned an empty response (HTTP ${response.status}).`);
                }
                try {
                    payload = JSON.parse(responseText);
                } catch {
                    throw new Error(`The preview service returned an invalid response (HTTP ${response.status}).`);
                }
                if (!response.ok) {
                    throw new Error(
                        [payload.error, payload.detail].filter(Boolean).join(' ') ||
                        `The preview request failed (HTTP ${response.status}).`
                    );
                }
                previewCache.set(templateId, payload);
            }
            if (previewRequests.get(frame) === request) {
                setPreview(frame, loading, previewDocument(payload.html), 'Rendering the validated preview…');
                state.textContent = payload.valid ? 'Preview passed' : 'Needs attention';
                options.onPayload?.(payload);
            }
        } catch (error) {
            const message = error instanceof Error ? error.message : 'The preview could not be loaded.';
            if (previewRequests.get(frame) === request) {
                setPreview(frame, loading, fallbackDocument(name, message), 'Preview unavailable');
                state.textContent = 'Unavailable';
                options.onError?.(message);
            }
        }
    }

    function selectTile(tile) {
        tiles.forEach(candidate => candidate.classList.toggle('selected', candidate === tile));
        document.getElementById('template-preview-name').textContent = tile.dataset.templateName || 'Email template';
        document.getElementById('template-preview-description').textContent = tile.dataset.templateDescription || '';
        document.getElementById('template-preview-trigger').textContent =
            tile.dataset.templateTrigger || 'No trigger flow has been documented.';
        loadPreview(
            tile,
            document.getElementById('template-preview-frame'),
            document.getElementById('template-preview-loading'),
            document.getElementById('template-preview-state')
        );
    }

    function openDialog(tile) {
        dialogTile = tile;
        dialogPreviewValid = tile.dataset.previewValid === '1';
        document.getElementById('template-dialog-key').value = tile.dataset.templateKey || '';
        document.getElementById('template-dialog-title').textContent = tile.dataset.templateName || 'Edit template';
        document.getElementById('template-dialog-class').textContent =
            `${tile.dataset.templateClass || 'Email'}${tile.dataset.templateEssential === '1' ? ' · Essential' : ''}`;
        document.getElementById('template-dialog-description').textContent = tile.dataset.templateDescription || '';
        document.getElementById('template-dialog-trigger').textContent =
            tile.dataset.templateTrigger || 'No trigger flow has been documented.';
        document.getElementById('template-dialog-id').value = tile.dataset.templateId || '';
        document.getElementById('template-dialog-cap').value = tile.dataset.templateCap || '';
        document.getElementById('template-dialog-enabled').checked = tile.dataset.templateEnabled === '1';
        const assignedTags = new Set((tile.dataset.templateTags || '').split(',').filter(Boolean));
        dialog.querySelectorAll('[data-template-tag-input]').forEach(input => {
            input.checked = assignedTags.has(input.dataset.templateTagInput || '');
        });
        document.getElementById('template-dialog-preview-name').textContent = tile.dataset.templateName || 'Template preview';
        document.getElementById('template-preview-warnings').hidden = true;
        loadPreview(
            tile,
            document.getElementById('template-dialog-frame'),
            document.getElementById('template-dialog-loading'),
            document.getElementById('template-dialog-state'),
            {
                onPayload: setEnvelope,
                onError: message => {
                    dialogPreviewValid = false;
                    document.getElementById('template-preview-approval-note').textContent =
                        `Preview failed: ${message || 'unknown error'}`;
                }
            }
        );
        dialog.showModal();
    }

    let hoverTimer = 0;
    tiles.forEach(tile => {
        tile.addEventListener('mouseenter', () => {
            window.clearTimeout(hoverTimer);
            hoverTimer = window.setTimeout(() => selectTile(tile), 180);
        });
        tile.addEventListener('focus', () => selectTile(tile));
        tile.addEventListener('click', () => openDialog(tile));
    });

    dialog.querySelectorAll('[data-template-close]').forEach(button => {
        button.addEventListener('click', () => dialog.close());
    });
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });

    document.getElementById('template-dialog-enabled').addEventListener('change', event => {
        if (event.target.checked && !dialogPreviewValid) {
            event.target.checked = false;
            document.getElementById('template-dialog-state').textContent = 'Preview required';
            document.getElementById('template-preview-approval-note').textContent =
                'Generate a successful preview before enabling this template.';
        }
    });
    document.getElementById('template-dialog-id').addEventListener('input', event => {
        if (!dialogTile || event.target.value !== (dialogTile.dataset.templateId || '')) {
            dialogPreviewValid = false;
            document.getElementById('template-dialog-enabled').checked = false;
            document.getElementById('template-preview-approval-note').textContent =
                'Save the new mapping while disabled, then refresh its preview before activation.';
        }
    });
    document.getElementById('template-preview-refresh').addEventListener('click', () => {
        if (!dialogTile) return;
        loadPreview(
            dialogTile,
            document.getElementById('template-dialog-frame'),
            document.getElementById('template-dialog-loading'),
            document.getElementById('template-dialog-state'),
            {force: true, onPayload: setEnvelope, onError: () => { dialogPreviewValid = false; }}
        );
    });
    dialog.querySelectorAll('[data-preview-viewport]').forEach(button => {
        button.addEventListener('click', () => {
            const mobile = button.dataset.previewViewport === 'mobile';
            document.getElementById('template-dialog-frame-wrap').classList.toggle('mobile-preview', mobile);
            dialog.querySelectorAll('[data-preview-viewport]').forEach(candidate => {
                candidate.classList.toggle('active', candidate === button);
            });
        });
    });

    const activeTags = new Set();
    let statusFilter = 'all';
    const tagButtons = Array.from(document.querySelectorAll('[data-template-tag-filter]'));
    const statusButtons = Array.from(document.querySelectorAll('[data-template-status-filter]'));
    const resultLabel = document.getElementById('template-filter-results');

    function applyFilters() {
        let visibleCount = 0;
        tiles.forEach(tile => {
            const tags = new Set((tile.dataset.templateTags || '').split(',').filter(Boolean));
            const matchesTags = Array.from(activeTags).every(tag => tags.has(tag));
            const matchesStatus = statusFilter === 'all' || tile.dataset.templateStatus === statusFilter;
            tile.hidden = !(matchesTags && matchesStatus);
            if (!tile.hidden) visibleCount++;
        });
        if (resultLabel) resultLabel.textContent = `${visibleCount} shown`;
        const selected = tiles.find(tile => tile.classList.contains('selected') && !tile.hidden);
        const firstVisible = tiles.find(tile => !tile.hidden);
        if (!selected && firstVisible) {
            selectTile(firstVisible);
        } else if (!firstVisible) {
            document.getElementById('template-preview-name').textContent = 'No matching templates';
            document.getElementById('template-preview-description').textContent = 'Clear one or more filters to return templates to the registry.';
            document.getElementById('template-preview-state').textContent = '0 shown';
            document.getElementById('template-preview-frame').hidden = true;
            const loading = document.getElementById('template-preview-loading');
            loading.textContent = 'No templates match the selected filters.';
            loading.hidden = false;
        }
    }

    tagButtons.forEach(button => {
        button.addEventListener('click', () => {
            const tag = button.dataset.templateTagFilter || '';
            if (activeTags.has(tag)) activeTags.delete(tag);
            else activeTags.add(tag);
            button.setAttribute('aria-pressed', activeTags.has(tag) ? 'true' : 'false');
            applyFilters();
        });
    });
    statusButtons.forEach(button => {
        button.addEventListener('click', () => {
            statusFilter = button.dataset.templateStatusFilter || 'all';
            statusButtons.forEach(candidate => {
                candidate.setAttribute('aria-pressed', candidate === button ? 'true' : 'false');
            });
            applyFilters();
        });
    });
    document.querySelector('[data-template-clear-filters]')?.addEventListener('click', () => {
        activeTags.clear();
        statusFilter = 'all';
        tagButtons.forEach(button => button.setAttribute('aria-pressed', 'false'));
        statusButtons.forEach(button => {
            button.setAttribute('aria-pressed', button.dataset.templateStatusFilter === 'all' ? 'true' : 'false');
        });
        applyFilters();
    });

    selectTile(tiles[0]);
})();
