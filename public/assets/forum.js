/* Divan forum - small progressive enhancements. Everything works without JS
   except the editor toolbar, quoting and the theme switch. */
(function () {
    'use strict';

    const root = document.documentElement;

    // ---- Theme switch (stored in a cookie so the server renders it) ---------
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const current = root.dataset.theme === 'auto'
                ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : root.dataset.theme;
            const next = current === 'dark' ? 'light' : 'dark';
            root.dataset.theme = next;
            document.cookie = 'theme=' + next + ';path=/;max-age=31536000;samesite=lax';
        });
    });

    // ---- Advertise page: periods and the order summary follow the chosen zone -----
    const adForm = document.querySelector('[data-ad-form]');
    if (adForm) {
        const summary = (name, text) => adForm.querySelectorAll('[data-summary="' + name + '"]').forEach((el) => { el.textContent = text; });
        const refresh = () => {
            const zone = adForm.querySelector('input[name="zone_id"]:checked');
            adForm.querySelectorAll('[data-periods]').forEach((group) => {
                const current = zone !== null && group.dataset.periods === zone.value;
                group.hidden = !current;
                group.querySelectorAll('input').forEach((input) => { input.disabled = !current; });
                if (current && !group.querySelector('input:checked')) group.querySelector('input').checked = true;
            });
            const period = adForm.querySelector('input[name="days"]:checked:not(:disabled)');
            summary('title', zone ? zone.dataset.title : '—');
            summary('size', zone ? zone.dataset.size : '—');
            // A text link has no banner to upload.
            adForm.querySelectorAll('[data-banner-only]').forEach((field) => { field.hidden = !!zone && zone.dataset.kind === 'text'; });
            summary('days', period ? period.dataset.label : '—');
            summary('price', period ? period.dataset.price : '—');
            const map = adForm.querySelector('[data-summary-map]');
            if (map) map.dataset.zone = zone ? zone.dataset.zone : '';
        };
        adForm.addEventListener('change', (event) => {
            if (event.target.name === 'zone_id' || event.target.name === 'days') refresh();
        });
        refresh();
    }

    // ---- Collapsible categories (remembered in this browser) ----------------------
    const collapseKey = 'forum.collapsed';
    const readCollapsed = () => {
        try { return JSON.parse(localStorage.getItem(collapseKey) || '[]'); } catch (e) { return []; }
    };
    const setCollapsed = (button, collapsed) => {
        const body = document.querySelector('[data-collapsible="' + button.dataset.collapse + '"]');
        if (body) body.hidden = collapsed;
        button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        // Category bars look different when closed and say what the button will do.
        const bar = button.closest('[data-catbar]');
        if (bar) bar.classList.toggle('is-collapsed', collapsed);
        const label = button.querySelector('.cat-toggle-label');
        if (label) label.textContent = collapsed ? button.dataset.labelClosed : button.dataset.labelOpen;
    };
    document.querySelectorAll('[data-collapse]').forEach((button) => {
        if (readCollapsed().includes(button.dataset.collapse)) setCollapsed(button, true);
        button.addEventListener('click', () => {
            const collapsed = button.getAttribute('aria-expanded') === 'true';
            setCollapsed(button, collapsed);
            const list = readCollapsed().filter((key) => key !== button.dataset.collapse);
            if (collapsed) list.push(button.dataset.collapse);
            try { localStorage.setItem(collapseKey, JSON.stringify(list)); } catch (e) { /* private mode */ }
        });
    });

    // ---- Attachments in the editor (upload, insert, remove) -------------------------
    const csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    document.querySelectorAll('[data-uploader]').forEach((editor) => {
        const textarea = editor.querySelector('textarea');
        const list = editor.querySelector('[data-files]');
        const hint = editor.querySelector('[data-upload-hint]');
        const input = editor.querySelector('input[type="file"]');
        const hintText = hint ? hint.textContent : '';

        const addItem = (file) => {
            const item = document.createElement('li');
            item.dataset.bbcode = file.bbcode;
            item.dataset.delete = file.delete_url;

            if (file.is_image && file.thumb) {
                const image = document.createElement('img');
                image.src = file.thumb;
                image.alt = '';
                item.appendChild(image);
            } else {
                const icon = document.createElement('span');
                icon.className = 'file-icon';
                icon.textContent = '📎';
                item.appendChild(icon);
            }

            const name = document.createElement('span');
            name.className = 'file-name truncate';
            name.textContent = file.name + ' ';
            const size = document.createElement('small');
            size.className = 'muted';
            size.textContent = file.size;
            name.appendChild(size);
            item.appendChild(name);

            const insert = document.createElement('button');
            insert.type = 'button';
            insert.className = 'btn btn-sm';
            insert.dataset.fileInsert = '';
            insert.textContent = editor.dataset.insert;
            item.appendChild(insert);

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-ghost btn-danger';
            remove.dataset.fileRemove = '';
            remove.textContent = '×';
            remove.title = editor.dataset.remove;
            item.appendChild(remove);

            list.appendChild(item);
        };

        const upload = async (file) => {
            const body = new FormData();
            body.append('file', file);
            body.append('key', editor.dataset.key);
            body.append('node_id', editor.dataset.node);
            hint.textContent = file.name + '…';
            try {
                const response = await fetch(editor.dataset.url, {
                    method: 'POST', body, credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const errors = data.errors ? Object.values(data.errors).flat() : [];
                    throw new Error(errors[0] || data.message || editor.dataset.failed);
                }
                addItem(data);
                // Images go straight into the text, where the cursor is.
                if (data.is_image) wrap(textarea, data.bbcode + '\n', '');
                hint.textContent = hintText;
            } catch (error) {
                hint.textContent = file.name + ': ' + (error.message || editor.dataset.failed);
            }
        };
        const uploadAll = async (files) => { for (const file of files) await upload(file); };

        input.addEventListener('change', () => { uploadAll(Array.from(input.files)); input.value = ''; });

        ['dragenter', 'dragover'].forEach((type) => editor.addEventListener(type, (event) => {
            if (event.dataTransfer && Array.from(event.dataTransfer.types).includes('Files')) {
                event.preventDefault();
                editor.classList.add('dragging');
            }
        }));
        ['dragleave', 'drop'].forEach((type) => editor.addEventListener(type, () => editor.classList.remove('dragging')));
        editor.addEventListener('drop', (event) => {
            if (event.dataTransfer && event.dataTransfer.files.length) {
                event.preventDefault();
                uploadAll(Array.from(event.dataTransfer.files));
            }
        });
        const pasted = (event) => {
            const files = Array.from((event.clipboardData || {}).files || []);
            if (files.length) {
                event.preventDefault();
                uploadAll(files);
            }
        };
        textarea.addEventListener('paste', pasted);
        // The same in the visual view: a screenshot pasted there is uploaded, not embedded as raw data.
        const visualBox = editor.querySelector('.editor-visual');
        if (visualBox) visualBox.addEventListener('paste', pasted);

        list.addEventListener('click', async (event) => {
            const item = event.target.closest('li');
            if (!item) return;
            if (event.target.closest('[data-file-insert]')) {
                wrap(textarea, item.dataset.bbcode, '');
            } else if (event.target.closest('[data-file-remove]')) {
                try {
                    await fetch(item.dataset.delete, {
                        method: 'DELETE', credentials: 'same-origin',
                        headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
                    });
                } catch (error) { /* the row is removed either way; orphans are cleaned up on the server */ }
                textarea.value = textarea.value.split(item.dataset.bbcode).join('');
                item.remove();
            }
        });
    });

    // ---- Inline moderation: tick rows, then choose one action for all of them ------------
    document.querySelectorAll('[data-mod-bar]').forEach((bar) => {
        const boxes = () => Array.from(document.querySelectorAll('input[type="checkbox"][form="' + bar.id + '"]'));
        const count = bar.querySelector('[data-count]');
        const action = bar.querySelector('[data-mod-action]');
        const all = document.querySelector('[data-select-all="' + bar.id + '"]');

        const refresh = () => {
            const checked = boxes().filter((box) => box.checked).length;
            bar.hidden = checked === 0;
            if (count) count.textContent = checked;
            bar.querySelectorAll('[data-for]').forEach((field) => {
                const wanted = field.dataset.for.split(' ').includes(action.value);
                field.hidden = !wanted;
                field.querySelectorAll('input, select').forEach((control) => { control.disabled = !wanted; });
            });
        };

        document.addEventListener('change', (event) => {
            if (event.target === all) boxes().forEach((box) => { box.checked = all.checked; });
            if (event.target === all || event.target === action || boxes().includes(event.target)) refresh();
        });
        refresh();
    });

    // ---- Dialogs that need a value from the button that opened them -----------------------
    document.addEventListener('click', (event) => {
        const button = event.target.closest && event.target.closest('[data-dialog][data-fill]');
        if (!button) return;
        const dialog = document.getElementById(button.dataset.dialog);
        const values = JSON.parse(button.dataset.fill);
        Object.keys(values).forEach((name) => {
            const field = dialog && dialog.querySelector('[name="' + name + '"]');
            if (field) field.value = values[name];
        });
    });

    // ---- "/" focuses the header search ------------------------------------------
    const searchInput = document.querySelector('[data-search-input]');
    if (searchInput) {
        document.addEventListener('keydown', (event) => {
            const target = event.target;
            const typing = target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
            if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        });
    }

    // ---- Close <details class="menu"> when clicking elsewhere ---------------
    document.addEventListener('click', (event) => {
        document.querySelectorAll('details.menu[open]').forEach((menu) => {
            if (!menu.contains(event.target)) menu.removeAttribute('open');
        });
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') document.querySelectorAll('details.menu[open]').forEach((m) => m.removeAttribute('open'));
    });

    // ---- Confirmations --------------------------------------------------------
    document.addEventListener('submit', (event) => {
        const message = event.target.dataset.confirm || (event.submitter && event.submitter.dataset.confirm);
        if (message && !confirm(message)) event.preventDefault();
    });

    // ---- BBCode editor: toolbar, visual mode, preview, drafts, emoji -----------------
    const escapeHtml = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    const safeUrl = /^https?:\/\/[^\s"<>\[\]]+$/i;

    // Tags the visual mode does not edit: they are shown as one block and written back untouched.
    function chipHtml(raw) {
        const label = raw.replace(/\s+/g, ' ').slice(0, 70) + (raw.length > 70 ? '…' : '');
        return '<span class="bb-chip" contenteditable="false" data-bb="' + escapeHtml(raw) + '">' + escapeHtml(label) + '</span>';
    }

    function bbToHtml(text) {
        const kept = [];
        const keep = (raw) => { kept.push(raw); return '\u0000' + (kept.length - 1) + '\u0000'; };
        const restore = (raw) => raw.replace(/\u0000(\d+)\u0000/g, (m, n) => restore(kept[n]));

        let s = text.replace(/\[(code|icode)\][\s\S]*?\[\/\1\]/gi, keep).replace(/\[attach\]\d+\[\/attach\]/gi, keep).replace(/\[media\][\s\S]*?\[\/media\]/gi, keep);
        for (let i = 0; i < 20; i++) {
            const before = s;
            s = s.replace(/\[(quote|spoiler|color|size|center|right)(=[^\]]*)?\]((?:(?!\[\1[=\]])[\s\S])*?)\[\/\1\]/gi, keep);
            if (s === before) break;
        }

        s = escapeHtml(s);
        [['b', 'b'], ['i', 'i'], ['u', 'u'], ['s', 's']].forEach(([bb, tag]) => {
            const pattern = new RegExp('\\[' + bb + '\\]([\\s\\S]*?)\\[\\/' + bb + '\\]', 'gi');
            for (let i = 0; i < 5; i++) s = s.replace(pattern, '<' + tag + '>$1</' + tag + '>');
        });
        s = s.replace(/\[url=(https?:\/\/[^\s"<>\[\]]+)\]([\s\S]*?)\[\/url\]/gi, '<a href="$1">$2</a>');
        s = s.replace(/\[url\](https?:\/\/[^\s"<>\[\]]+)\[\/url\]/gi, '<a href="$1">$1</a>');
        s = s.replace(/\[img\](https?:\/\/[^\s"<>\[\]]+)\[\/img\]/gi, '<img src="$1" alt="">');
        s = s.replace(/\[list(=1)?\]([\s\S]*?)\[\/list\]\n?/gi, (m, ordered, body) => {
            const items = body.split('[*]').map((item) => item.trim()).filter(Boolean);
            const tag = ordered ? 'ol' : 'ul';
            return '<' + tag + '>' + items.map((item) => '<li>' + item + '</li>').join('') + '</' + tag + '>';
        });
        s = s.replace(/\n/g, '<br>');

        return s.replace(/\u0000(\d+)\u0000/g, (m, n) => chipHtml(restore(kept[n])));
    }

    function htmlToBb(node) {
        let out = '';
        node.childNodes.forEach((child) => {
            if (child.nodeType === 3) { out += child.nodeValue.replace(/ /g, ' '); return; }
            if (child.nodeType !== 1) return;
            if (child.dataset && child.dataset.bb !== undefined) { out += child.dataset.bb; return; }

            const inner = () => htmlToBb(child);
            switch (child.tagName) {
                case 'BR': out += '\n'; break;
                case 'B': case 'STRONG': out += '[b]' + inner() + '[/b]'; break;
                case 'I': case 'EM': out += '[i]' + inner() + '[/i]'; break;
                case 'U': out += '[u]' + inner() + '[/u]'; break;
                case 'S': case 'STRIKE': case 'DEL': out += '[s]' + inner() + '[/s]'; break;
                case 'A': {
                    const href = child.getAttribute('href') || '';
                    const text = inner();
                    out += safeUrl.test(href) ? (text === href ? '[url]' + href + '[/url]' : '[url=' + href + ']' + text + '[/url]') : text;
                    break;
                }
                case 'IMG': {
                    const src = child.getAttribute('src') || '';
                    if (safeUrl.test(src)) out += '[img]' + src + '[/img]';
                    break;
                }
                case 'UL': case 'OL':
                    out += (out && !out.endsWith('\n') ? '\n' : '') + '[list' + (child.tagName === 'OL' ? '=1' : '') + ']\n'
                        + Array.from(child.children).filter((li) => li.tagName === 'LI').map((li) => '[*]' + htmlToBb(li).trim()).join('\n') + '\n[/list]\n';
                    break;
                case 'BLOCKQUOTE': out += (out && !out.endsWith('\n') ? '\n' : '') + '[quote]' + inner().trim() + '[/quote]\n'; break;
                case 'DIV': case 'P': {
                    const text = inner();
                    out += (out && !out.endsWith('\n') ? '\n' : '') + text + (text.endsWith('\n') ? '' : '\n');
                    break;
                }
                case 'SCRIPT': case 'STYLE': break;
                default: out += inner();
            }
        });
        return out;
    }

    const syncFromVisual = (editor) => {
        editor.querySelector('textarea').value = htmlToBb(editor.querySelector('.editor-visual')).replace(/\n{3,}/g, '\n\n').replace(/\s+$/, '');
    };

    // Insert BBCode at the cursor of whichever view is active.
    function wrap(textarea, open, close) {
        const editor = textarea.closest('.editor');
        if (editor && editor.dataset.mode === 'visual') {
            const visual = editor.querySelector('.editor-visual');
            visual.focus();
            const selected = String(window.getSelection());
            document.execCommand('insertHTML', false, bbToHtml(open + selected + close) + (close ? '' : ' '));
            syncFromVisual(editor);
            return;
        }
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const selected = textarea.value.slice(start, end);
        textarea.setRangeText(open + selected + close, start, end, 'end');
        if (!selected) textarea.selectionStart = textarea.selectionEnd = start + open.length;
        textarea.focus();
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // Replace the whole text (quotes, restored drafts) and refresh the visual view.
    function setEditorText(textarea, value) {
        textarea.value = value;
        const editor = textarea.closest('.editor');
        if (editor && editor.dataset.mode === 'visual') editor.querySelector('.editor-visual').innerHTML = bbToHtml(value);
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    const storage = {
        get(key) { try { return localStorage.getItem(key); } catch (e) { return null; } },
        set(key, value) { try { localStorage.setItem(key, value); } catch (e) { /* private mode */ } },
        remove(key) { try { localStorage.removeItem(key); } catch (e) { /* private mode */ } },
    };

    document.querySelectorAll('.editor').forEach((editor) => {
        const textarea = editor.querySelector('textarea');
        const visual = editor.querySelector('.editor-visual');
        const preview = editor.querySelector('.editor-preview');
        const form = editor.closest('form');
        editor.dataset.mode = 'code';

        const visualCommands = { b: 'bold', i: 'italic', u: 'underline', s: 'strikeThrough', list: 'insertUnorderedList', olist: 'insertOrderedList' };

        editor.querySelectorAll('[data-bb]').forEach((button) => {
            button.addEventListener('click', () => {
                const tag = button.dataset.bb;
                const visualMode = editor.dataset.mode === 'visual';

                if (visualMode && visualCommands[tag]) {
                    visual.focus();
                    document.execCommand(visualCommands[tag]);
                    syncFromVisual(editor);
                } else if (tag === 'url' || tag === 'img' || tag === 'media') {
                    askAddress(button, tag);
                } else if (tag === 'list' || tag === 'olist') {
                    wrap(textarea, (tag === 'olist' ? '[list=1]' : '[list]') + '\n[*]', '\n[/list]');
                } else {
                    wrap(textarea, '[' + tag + ']', '[/' + tag + ']');
                }
            });
        });

        // Size and colour menus: every entry wraps the selection in its own tag.
        editor.querySelectorAll('.editor-drop').forEach((drop) => {
            drop.querySelectorAll('[data-open]').forEach((button) => {
                button.addEventListener('click', () => {
                    wrap(textarea, button.dataset.open, button.dataset.close);
                    drop.open = false;
                });
            });
        });
        document.addEventListener('click', (event) => {
            editor.querySelectorAll('.editor-drop[open]').forEach((drop) => { if (!drop.contains(event.target)) drop.open = false; });
        });

        // Ctrl+B / I / U / K, in both views.
        const shortcut = (event) => {
            if (!(event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey) return;
            const button = editor.querySelector('[data-key="' + event.key.toLowerCase() + '"]');
            if (button) { event.preventDefault(); button.click(); }
        };
        textarea.addEventListener('keydown', shortcut);
        if (visual) visual.addEventListener('keydown', shortcut);

        // Character count.
        const counter = editor.querySelector('[data-editor-count]');
        if (counter) {
            const count = () => {
                counter.textContent = counter.dataset.label.replace(':count', new Intl.NumberFormat(root.lang || 'tr').format(textarea.value.length));
                // A limit (signatures have one): the count turns red at the edge.
                if (counter.dataset.max) counter.classList.toggle('over', textarea.value.length >= parseInt(counter.dataset.max, 10));
            };
            textarea.addEventListener('input', count);
            count();
        }

        // "Write" tab: back to the plain editor from the visual view or the preview.
        const writeTab = editor.querySelector('[data-write-tab]');
        if (writeTab) {
            const modes = Array.from(editor.querySelectorAll('.editor-mode'));
            const reflect = () => writeTab.classList.toggle('active', !modes.some((mode) => mode.classList.contains('active')));
            writeTab.addEventListener('click', () => { modes.filter((mode) => mode.classList.contains('active')).forEach((mode) => mode.click()); textarea.focus(); });
            modes.forEach((mode) => new MutationObserver(reflect).observe(mode, { attributes: true, attributeFilter: ['class'] }));
        }

        // Pop-overs open under the button that called them and never leave the editor's width.
        const placeUnder = (panel, button) => {
            const box = editor.getBoundingClientRect();
            const at = button.getBoundingClientRect();
            panel.style.top = (at.bottom - box.top + 6) + 'px';
            panel.style.left = Math.max(6, Math.min(at.left - box.left, box.width - panel.offsetWidth - 6)) + 'px';
        };

        // Link, image and video: a small form instead of the browser's own prompt.
        let pop = null;
        const closePop = () => { if (pop) { pop.remove(); pop = null; } };
        function askAddress(button, tag) {
            const reopen = pop && pop.dataset.tag === tag;
            closePop();
            if (reopen) return;

            const visualMode = editor.dataset.mode === 'visual';
            // The selection is remembered: focus moves into the form.
            const range = visualMode && window.getSelection().rangeCount ? window.getSelection().getRangeAt(0).cloneRange() : null;
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const selected = visualMode ? String(window.getSelection()) : textarea.value.slice(start, end);

            pop = document.createElement('form');
            pop.className = 'editor-pop';
            pop.dataset.tag = tag;
            pop.noValidate = true;
            const field = (label, value, placeholder) => {
                const wrapper = document.createElement('label');
                const caption = document.createElement('span');
                caption.textContent = label;
                const input = document.createElement('input');
                input.className = 'input';
                input.value = value;
                input.placeholder = placeholder || '';
                wrapper.append(caption, input);
                pop.appendChild(wrapper);
                return input;
            };
            const heading = document.createElement('b');
            heading.textContent = button.title.replace(/\s*\(.*\)$/, '');
            pop.appendChild(heading);
            // A selected address goes into the address field, selected words into the text field.
            const address = field(tag === 'media' ? editor.dataset.lMedia : editor.dataset.lAddress, safeUrl.test(selected.trim()) ? selected.trim() : '', 'https://');
            const text = tag === 'url' ? field(editor.dataset.lText, safeUrl.test(selected.trim()) ? '' : selected, '') : null;
            const error = document.createElement('div');
            error.className = 'error';
            error.hidden = true;
            error.textContent = editor.dataset.lInvalid;
            const actions = document.createElement('div');
            actions.className = 'editor-pop-actions';
            const add = document.createElement('button');
            add.type = 'submit';
            add.className = 'btn btn-primary btn-sm';
            add.textContent = editor.dataset.lAdd;
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'btn btn-sm';
            cancel.textContent = editor.dataset.lCancel;
            cancel.addEventListener('click', () => { closePop(); textarea.focus(); });
            actions.append(add, cancel);
            pop.append(error, actions);

            pop.addEventListener('keydown', (event) => { if (event.key === 'Escape') { event.preventDefault(); closePop(); textarea.focus(); } });
            pop.addEventListener('submit', (event) => {
                event.preventDefault();
                event.stopPropagation();
                const url = address.value.trim();
                if (!safeUrl.test(url)) { error.hidden = false; address.focus(); return; }
                const label = text && text.value.trim() ? text.value.trim() : url;
                closePop();

                if (visualMode) {
                    visual.focus();
                    if (range) { const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range); }
                    if (tag === 'media') {
                        document.execCommand('insertHTML', false, bbToHtml('[media]' + url + '[/media]') + ' ');
                    } else if (tag === 'img') {
                        document.execCommand('insertImage', false, url);
                    } else {
                        document.execCommand('insertHTML', false, '<a href="' + escapeHtml(url) + '">' + escapeHtml(label) + '</a>');
                    }
                    syncFromVisual(editor);
                    return;
                }

                const code = tag === 'url' ? '[url=' + url + ']' + label + '[/url]' : '[' + tag + ']' + url + '[/' + tag + ']';
                textarea.focus();
                textarea.setRangeText(code, start, end, 'end');
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
            });

            editor.appendChild(pop);
            placeUnder(pop, button);
            address.focus();
        }
        document.addEventListener('mousedown', (event) => {
            if (pop && !pop.contains(event.target) && !event.target.closest('[data-bb="url"], [data-bb="img"], [data-bb="media"]')) closePop();
        });

        // Emoji
        const emojiPanel = editor.querySelector('.emoji-panel');
        if (emojiPanel) {
            const emojiButton = editor.querySelector('[data-emoji-toggle]');
            emojiButton.addEventListener('click', () => {
                closePop();
                emojiPanel.hidden = !emojiPanel.hidden;
                if (!emojiPanel.hidden) placeUnder(emojiPanel, emojiButton);
            });
            document.addEventListener('mousedown', (event) => {
                if (!emojiPanel.hidden && !emojiPanel.contains(event.target) && !emojiButton.contains(event.target)) emojiPanel.hidden = true;
            });
            document.addEventListener('keydown', (event) => { if (event.key === 'Escape') emojiPanel.hidden = true; });
            emojiPanel.addEventListener('click', (event) => {
                if (event.target.tagName === 'BUTTON') wrap(textarea, event.target.textContent, '');
            });
        }

        // Visual mode
        const visualToggle = editor.querySelector('[data-visual-toggle]');
        const setMode = (mode) => {
            if (preview && !preview.hidden) togglePreview(false);
            if (mode === 'visual') {
                visual.innerHTML = bbToHtml(textarea.value);
            } else if (editor.dataset.mode === 'visual') {
                syncFromVisual(editor);
            }
            editor.dataset.mode = mode;
            visual.hidden = mode !== 'visual';
            textarea.hidden = mode === 'visual';
            textarea.required = mode !== 'visual';
            visualToggle.classList.toggle('active', mode === 'visual');
        };
        if (visual && visualToggle) {
            visualToggle.addEventListener('click', () => {
                const next = editor.dataset.mode === 'visual' ? 'code' : 'visual';
                setMode(next);
                storage.set('forum.editor', next);
            });
            visual.addEventListener('input', () => { syncFromVisual(editor); textarea.dispatchEvent(new Event('input', { bubbles: true })); });
            visual.addEventListener('paste', (event) => {
                const data = event.clipboardData;
                if (!data || data.files.length) return;
                event.preventDefault();
                document.execCommand('insertText', false, data.getData('text/plain'));
            });
            if (form) form.addEventListener('submit', () => { if (editor.dataset.mode === 'visual') syncFromVisual(editor); });
        }

        // Preview (rendered by the server, exactly as the post will look)
        const previewToggle = editor.querySelector('[data-preview-toggle]');
        async function togglePreview(show) {
            if (editor.dataset.mode === 'visual') syncFromVisual(editor);
            preview.hidden = !show;
            previewToggle.classList.toggle('active', show);
            textarea.hidden = show || editor.dataset.mode === 'visual';
            if (visual) visual.hidden = show || editor.dataset.mode !== 'visual';
            if (!show) return;
            preview.textContent = '…';
            try {
                const body = new FormData();
                body.append('body', textarea.value);
                const response = await fetch(previewToggle.dataset.url, {
                    method: 'POST', body, credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') || {}).content || '', Accept: 'application/json' },
                });
                const data = await response.json();
                // Markup produced by the server-side renderer, which escapes everything first.
                preview.innerHTML = data.html || '';
            } catch (error) {
                preview.textContent = previewToggle.dataset.failed || '';
            }
        }
        if (preview && previewToggle) previewToggle.addEventListener('click', () => togglePreview(preview.hidden));

        // Drafts: what is typed survives a closed tab; cleared when the form is sent.
        const draftKey = form && editor.dataset.draft ? 'forum.draft:' + editor.dataset.draft : null;
        const draftNote = editor.querySelector('[data-draft-note]');
        if (draftKey) {
            const saved = storage.get(draftKey);
            // A forum's starting text is not the member's writing: a draft goes on top of it, and it is not kept as one.
            const given = editor.hasAttribute('data-prefilled') ? textarea.value : null;
            if (saved && (!textarea.value.trim() || given !== null)) {
                textarea.value = saved;
                if (draftNote) draftNote.hidden = false;
            }
            let timer = null;
            textarea.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(() => (textarea.value.trim() && textarea.value !== given ? storage.set(draftKey, textarea.value) : storage.remove(draftKey)), 600);
            });
            form.addEventListener('submit', () => storage.remove(draftKey));
            if (draftNote) {
                draftNote.querySelector('button').addEventListener('click', () => {
                    storage.remove(draftKey);
                    setEditorText(textarea, '');
                    draftNote.hidden = true;
                });
            }
        }

        if (visual && visualToggle && storage.get('forum.editor') === 'visual') setMode('visual');
    });

    // ---- Quote a post into the reply editor; "+ quote" collects several first ------------
    const replyArea = () => document.querySelector('#reply textarea');
    async function fetchQuote(url) {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        return response.ok ? (await response.json()).bbcode : '';
    }
    function appendToReply(text) {
        const textarea = replyArea();
        if (!textarea || !text) return;
        setEditorText(textarea, textarea.value + (textarea.value && !textarea.value.endsWith('\n') ? '\n' : '') + text);
        textarea.closest('.editor').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // "Reply" under a post: the post comes into the editor as a quote and the cursor waits after it.
    document.addEventListener('click', async (event) => {
        const button = event.target.closest && event.target.closest('[data-reply]');
        const textarea = button && replyArea();
        if (!textarea) return;
        const editor = textarea.closest('.editor');
        let quote = '';
        try { quote = await fetchQuote(button.dataset.reply); } catch (error) { /* network problem: the editor still opens, without the quote */ }
        if (quote && !textarea.value.includes(quote.trim())) {
            setEditorText(textarea, textarea.value + (textarea.value && !textarea.value.endsWith('\n') ? '\n' : '') + quote + (quote.endsWith('\n') ? '' : '\n'));
        }
        editor.scrollIntoView({ behavior: 'smooth', block: 'center' });
        const visual = editor.dataset.mode === 'visual' ? editor.querySelector('.editor-visual') : null;
        (visual || textarea).focus({ preventScroll: true });
        if (visual) {
            const range = document.createRange();
            range.selectNodeContents(visual);
            range.collapse(false);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        } else {
            textarea.setSelectionRange(textarea.value.length, textarea.value.length);
            textarea.scrollTop = textarea.scrollHeight;
        }
    });

    // Bound to the document: posts that are added to the page later work like the ones that were there.
    document.addEventListener('click', async (event) => {
        const button = event.target.closest && event.target.closest('[data-quote]');
        if (!button) return;
        try { appendToReply(await fetchQuote(button.dataset.quote)); } catch (error) { /* network problem: leave the editor untouched */ }
    });

    const quoteKey = 'forum.quotes:' + location.pathname.replace(/\/unread$/, '');
    const quoteInsert = document.querySelector('[data-multiquote-insert]');
    const readQuotes = () => { try { return JSON.parse(storage.get(quoteKey) || '[]'); } catch (e) { return []; } };
    const refreshQuotes = () => {
        const list = readQuotes();
        document.querySelectorAll('[data-multiquote]').forEach((button) => button.classList.toggle('active', list.includes(button.dataset.multiquote)));
        if (quoteInsert) {
            quoteInsert.hidden = list.length === 0;
            const count = quoteInsert.querySelector('[data-count]');
            if (count) count.textContent = list.length;
        }
    };
    document.addEventListener('click', (event) => {
        const button = event.target.closest && event.target.closest('[data-multiquote]');
        if (!button) return;
        const list = readQuotes();
        const at = list.indexOf(button.dataset.multiquote);
        at === -1 ? list.push(button.dataset.multiquote) : list.splice(at, 1);
        storage.set(quoteKey, JSON.stringify(list));
        refreshQuotes();
    });
    if (quoteInsert) {
        quoteInsert.addEventListener('click', async () => {
            for (const url of readQuotes()) {
                try { appendToReply(await fetchQuote(url)); } catch (error) { /* skip this one */ }
            }
            storage.remove(quoteKey);
            refreshQuotes();
        });
    }
    refreshQuotes();

    // ---- Notices can be hidden; the choice is remembered until the notice changes ---------
    document.querySelectorAll('[data-notice]').forEach((notice) => {
        const key = 'forum.notice:' + notice.dataset.notice;
        if (storage.get(key)) notice.hidden = true;
        const close = notice.querySelector('[data-notice-close]');
        if (close) close.addEventListener('click', () => { notice.hidden = true; storage.set(key, '1'); });
    });

    // ---- Embedded posts from X and Instagram need the provider's script ------------------
    const embedScripts = { x: 'https://platform.twitter.com/widgets.js', instagram: 'https://www.instagram.com/embed.js' };
    Object.keys(embedScripts).forEach((provider) => {
        if (document.querySelector('[data-embed="' + provider + '"]')) {
            const script = document.createElement('script');
            script.async = true;
            script.src = embedScripts[provider];
            document.body.appendChild(script);
        }
    });

    // ---- Dialogs (report a post) ----------------------------------------------------
    document.addEventListener('click', (event) => {
        const button = event.target.closest && event.target.closest('[data-dialog]');
        if (button) {
            const dialog = document.getElementById(button.dataset.dialog);
            if (!dialog) return;
            if (button.dataset.action) dialog.querySelector('form').action = button.dataset.action;
            dialog.showModal();
        }
        const close = event.target.closest && event.target.closest('[data-dialog-close]');
        if (close) close.closest('dialog').close();
    });

    // ---- Tabbed panels (latest threads on the board index) ------------------------------
    document.querySelectorAll('[data-tabs]').forEach((panel) => {
        panel.querySelectorAll('[data-tab]').forEach((tab) => {
            tab.addEventListener('click', () => {
                panel.querySelectorAll('[data-tab]').forEach((other) => other.classList.toggle('active', other === tab));
                panel.querySelectorAll('[data-pane]').forEach((pane) => pane.classList.toggle('hidden', pane.dataset.pane !== tab.dataset.tab));
            });
        });
    });

    // ---- Appearance page: presets and live preview -----------------------------------
    document.querySelectorAll('[data-theme-form]').forEach((form) => {
        const preview = form.querySelector('[data-preview]');
        const inputs = Array.from(form.querySelectorAll('[data-color]'));
        const presets = Array.from(form.querySelectorAll('[data-preset]'));

        // White or near-black, whichever is readable on the colour (same rule as the server).
        const contrast = (hex) => {
            const [r, g, b] = [1, 3, 5].map((i) => {
                const v = parseInt(hex.substr(i, 2), 16) / 255;
                return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
            });
            return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.42 ? '#1f2733' : '#ffffff';
        };

        const refresh = () => {
            const current = {};
            inputs.forEach((input) => {
                const key = input.dataset.color;
                current[key] = input.value.toLowerCase();
                const label = form.querySelector('[data-hex="' + key + '"]');
                if (label) label.textContent = input.value;
                if (preview) {
                    preview.style.setProperty('--p-' + key, input.value);
                    preview.style.setProperty('--p-on-' + key, contrast(input.value));
                }
            });
            presets.forEach((button) => {
                const preset = JSON.parse(button.dataset.preset);
                button.classList.toggle('active', Object.keys(preset).every((key) => preset[key].toLowerCase() === current[key]));
            });
        };

        inputs.forEach((input) => input.addEventListener('input', refresh));
        presets.forEach((button) => {
            button.addEventListener('click', () => {
                const preset = JSON.parse(button.dataset.preset);
                inputs.forEach((input) => { if (preset[input.dataset.color]) input.value = preset[input.dataset.color]; });
                refresh();
            });
        });
        refresh();
    });

    // ---- Permission selects get a colour for their value ------------------------------
    document.querySelectorAll('.perm-select').forEach((select) => {
        const paint = () => { select.classList.remove('allow', 'never'); if (select.value) select.classList.add(select.value); };
        select.addEventListener('change', paint);
        paint();
    });

    // ---- Install as an app: the service worker shows an offline page and notifications ------
    const workerUrl = (document.querySelector('meta[name="service-worker"]') || {}).content;
    const canWork = 'serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1');
    if (workerUrl && canWork) {
        navigator.serviceWorker.register(workerUrl).catch(() => {});
    }

    // ---- Browser notifications (account settings) ----------------------------------------------
    const pushButton = document.querySelector('[data-push]');
    if (pushButton) {
        const state = document.querySelector('[data-push-state]');
        const text = document.querySelector('[data-push-text]');
        const supported = workerUrl && canWork && 'PushManager' in window && 'Notification' in window;

        const show = (on) => {
            pushButton.disabled = false;
            pushButton.textContent = on ? pushButton.dataset.labelOff : pushButton.dataset.labelOn;
            pushButton.dataset.on = on ? '1' : '';
            if (state) { state.textContent = on ? state.dataset.on : state.dataset.off; state.classList.toggle('green', on); }
        };
        const keyBytes = (value) => {
            const raw = atob((value + '='.repeat((4 - (value.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
            return Uint8Array.from(raw, (char) => char.charCodeAt(0));
        };
        const tell = (method, endpoint) => fetch(pushButton.dataset.url, {
            method,
            credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ endpoint }),
        });

        if (!supported) {
            if (text) text.textContent = text.dataset.unsupported;
        } else {
            navigator.serviceWorker.ready.then((worker) => worker.pushManager.getSubscription()).then((subscription) => show(!!subscription)).catch(() => {});

            pushButton.addEventListener('click', () => {
                pushButton.disabled = true;
                navigator.serviceWorker.ready.then((worker) => worker.pushManager.getSubscription().then((subscription) => {
                    if (pushButton.dataset.on) {
                        return (subscription ? tell('DELETE', subscription.endpoint).then(() => subscription.unsubscribe()) : Promise.resolve()).then(() => show(false));
                    }
                    return Notification.requestPermission().then((permission) => {
                        if (permission !== 'granted') {
                            if (text && permission === 'denied') text.textContent = text.dataset.denied;
                            return show(false);
                        }
                        return worker.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(pushButton.dataset.push) })
                            .then((created) => tell('POST', created.endpoint).then((response) => {
                                if (!response.ok) return created.unsubscribe().then(() => show(false));
                                return show(true);
                            }));
                    });
                })).catch(() => show(!!pushButton.dataset.on));
            });
        }
    }

    // ---- Import from another forum (admin): the page asks for one batch after another ----------
    const importSource = document.querySelector('[data-import-source]');
    if (importSource) {
        const prefix = document.querySelector('[data-import-prefix]');
        importSource.addEventListener('change', () => { prefix.value = importSource.selectedOptions[0].dataset.prefix || ''; });
    }

    const importBox = document.querySelector('[data-import]');
    if (importBox) {
        const part = (name) => importBox.querySelector('[data-import-' + name + ']');
        const format = new Intl.NumberFormat(root.lang || 'tr');
        let failures = 0;

        const step = () => fetch(importBox.dataset.import, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' } })
            .then((response) => { if (!response.ok) throw new Error('HTTP ' + response.status); return response.json(); })
            .then((run) => {
                failures = 0;
                part('bar').style.width = run.percent + '%';
                part('percent').textContent = run.percent + '%';
                part('step').textContent = run.step;
                Object.keys(run.done || {}).forEach((key) => {
                    const cell = importBox.querySelector('[data-import-count="' + key + '"]');
                    if (cell) cell.textContent = format.format(run.done[key]);
                });
                if (run.status === 'running') return step();
                location.reload();
            })
            .catch((error) => {
                // A slow or interrupted request is tried again a few times before giving up.
                if (++failures < 5) return new Promise((resolve) => setTimeout(resolve, 4000)).then(step);
                part('error').hidden = false;
                part('error').textContent = String(error.message || error);
            });

        step();
    }

    // ---- Shoutbox -------------------------------------------------------------------------------
    const shoutbox = document.querySelector('[data-shoutbox]');
    if (shoutbox) {
        const list = shoutbox.querySelector('[data-shout-list]');
        const form = shoutbox.querySelector('[data-shout-form]');
        let last = parseInt(shoutbox.dataset.last, 10) || 0;
        let busy = false;
        list.scrollTop = list.scrollHeight;

        const refresh = () => {
            if (busy || document.hidden) return Promise.resolve();
            busy = true;
            return fetch(shoutbox.dataset.shoutbox + '?after=' + last, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : null))
                .then((data) => {
                    if (!data || data.last <= last) return;
                    const atBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 40;
                    const empty = list.querySelector('[data-shout-empty]');
                    if (empty) empty.remove();
                    list.insertAdjacentHTML('beforeend', data.html);
                    last = data.last;
                    while (list.children.length > 60) list.firstElementChild.remove();
                    if (atBottom) list.scrollTop = list.scrollHeight;
                })
                .catch(() => {})
                .then(() => { busy = false; });
        };
        setInterval(refresh, 6000);
        document.addEventListener('visibilitychange', refresh);

        if (form) {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                const input = form.querySelector('input[name="body"]');
                const body = input.value.trim();
                if (!body) return;
                input.value = '';
                fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ body }),
                }).then((response) => {
                    if (!response.ok) input.value = body;
                    list.scrollTop = list.scrollHeight;
                    return refresh();
                }).catch(() => { input.value = body; });
            });
        }

        list.addEventListener('click', (event) => {
            const button = event.target.closest('[data-shout-delete]');
            if (!button) return;
            fetch(button.dataset.shoutDelete, { method: 'DELETE', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' } })
                .then((response) => { if (response.ok) button.closest('.shout').remove(); })
                .catch(() => {});
        });
    }

    // ---- Live alerts: header counters, tab title and a small notice for a new alert -------------
    const liveMeta = document.querySelector('meta[name="live-alerts"]');
    if (liveMeta) {
        const baseTitle = document.title;
        const counter = (name) => document.querySelector('[data-live="' + name + '"]');
        let seen = null;

        const setCount = (name, value) => {
            const element = counter(name);
            if (!element) return;
            const changed = element.textContent.trim() !== String(value);
            // The same number is shown in the header and in the bar at the bottom of a phone screen.
            document.querySelectorAll('[data-live="' + name + '"]').forEach((other) => { other.textContent = value; other.hidden = value < 1; });
            // An open pop-over shows what just arrived.
            const pop = element.closest('details[data-pop]');
            if (changed && pop && pop.reloadPop) pop.reloadPop();
        };
        const toast = (alert) => {
            const old = document.querySelector('.live-toast');
            if (old) old.remove();
            const link = document.createElement('a');
            link.className = 'live-toast';
            link.href = alert.url;
            link.textContent = alert.text;
            document.body.appendChild(link);
            setTimeout(() => link.remove(), 9000);
        };
        const check = () => {
            if (document.hidden) return;
            fetch(liveMeta.content, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : null))
                .then((data) => {
                    if (!data) return;
                    setCount('alerts', data.alerts);
                    setCount('conversations', data.conversations);
                    const total = data.alerts + data.conversations;
                    document.title = (total > 0 ? '(' + total + ') ' : '') + baseTitle;
                    const latest = data.latest ? data.latest.id : '';
                    if (seen !== null && latest && latest !== seen) toast(data.latest);
                    seen = latest;
                })
                .catch(() => {});
        };
        setInterval(check, Math.max(10, parseInt(liveMeta.dataset.seconds, 10) || 30) * 1000);
        document.addEventListener('visibilitychange', check);
        check();
    }

    // ---- New thread: existing threads with a similar title ---------------------------------------
    const similarInput = document.querySelector('[data-similar]');
    const similarBox = document.querySelector('[data-similar-box]');
    if (similarInput && similarBox) {
        let timer = null;
        let asked = '';
        const look = () => {
            const title = similarInput.value.trim();
            if (title === asked) return;
            asked = title;
            if (title.length < 8) { similarBox.hidden = true; return; }
            fetch(similarInput.dataset.similar + '?title=' + encodeURIComponent(title), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : { threads: [] }))
                .then((data) => {
                    const target = similarBox.querySelector('[data-similar-list]');
                    target.textContent = '';
                    (data.threads || []).forEach((thread) => {
                        const link = document.createElement('a');
                        link.href = thread.url;
                        link.target = '_blank';
                        link.rel = 'noopener';
                        link.textContent = thread.title;
                        const meta = document.createElement('span');
                        meta.className = 'muted small';
                        meta.textContent = ' · ' + (thread.forum || '');
                        const row = document.createElement('div');
                        row.append(link, meta);
                        target.appendChild(row);
                    });
                    similarBox.hidden = !(data.threads || []).length;
                })
                .catch(() => {});
        };
        similarInput.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(look, 600); });
    }

    // ---- Forms that take a while say so ------------------------------------------------------------
    document.querySelectorAll('form[data-busy]').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');
            if (button) { button.textContent = form.dataset.busy; setTimeout(() => { button.disabled = true; }, 0); }
        });
    });

    // ---- Admin panel: menu button on small screens ---------------------------------------------
    const adminMenu = document.querySelector('[data-admin-menu]');
    const adminSide = document.querySelector('.admin-side');
    if (adminMenu && adminSide) {
        const setMenu = (open) => {
            adminSide.classList.toggle('open', open);
            adminMenu.setAttribute('aria-expanded', open ? 'true' : 'false');
        };
        adminMenu.addEventListener('click', (event) => { event.stopPropagation(); setMenu(!adminSide.classList.contains('open')); });
        document.addEventListener('click', (event) => {
            if (adminSide.classList.contains('open') && !event.target.closest('.admin-side')) setMenu(false);
        });
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape') setMenu(false); });
    }

    // ---- Admin panel: the groups of the side menu stay the way the administrator left them --------
    const adminGroups = document.querySelectorAll('[data-acp-group]');
    if (adminGroups.length) {
        const read = () => { try { return JSON.parse(localStorage.getItem('acp-groups') || '[]'); } catch (error) { return []; } };
        const kept = read();
        adminGroups.forEach((group) => {
            if (kept.includes(group.dataset.acpGroup)) group.open = true;
            group.addEventListener('toggle', () => {
                const open = Array.from(adminGroups).filter((item) => item.open && !item.hasAttribute('data-here')).map((item) => item.dataset.acpGroup);
                try { localStorage.setItem('acp-groups', JSON.stringify(open)); } catch (error) { /* private mode: nothing is kept */ }
            });
        });
    }

    // ---- Admin panel: the list of sections beside a long form marks where the page is -------------
    const sectionLinks = document.querySelectorAll('[data-acp-sections] a[href^="#"]');
    if (sectionLinks.length && 'IntersectionObserver' in window) {
        const byId = {};
        sectionLinks.forEach((link) => { byId[link.getAttribute('href').slice(1)] = link; });
        const mark = (id) => sectionLinks.forEach((link) => link.classList.toggle('active', link === byId[id]));
        const seen = new IntersectionObserver((entries) => {
            entries.forEach((entry) => { if (entry.isIntersecting) mark(entry.target.id); });
        }, { rootMargin: '-80px 0px -70% 0px' });
        Object.keys(byId).forEach((id) => { const target = document.getElementById(id); if (target) seen.observe(target); });
    }

    // ---- Category bars: a click anywhere on the bar opens or closes it ---------------------------
    document.querySelectorAll('[data-catbar]').forEach((bar) => {
        bar.addEventListener('click', (event) => {
            if (event.target.closest('a, button')) return;
            const toggle = bar.querySelector('[data-collapse]');
            if (toggle) toggle.click();
        });
    });

    // ---- Search box: live suggestions while typing ------------------------------------------------
    document.querySelectorAll('input[data-suggest]').forEach((input) => {
        const host = input.closest('form');
        const panel = document.createElement('div');
        panel.className = 'search-suggest';
        panel.hidden = true;
        host.appendChild(panel);

        let timer = null;
        let serial = 0;
        let shownFor = '';
        const items = () => Array.from(panel.querySelectorAll('a'));
        const close = () => { panel.hidden = true; };

        const group = (label, rows) => {
            if (!rows.length) return;
            const title = document.createElement('div');
            title.className = 'suggest-label';
            title.textContent = label;
            panel.appendChild(title);
            rows.forEach((row) => {
                const link = document.createElement('a');
                link.href = row.url;
                const name = document.createElement('b');
                name.textContent = row.title;
                const meta = document.createElement('span');
                meta.textContent = row.meta || '';
                link.append(name, meta);
                panel.appendChild(link);
            });
        };

        const render = (query, data) => {
            panel.textContent = '';
            group(input.dataset.labelThreads, data.threads || []);
            group(input.dataset.labelMembers, data.members || []);
            if (!panel.children.length) {
                const empty = document.createElement('div');
                empty.className = 'suggest-empty';
                empty.textContent = input.dataset.labelEmpty;
                panel.appendChild(empty);
            }
            if (data.all) {
                const all = document.createElement('a');
                all.className = 'suggest-all';
                all.href = data.all;
                all.textContent = input.dataset.labelAll.replace(':query', query);
                panel.appendChild(all);
            }
            shownFor = query;
            panel.hidden = false;
        };

        const look = () => {
            const query = input.value.trim();
            if (query.length < 2) { shownFor = ''; close(); return; }
            if (query === shownFor) { panel.hidden = false; return; }
            const mine = ++serial;
            fetch(input.dataset.suggest + '?q=' + encodeURIComponent(query), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : null))
                // Only the answer to the latest keystroke is shown.
                .then((data) => { if (data && mine === serial && document.activeElement === input) render(query, data); })
                .catch(() => {});
        };

        input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(look, 220); });
        input.addEventListener('focus', look);
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { close(); return; }
            if (panel.hidden || (event.key !== 'ArrowDown' && event.key !== 'ArrowUp' && event.key !== 'Enter')) return;
            const list = items();
            const current = list.findIndex((link) => link.classList.contains('active'));
            if (event.key === 'Enter') {
                if (current >= 0) { event.preventDefault(); location.href = list[current].href; }
                return;
            }
            event.preventDefault();
            if (!list.length) return;
            const next = event.key === 'ArrowDown' ? (current + 1) % list.length : (current <= 0 ? list.length - 1 : current - 1);
            list.forEach((link, index) => link.classList.toggle('active', index === next));
            list[next].scrollIntoView({ block: 'nearest' });
        });
        document.addEventListener('click', (event) => { if (!host.contains(event.target)) close(); });
    });

    // ---- Welcome area: a visitor who closed it does not see it again in this browser ---------------
    const welcome = document.querySelector('[data-welcome]');
    if (welcome) {
        let hidden = false;
        try { hidden = localStorage.getItem('forum.welcome') === 'hidden'; } catch (e) { /* private mode */ }
        if (hidden) welcome.hidden = true;
        welcome.querySelector('[data-welcome-close]').addEventListener('click', () => {
            welcome.hidden = true;
            try { localStorage.setItem('forum.welcome', 'hidden'); } catch (e) { /* private mode */ }
        });
    }

    // ---- Panels that rest under a link while the pointer is on it: member card, thread preview ----
    if (window.matchMedia && window.matchMedia('(hover: hover)').matches) {
        const pops = [];
        const hoverPop = (selector, className, urlOf, skip) => {
            const cache = {};
            let card = null;
            let openTimer = null;
            let closeTimer = null;
            let current = null;

            const hide = () => { clearTimeout(openTimer); if (card) card.hidden = true; current = null; };
            const place = (link) => {
                const at = link.getBoundingClientRect();
                card.hidden = false;
                const width = card.offsetWidth;
                const height = card.offsetHeight;
                const left = Math.max(8, Math.min(at.left, window.innerWidth - width - 8));
                // Under the link, or above it when there is no room below.
                const top = at.bottom + height + 10 > window.innerHeight && at.top - height - 8 > 0 ? at.top - height - 8 : at.bottom + 8;
                card.style.left = left + 'px';
                card.style.top = top + 'px';
            };
            const show = (link) => {
                const url = urlOf(link);
                if (!url) return;
                current = link;
                const render = (html) => {
                    if (current !== link || !html) return;
                    pops.forEach((other) => { if (other !== hide) other(); });
                    current = link;
                    if (!card) {
                        card = document.createElement('div');
                        card.className = 'hovercard-pop ' + className;
                        card.addEventListener('mouseenter', () => clearTimeout(closeTimer));
                        card.addEventListener('mouseleave', () => { closeTimer = setTimeout(hide, 200); });
                        document.body.appendChild(card);
                    }
                    card.innerHTML = html; // markup rendered by the server
                    place(link);
                };
                if (cache[url] !== undefined) { render(cache[url]); return; }
                fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then((response) => (response.ok ? response.text() : ''))
                    .then((html) => { cache[url] = html; render(html); })
                    .catch(() => {});
            };

            document.addEventListener('mouseover', (event) => {
                const link = event.target.closest && event.target.closest(selector);
                if (!link || link.closest(skip)) return;
                clearTimeout(closeTimer);
                clearTimeout(openTimer);
                openTimer = setTimeout(() => show(link), 380);
            });
            document.addEventListener('mouseout', (event) => {
                const link = event.target.closest && event.target.closest(selector);
                if (!link) return;
                clearTimeout(openTimer);
                closeTimer = setTimeout(hide, 250);
            });
            window.addEventListener('scroll', hide, { passive: true });
            document.addEventListener('keydown', (event) => { if (event.key === 'Escape') hide(); });
            pops.push(hide);
        };

        hoverPop('a.username[href]', 'member-pop', (link) => link.href.split('#')[0].split('?')[0] + '/card',
            '.hovercard-pop, .header-user, .menu-panel, .pop-panel');

        // The start of a thread, under its title in the lists.
        if (document.documentElement.hasAttribute('data-thread-preview')) {
            hoverPop('a.thread-title[href], .latest-row a.t[href], a[data-preview][href]', 'thread-pop', (link) => {
                const found = link.href.split('#')[0].split('?')[0].match(/^(.*\/threads\/[^/]+)(?:\/unread)?$/);
                return found ? found[1] + '/preview' : null;
            }, '.hovercard-pop, .menu-panel, .pop-panel');
        }
    }

    // ---- Header pop-overs: alerts and conversations -------------------------------------------
    document.querySelectorAll('details[data-pop]').forEach((pop) => {
        const list = pop.querySelector('[data-pop-list]');
        const panel = pop.querySelector('.pop-panel');
        let loadedAt = 0;

        const load = () => {
            loadedAt = Date.now();
            fetch(pop.dataset.pop, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then((response) => (response.ok ? response.text() : null))
                .then((html) => { if (html !== null) list.innerHTML = html; }) // markup rendered by the server
                .catch(() => {});
        };
        pop.reloadPop = () => { loadedAt = 0; if (pop.open) load(); };

        pop.addEventListener('toggle', () => {
            if (!pop.open) return;
            document.querySelectorAll('details[data-pop][open]').forEach((other) => { if (other !== pop) other.removeAttribute('open'); });
            // On a phone the panel spans the screen, just under the button.
            panel.style.top = window.matchMedia('(max-width: 720px)').matches ? Math.round(pop.getBoundingClientRect().bottom + 8) + 'px' : '';
            if (Date.now() - loadedAt > 10000) load();
        });

        const read = pop.querySelector('[data-pop-read]');
        if (read) {
            read.addEventListener('click', () => {
                fetch(read.dataset.popRead, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' } })
                    .then((response) => {
                        if (!response.ok) return;
                        list.querySelectorAll('.unread').forEach((item) => item.classList.remove('unread'));
                        const count = pop.querySelector('.count');
                        if (count) { count.textContent = '0'; count.hidden = true; }
                    })
                    .catch(() => {});
            });
        }
    });

    // ---- Footer: back to the top ---------------------------------------------------------------
    document.querySelectorAll('[data-to-top]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        });
    });

    // ---- Thread page: reply without a reload, and new posts as they arrive -----------------------
    const postsBox = document.querySelector('[data-thread-posts]');
    if (postsBox && window.DOMParser) {
        const postList = postsBox.querySelector('[data-post-list]');
        const postPager = postsBox.querySelector('[data-post-pager]');
        const newBar = postsBox.querySelector('[data-new-posts]');
        const lastPostId = () => Math.max(0, ...Array.from(postList.querySelectorAll('article.post')).map((article) => parseInt(article.id.replace('post-', ''), 10) || 0));
        const pageOf = (url) => { const parsed = new URL(url, location.href); return parsed.pathname + '|' + (parsed.searchParams.get('page') || '1'); };
        const onThisPage = (url) => pageOf(url) === pageOf(location.href);

        // Takes over the posts of a fresh copy of this page that are not on the screen yet.
        const takeNewPosts = async (url) => {
            const response = await fetch(url, { credentials: 'same-origin' });
            if (!response.ok) return [];
            const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');
            const freshList = fresh.querySelector('[data-post-list]');
            if (!freshList) return [];
            const added = [];
            freshList.querySelectorAll('article.post').forEach((article) => {
                if (!document.getElementById(article.id)) added.push(postList.appendChild(document.importNode(article, true)));
            });
            const freshPager = fresh.querySelector('[data-post-pager]');
            if (postPager && freshPager) postPager.innerHTML = freshPager.innerHTML; // markup rendered by the server
            added.forEach((article) => article.classList.add('just-added'));
            refreshQuotes();
            if (window.twttr && window.twttr.widgets) window.twttr.widgets.load(postList);
            return added;
        };

        // New replies from others: a bar offers them instead of moving the page under the reader.
        let waitingUrl = null;
        const hideBar = () => { if (newBar) newBar.hidden = true; waitingUrl = null; };
        if (newBar && postsBox.dataset.since) {
            const look = () => {
                if (document.hidden || waitingUrl) return;
                fetch(postsBox.dataset.since + '/' + lastPostId(), { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                    .then((response) => (response.ok ? response.json() : null))
                    .then((data) => {
                        if (!data || !data.count || !data.url) return;
                        waitingUrl = data.url;
                        newBar.textContent = data.count === 1 ? newBar.dataset.one : newBar.dataset.many.replace(':count', data.count);
                        newBar.hidden = false;
                    })
                    .catch(() => {});
            };
            setInterval(look, 30000);
            document.addEventListener('visibilitychange', look);
            newBar.addEventListener('click', async () => {
                const url = waitingUrl;
                if (!url) return;
                if (!onThisPage(url)) { location.href = url; return; }
                try {
                    const added = await takeNewPosts(url);
                    hideBar();
                    if (added[0]) added[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
                } catch (error) { location.href = url; }
            });
        }

        // The reply form posts in the background; the new post appears in place.
        const replyForm = document.querySelector('form[data-live-reply]');
        if (replyForm) {
            const submitButton = replyForm.querySelector('button[type="submit"]');
            const problem = document.createElement('div');
            problem.className = 'flash error';
            problem.hidden = true;
            replyForm.insertBefore(problem, replyForm.querySelector('.editor'));

            replyForm.addEventListener('submit', async (event) => {
                if (event.defaultPrevented) return;
                event.preventDefault();
                const textarea = replyForm.querySelector('textarea');
                problem.hidden = true;
                submitButton.disabled = true;
                let data = null;
                try {
                    const response = await fetch(replyForm.action, {
                        method: 'POST', body: new FormData(replyForm), credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    data = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        const errors = data.errors ? Object.values(data.errors).flat() : [];
                        throw new Error(errors[0] || data.message || replyForm.dataset.failed);
                    }
                } catch (error) {
                    problem.textContent = error.message || replyForm.dataset.failed;
                    problem.hidden = false;
                    submitButton.disabled = false;
                    textarea.dispatchEvent(new Event('input', { bubbles: true })); // the draft is kept
                    return;
                }

                // Another page of the thread, a post waiting for approval, or anything unexpected: the usual way.
                if (!data.visible || !data.url || !onThisPage(data.url)) { location.href = (data.url || location.href) + (data.id ? '#post-' + data.id : ''); return; }
                try {
                    await takeNewPosts(data.url);
                    hideBar();
                } catch (error) { location.href = data.url + '#post-' + data.id; return; }

                setEditorText(textarea, '');
                replyForm.querySelectorAll('[data-files] li').forEach((item) => item.remove());
                submitButton.disabled = false;
                const mine = document.getElementById('post-' + data.id);
                if (mine) mine.scrollIntoView({ behavior: 'smooth', block: 'center' });
                else location.href = data.url + '#post-' + data.id;
            });
        }
    }

    // ---- Editor: "@" lists members while a name is being typed ------------------------------------
    document.querySelectorAll('.editor[data-mentions]').forEach((editor) => {
        const textarea = editor.querySelector('textarea');
        const list = editor.querySelector('.mention-list');
        if (!textarea || !list) return;
        let items = [];
        let active = 0;
        let start = -1;
        let timer = null;
        let asked = '';

        const close = () => { list.hidden = true; items = []; start = -1; };
        const typed = () => {
            const before = textarea.value.slice(0, textarea.selectionStart);
            const match = before.match(/(^|[\s(\[>])@([\p{L}\p{N}_-]{2,30})$/u);
            return match ? { term: match[2], at: before.length - match[2].length - 1 } : null;
        };
        const draw = () => {
            list.textContent = '';
            items.forEach((member, index) => {
                const row = document.createElement('button');
                row.type = 'button';
                row.className = index === active ? 'active' : '';
                const avatar = document.createElement('span');
                avatar.className = 'avatar xs';
                avatar.style.setProperty('--hue', member.hue);
                if (member.avatar) { const image = document.createElement('img'); image.src = member.avatar; image.alt = ''; avatar.appendChild(image); } else avatar.textContent = member.initial;
                const name = document.createElement('b');
                name.textContent = member.name;
                const title = document.createElement('span');
                title.textContent = member.title;
                row.append(avatar, name, title);
                row.addEventListener('mousedown', (event) => { event.preventDefault(); choose(index); });
                list.appendChild(row);
            });
            list.hidden = items.length === 0;
            // Under the text area's first line of sight: its lower left corner inside the editor.
            list.style.top = (textarea.offsetTop + Math.min(textarea.offsetHeight, 150)) + 'px';
        };
        const choose = (index) => {
            const member = items[index];
            if (!member || start < 0) return;
            const end = textarea.selectionStart;
            textarea.value = textarea.value.slice(0, start) + '@' + member.name + ' ' + textarea.value.slice(end);
            const caret = start + member.name.length + 2;
            textarea.setSelectionRange(caret, caret);
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            close();
            textarea.focus();
        };

        textarea.addEventListener('input', () => {
            const found = typed();
            clearTimeout(timer);
            if (!found) { close(); return; }
            start = found.at;
            timer = setTimeout(() => {
                asked = found.term;
                fetch(editor.dataset.mentions + '?q=' + encodeURIComponent(found.term), { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                    .then((response) => (response.ok ? response.json() : null))
                    .then((data) => {
                        if (!data || asked !== found.term || !typed()) return;
                        items = data.members || [];
                        active = 0;
                        draw();
                    })
                    .catch(() => {});
            }, 180);
        });
        textarea.addEventListener('keydown', (event) => {
            if (list.hidden) return;
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                active = (active + (event.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length;
                draw();
            } else if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                choose(active);
            } else if (event.key === 'Escape') {
                close();
            }
        });
        textarea.addEventListener('blur', () => setTimeout(close, 150));
    });

    // ---- Editor: saved replies ----------------------------------------------------------------------
    document.querySelectorAll('.editor [data-saved-toggle]').forEach((button) => {
        const editor = button.closest('.editor');
        const panel = editor.querySelector('.saved-panel');
        const textarea = editor.querySelector('textarea');
        if (!panel || !textarea) return;
        const list = panel.querySelector('.saved-list');
        let loaded = false;

        const fill = (replies) => {
            list.textContent = '';
            if (!replies.length) {
                const empty = document.createElement('div');
                empty.className = 'saved-empty';
                empty.textContent = panel.dataset.empty;
                list.appendChild(empty);
                return;
            }
            replies.forEach((reply) => {
                const row = document.createElement('button');
                row.type = 'button';
                const title = document.createElement('b');
                title.textContent = reply.title;
                const text = document.createElement('span');
                text.textContent = reply.body.replace(/\s+/g, ' ').slice(0, 90);
                row.append(title, text);
                row.addEventListener('click', () => { wrap(textarea, reply.body, ''); panel.hidden = true; });
                list.appendChild(row);
            });
        };

        button.addEventListener('click', () => {
            panel.hidden = !panel.hidden;
            if (panel.hidden) return;
            const box = editor.getBoundingClientRect();
            const at = button.getBoundingClientRect();
            panel.style.top = (at.bottom - box.top + 6) + 'px';
            panel.style.left = Math.max(6, Math.min(at.left - box.left, box.width - panel.offsetWidth - 6)) + 'px';
            if (loaded) return;
            fetch(button.dataset.url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then((response) => (response.ok ? response.json() : { replies: [] }))
                .then((data) => { loaded = true; fill(data.replies || []); })
                .catch(() => fill([]));
        });
        document.addEventListener('mousedown', (event) => {
            if (!panel.hidden && !panel.contains(event.target) && !button.contains(event.target)) panel.hidden = true;
        });
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape') panel.hidden = true; });
    });

    // ---- Pictures in posts open in place: larger, with the others of the same post one key away ------
    (() => {
        let box = null;
        let set = [];
        let at = 0;
        const source = (image) => { const link = image.closest('a.bb-attach-img'); return link ? link.href : image.src; };
        const show = () => {
            const picture = box.querySelector('img');
            picture.src = source(set[at]);
            box.querySelector('.lightbox-count').textContent = set.length > 1 ? (at + 1) + ' / ' + set.length : '';
            box.querySelectorAll('[data-step]').forEach((button) => { button.hidden = set.length < 2; });
        };
        const close = () => { if (box) { box.remove(); box = null; document.removeEventListener('keydown', keys); } };
        const step = (by) => { at = (at + by + set.length) % set.length; show(); };
        const keys = (event) => {
            if (event.key === 'Escape') close();
            else if (event.key === 'ArrowRight') step(1);
            else if (event.key === 'ArrowLeft') step(-1);
        };
        const control = (className, text, label, onClick) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = className;
            button.textContent = text;
            button.setAttribute('aria-label', label);
            button.addEventListener('click', (event) => { event.stopPropagation(); onClick(); });
            return button;
        };

        document.addEventListener('click', (event) => {
            const image = event.target.closest && event.target.closest('.bb img.bb-img');
            if (!image || image.closest('.editor') || image.closest('a:not(.bb-attach-img)') || event.ctrlKey || event.metaKey || event.shiftKey) return;
            event.preventDefault();
            const scope = image.closest('.post-body, .bb') || document;
            set = Array.from(scope.querySelectorAll('img.bb-img'));
            at = Math.max(0, set.indexOf(image));

            box = document.createElement('div');
            box.className = 'lightbox';
            box.setAttribute('role', 'dialog');
            box.setAttribute('aria-modal', 'true');
            const picture = document.createElement('img');
            picture.alt = image.alt || '';
            picture.addEventListener('click', (inner) => inner.stopPropagation());
            const count = document.createElement('span');
            count.className = 'lightbox-count';
            const previous = control('lightbox-nav prev', '‹', '←', () => step(-1));
            const next = control('lightbox-nav next', '›', '→', () => step(1));
            previous.dataset.step = next.dataset.step = '';
            box.append(picture, count, previous, next, control('lightbox-close', '×', 'Esc', close));
            box.addEventListener('click', close);
            document.body.appendChild(box);
            document.addEventListener('keydown', keys);
            show();
        });
    })();

    // ---- Choices that apply themselves: filters above a thread list, "go to forum" ---------------
    document.querySelectorAll('form[data-auto-submit] select').forEach((select) => {
        select.addEventListener('change', () => {
            // Empty choices stay out of the address.
            select.form.querySelectorAll('select').forEach((field) => { field.disabled = field.value === ''; });
            select.form.submit();
        });
    });
    document.querySelectorAll('select[data-jump]').forEach((select) => {
        select.addEventListener('change', () => { if (select.value) location.href = select.value; });
    });

    // ---- Up and down buttons on long pages ---------------------------------------------------------
    const scrollButtons = document.querySelector('[data-scroll-buttons]');
    if (scrollButtons) {
        const smooth = () => (window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth');
        const refreshScroll = () => {
            const room = document.documentElement.scrollHeight - window.innerHeight;
            scrollButtons.hidden = room < 900;
            scrollButtons.querySelector('[data-scroll="top"]').disabled = window.scrollY < 300;
            scrollButtons.querySelector('[data-scroll="bottom"]').disabled = window.scrollY > room - 300;
        };
        scrollButtons.addEventListener('click', (event) => {
            const button = event.target.closest('[data-scroll]');
            if (!button) return;
            window.scrollTo({ top: button.dataset.scroll === 'top' ? 0 : document.documentElement.scrollHeight, behavior: smooth() });
        });
        window.addEventListener('scroll', refreshScroll, { passive: true });
        window.addEventListener('resize', refreshScroll);
        refreshScroll();
    }

    // ---- Lists with tick boxes outside the moderation bars: "select all" -------------------------
    document.querySelectorAll('[data-select-all]').forEach((all) => {
        const form = document.getElementById(all.dataset.selectAll);
        if (!form || form.hasAttribute('data-mod-bar')) return;
        all.addEventListener('change', () => {
            document.querySelectorAll('input[type="checkbox"][form="' + form.id + '"]').forEach((box) => { box.checked = all.checked; });
        });
    });

    // ---- Admin, user group: the banner samples follow the text, the colours and the icon as they are typed ----
    document.querySelectorAll('[data-banner-form]').forEach((section) => {
        const form = section.closest('form');
        const field = (name) => form.querySelector('[name="' + name + '"]');
        const colour = (value) => (/^#[0-9a-f]{6}$/i.test(value) ? value : '');
        const icons = {};
        section.querySelectorAll('.icon-picker label').forEach((label) => {
            const svg = label.querySelector('svg');
            icons[label.querySelector('input').value] = svg ? svg.outerHTML : '';
        });
        const refresh = () => {
            const text = field('badge').value.trim() || field('name').value.trim();
            const chosen = form.querySelector('[name="banner_icon"]:checked');
            const icon = chosen ? icons[chosen.value] : '';
            section.querySelectorAll('[data-banner-sample]').forEach((sample) => {
                const c1 = colour(field('color').value);
                const c2 = colour(field('banner_color2').value);
                sample.style.cssText = (c1 ? '--c1: ' + c1 + ';' : '') + (c2 ? '--c2: ' + c2 + ';' : '');
                if (text) sample.querySelector('.gb-text').textContent = text;
                let box = sample.querySelector('.gb-icon');
                if (icon) {
                    if (!box) { box = document.createElement('span'); box.className = 'gb-icon'; sample.prepend(box); }
                    box.innerHTML = icon; // an icon of this page's own markup
                    const svg = box.querySelector('svg');
                    svg.setAttribute('width', 13);
                    svg.setAttribute('height', 13);
                } else if (box) {
                    box.remove();
                }
            });
        };
        form.addEventListener('input', refresh);
        form.addEventListener('change', refresh);
    });

    // ---- Thread titles: every word starts with a capital letter (the server does the same on saving) ----
    document.querySelectorAll('input[data-title-case]').forEach((input) => {
        const locale = document.documentElement.lang || 'en';
        const tidy = (value) => {
            let title = value.replace(/\s+/g, ' ').trim();
            const letters = title.replace(/[^\p{L}]/gu, '');
            if (letters.length >= 4 && !/\p{Ll}/u.test(letters)) title = title.toLocaleLowerCase(locale);
            return title.split(' ').map((word) => {
                if (word.includes('://') || /^www\./i.test(word) || /^.+\p{Lu}/u.test(word)) return word;
                return word.replace(/(^[(\[{"“«'‘]*|[\/-])(\p{Ll})/gu, (all, before, letter) => before + letter.toLocaleUpperCase(locale));
            }).join(' ');
        };
        input.addEventListener('blur', () => { if (input.value.trim()) input.value = tidy(input.value); });
    });

    // ---- Groups of tick boxes: all / none / invert, a count, and one box per section ---------------
    document.querySelectorAll('[data-check-group]').forEach((group) => {
        const usable = (root) => Array.from(root.querySelectorAll('input[type="checkbox"]:not([data-check-toggle])')).filter((box) => !box.disabled);
        const count = group.querySelector('[data-check-count]');

        const refresh = () => {
            const boxes = usable(group);
            const checked = boxes.filter((box) => box.checked).length;
            if (count) count.textContent = count.dataset.text.replace(':checked', checked).replace(':total', boxes.length);
            group.querySelectorAll('[data-check="all"]').forEach((button) => { button.disabled = checked === boxes.length; });
            group.querySelectorAll('[data-check="none"]').forEach((button) => { button.disabled = checked === 0; });
            group.querySelectorAll('[data-check-section]').forEach((section) => {
                const toggle = section.querySelector('[data-check-toggle]');
                if (!toggle) return;
                const own = usable(section);
                const on = own.filter((box) => box.checked).length;
                toggle.checked = own.length > 0 && on === own.length;
                toggle.indeterminate = on > 0 && on < own.length;
            });
        };

        group.addEventListener('click', (event) => {
            const button = event.target.closest('[data-check]');
            if (!button) return;
            const mode = button.dataset.check;
            usable(group).forEach((box) => { box.checked = mode === 'invert' ? !box.checked : mode === 'all'; });
            refresh();
        });
        group.addEventListener('change', (event) => {
            if (event.target.matches('[data-check-toggle]')) {
                usable(event.target.closest('[data-check-section]')).forEach((box) => { box.checked = event.target.checked; });
            }
            refresh();
        });
        refresh();
    });

    // ---- Admin panel: search for a page, a setting or a member -------------------------------------
    const adminSearch = document.querySelector('[data-admin-search]');
    if (adminSearch) {
        const input = adminSearch.querySelector('input');
        const results = adminSearch.querySelector('.admin-search-results');
        const pages = Array.from(document.querySelectorAll('.admin-side nav a[href]')).filter((link) => link.getAttribute('href') !== '#')
            .map((link) => ({ title: link.textContent.replace(/\s+/g, ' ').trim().replace(/\s\d+$/, ''), url: link.href, hint: '' }));
        let timer = null;
        let asked = '';

        const section = (label, items) => {
            if (!items.length) return;
            const head = document.createElement('div');
            head.className = 'head';
            head.textContent = label;
            results.appendChild(head);
            items.forEach((item) => {
                const link = document.createElement('a');
                link.href = item.url;
                const title = document.createElement('b');
                title.textContent = item.title;
                link.appendChild(title);
                if (item.hint) { const hint = document.createElement('span'); hint.textContent = item.hint; link.appendChild(hint); }
                results.appendChild(link);
            });
        };
        const draw = (term, data) => {
            results.textContent = '';
            const lower = term.toLocaleLowerCase(document.documentElement.lang || 'tr');
            section(adminSearch.dataset.lPages, pages.filter((page) => page.title.toLocaleLowerCase(document.documentElement.lang || 'tr').includes(lower)).slice(0, 6));
            section(adminSearch.dataset.lSettings, data.settings || []);
            section(adminSearch.dataset.lMembers, data.members || []);
            if (!results.children.length) {
                const empty = document.createElement('div');
                empty.className = 'empty-line';
                empty.textContent = adminSearch.dataset.lEmpty;
                results.appendChild(empty);
            }
            results.hidden = false;
        };

        input.addEventListener('input', () => {
            const term = input.value.trim();
            clearTimeout(timer);
            if (term.length < 2) { results.hidden = true; return; }
            draw(term, {});
            timer = setTimeout(() => {
                asked = term;
                fetch(adminSearch.dataset.adminSearch + '?q=' + encodeURIComponent(term), { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                    .then((response) => (response.ok ? response.json() : null))
                    .then((data) => { if (data && asked === term && input.value.trim() === term) draw(term, data); })
                    .catch(() => {});
            }, 200);
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { results.hidden = true; input.blur(); }
            if (event.key === 'Enter') { const first = results.querySelector('a'); if (first) { event.preventDefault(); location.href = first.href; } }
        });
        document.addEventListener('mousedown', (event) => { if (!adminSearch.contains(event.target)) results.hidden = true; });
    }

    // A setting reached from the search or the checklist is brought into view and marked for a moment.
    if (location.hash && /^#[\w-]+$/.test(location.hash) && document.querySelector('.admin-shell')) {
        const target = document.getElementById(location.hash.slice(1));
        const field = target && (target.closest('.field') || target);
        if (field) { field.classList.add('just-found'); field.scrollIntoView({ block: 'center' }); }
    }

    // Hero slides on the home page: dots, arrows and a slow turn that stops while the pointer rests on it.
    document.querySelectorAll('[data-hero]').forEach((hero) => {
        const slides = Array.from(hero.querySelectorAll('[data-hero-slide]'));
        const dots = Array.from(hero.querySelectorAll('[data-hero-dot]'));
        if (slides.length < 2) return;
        let current = 0;
        let timer = null;
        const show = (index) => {
            current = (index + slides.length) % slides.length;
            slides.forEach((slide, i) => { slide.hidden = i !== current; slide.classList.toggle('active', i === current); });
            dots.forEach((dot, i) => dot.classList.toggle('active', i === current));
        };
        const stop = () => { if (timer) { clearInterval(timer); timer = null; } };
        const start = () => {
            stop();
            if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) timer = setInterval(() => show(current + 1), 7000);
        };
        dots.forEach((dot, i) => dot.addEventListener('click', () => { show(i); start(); }));
        hero.querySelectorAll('[data-hero-step]').forEach((button) => button.addEventListener('click', () => { show(current + Number(button.dataset.heroStep)); start(); }));
        hero.addEventListener('mouseenter', stop);
        hero.addEventListener('mouseleave', start);
        start();
    });

    // Lets the page (and tests) know the script ran to the end.
    root.dataset.js = 'ready';
})();
