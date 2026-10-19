function getNsT3alLoader() {
    return document.getElementById('ns-t3al__loader');
}

function resolveNsT3alLoaderMessage(loader, message) {
    if (message) {
        return message;
    }
    const fromData = loader.getAttribute('data-loader-default');
    if (fromData) {
        return fromData;
    }
    if (typeof TYPO3 !== 'undefined' && TYPO3.lang && TYPO3.lang['loader_text']) {
        return TYPO3.lang['loader_text'];
    }
    return 'Updating view…';
}

function showNsT3alLoader(message) {
    const loader = getNsT3alLoader();
    if (!loader) {
        return;
    }
    const textEl = loader.querySelector('.loader-text');
    if (textEl) {
        // Always set copy so a prior AI-translate message does not stick on filter reloads.
        textEl.textContent = resolveNsT3alLoaderMessage(loader, message);
    }
    loader.classList.add('ns-show-overlay');
    loader.setAttribute('aria-busy', 'true');
}

/**
 * Show overlay, wait for a paint, then run callback (e.g. form.submit).
 */
function showNsT3alLoaderThen(callback, message) {
    showNsT3alLoader(message);
    window.requestAnimationFrame(function () {
        window.requestAnimationFrame(function () {
            if (typeof callback === 'function') {
                callback();
            }
        });
    });
}

function initApiModelPanels(root) {
    const container = root || document;
    const wrappers = container.querySelectorAll('[data-ns-t3al-api-model]');
    wrappers.forEach(function (wrapper) {
        const radios = Array.from(wrapper.querySelectorAll('input[name="apiModel"], input[name$="[apiModel]"]'));
        const panels = wrapper.querySelectorAll('.text-api-model-collapse');
        const labels = wrapper.querySelectorAll('[data-ns-t3al-api-model-target]');

        function showPanelFor(modelValue) {
            const targetId = 'collapse' + modelValue;
            panels.forEach(function (panel) {
                panel.classList.toggle('show', panel.id === targetId);
            });
            labels.forEach(function (label) {
                const isActive = label.getAttribute('data-ns-t3al-api-model-target') === targetId;
                label.classList.toggle('active', isActive);
                label.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
        }

        function selectRadio(radio) {
            if (!radio) {
                return;
            }
            radios.forEach(function (item) {
                item.checked = item === radio;
            });
            showPanelFor(radio.value);
            const persist = wrapper.querySelector('[data-ns-t3al-selected-api-model]');
            if (persist) {
                persist.value = radio.value;
            }
            wrapper.setAttribute('data-ns-t3al-api-model-default', radio.value);
        }

        // Prefer the server-rendered selection (survives missing API keys / flash forwards).
        // Never fall back to "first with key" when the server already sent a provider.
        const defaultModel = (wrapper.getAttribute('data-ns-t3al-api-model-default') || '').trim();
        let preferred = null;
        if (defaultModel) {
            preferred = radios.find(function (radio) {
                return radio.value === defaultModel;
            });
            if (!preferred) {
                // Server said e.g. "deepl" but radio missing — still show that panel, do not snap to OpenAI.
                showPanelFor(defaultModel);
                const persist = wrapper.querySelector('[data-ns-t3al-selected-api-model]');
                if (persist) {
                    persist.value = defaultModel;
                }
            }
        }
        if (!preferred) {
            preferred = radios.find(function (radio) {
                return radio.checked;
            });
        }
        if (!preferred && !defaultModel) {
            preferred = radios.find(function (radio) {
                return radio.getAttribute('data-has-key') === '1';
            });
        }
        if (!preferred && !defaultModel) {
            preferred = radios[0];
        }
        if (preferred) {
            selectRadio(preferred);
        }

        radios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (radio.checked) {
                    selectRadio(radio);
                }
            });
        });
    });
}

function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function submitFilterForm(select) {
    const form = select ? select.closest('form') : null;
    if (!form) {
        return;
    }
    showNsT3alLoader();
    form.submit();
}

function readComboboxItems(wrapper, select) {
    const metaMap = {};
    wrapper.querySelectorAll('.ns-t3al-combobox__meta [data-value]').forEach(function (node) {
        const value = String(node.getAttribute('data-value') || '');
        if (!value) {
            return;
        }
        metaMap[value] = {
            group: String(node.getAttribute('data-group') || '').trim(),
            title: String(node.getAttribute('data-title') || '').trim()
        };
    });

    return Array.from(select.options)
        .filter(function (option) {
            return option.value !== '' && option.value !== '0';
        })
        .map(function (option) {
            const meta = metaMap[option.value] || {};
            const title = meta.title || option.getAttribute('data-title') || option.text.trim() || option.value;
            return {
                value: option.value,
                title: title,
                subtitle: option.value,
                group: meta.group || option.getAttribute('data-group') || '',
                selected: option.selected
            };
        });
}

function setComboboxOpen(wrapper, open) {
    const menu = wrapper.querySelector('.ns-t3al-combobox__menu');
    const input = wrapper.querySelector('.ns-t3al-combobox__input');
    if (!menu || !input) {
        return;
    }
    if (open) {
        menu.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        wrapper.classList.add('is-open');
    } else {
        menu.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        wrapper.classList.remove('is-open');
        menu.querySelectorAll('.ns-t3al-combobox__option.is-active').forEach(function (opt) {
            opt.classList.remove('is-active');
        });
    }
}

function getSelectedLabel(item) {
    if (!item) {
        return '';
    }
    return item.subtitle ? item.title + ' (' + item.subtitle + ')' : item.title;
}

function syncMultiChips(wrapper, items) {
    const chips = wrapper.querySelector('.ns-t3al-combobox__chips');
    if (!chips) {
        return;
    }
    const selected = items.filter(function (item) {
        return item.selected;
    });
    if (!selected.length) {
        chips.innerHTML = '';
        chips.hidden = true;
        return;
    }
    chips.hidden = false;
    chips.innerHTML = selected.map(function (item) {
        return '<button type="button" class="ns-t3al-combobox__chip" data-value="'
            + escapeHtml(item.value) + '" title="' + escapeHtml(item.subtitle) + '">'
            + '<span class="ns-t3al-combobox__chip-label">' + escapeHtml(item.title) + '</span>'
            + '<span class="ns-t3al-combobox__chip-key">' + escapeHtml(item.subtitle) + '</span>'
            + '<span class="ns-t3al-combobox__chip-remove" aria-hidden="true">×</span>'
            + '</button>';
    }).join('');
}

function updateClosedInputLabel(wrapper, select, items, mode) {
    const input = wrapper.querySelector('.ns-t3al-combobox__input');
    if (!input || document.activeElement === input) {
        return;
    }
    if (mode === 'multi') {
        const selected = items.filter(function (item) {
            return item.selected;
        });
        const template = wrapper.getAttribute('data-selected-label') || '%s selected';
        if (!selected.length) {
            input.value = '';
        } else if (selected.length <= 2) {
            input.value = selected.map(getSelectedLabel).join(', ');
        } else {
            input.value = template.replace('%s', String(selected.length));
        }
        syncMultiChips(wrapper, items);
        return;
    }
    const selectedOption = Array.from(select.options).find(function (option) {
        return option.selected && option.value !== '' && option.value !== '0';
    });
    const selectedItem = items.find(function (item) {
        return selectedOption && item.value === selectedOption.value;
    });
    input.value = selectedItem ? getSelectedLabel(selectedItem) : '';
}

function renderComboboxMenu(wrapper, select, options) {
    options = options || {};
    const menu = wrapper.querySelector('.ns-t3al-combobox__menu');
    const input = wrapper.querySelector('.ns-t3al-combobox__input');
    const mode = wrapper.getAttribute('data-ns-t3al-combobox-mode') || 'single';
    if (!menu || !input || !select) {
        return;
    }

    const items = readComboboxItems(wrapper, select);
    const query = options.queryOverride !== undefined && options.queryOverride !== null
        ? String(options.queryOverride)
        : String(input.value || '');

    let effectiveQuery = query.trim().toLowerCase();
    if (mode === 'single') {
        const selectedItem = items.find(function (item) {
            return item.selected;
        });
        if (selectedItem && query.trim() === getSelectedLabel(selectedItem).trim()) {
            effectiveQuery = '';
        }
    } else {
        const selected = items.filter(function (item) {
            return item.selected;
        });
        const template = wrapper.getAttribute('data-selected-label') || '%s selected';
        const summary = selected.length > 2
            ? template.replace('%s', String(selected.length))
            : selected.map(getSelectedLabel).join(', ');
        if (summary && query.trim() === summary.trim()) {
            effectiveQuery = '';
        }
    }

    const filtered = items.filter(function (item) {
        if (!effectiveQuery) {
            return true;
        }
        const haystack = (item.title + ' ' + item.subtitle + ' ' + item.group).toLowerCase();
        return haystack.indexOf(effectiveQuery) !== -1;
    });

    const emptyLabel = wrapper.getAttribute('data-empty-label') || 'No matches';
    if (!filtered.length) {
        menu.innerHTML = '<div class="ns-t3al-combobox__empty">' + escapeHtml(emptyLabel) + '</div>';
    } else {
        const groups = [];
        const groupMap = new Map();
        filtered.forEach(function (item) {
            const groupName = item.group || '';
            if (!groupMap.has(groupName)) {
                groupMap.set(groupName, []);
                groups.push(groupName);
            }
            groupMap.get(groupName).push(item);
        });

        // Prefer Local then System then other when multiple groups exist.
        groups.sort(function (a, b) {
            const rank = function (name) {
                if (name === 'Local') {
                    return 1;
                }
                if (name === 'System') {
                    return 2;
                }
                return name ? 3 : 4;
            };
            const diff = rank(a) - rank(b);
            return diff !== 0 ? diff : String(a).localeCompare(String(b));
        });

        const showGroups = groups.filter(Boolean).length > 1;
        let html = '';
        groups.forEach(function (groupName) {
            if (showGroups && groupName) {
                html += '<div class="ns-t3al-combobox__group">' + escapeHtml(groupName) + '</div>';
            }
            groupMap.get(groupName).forEach(function (item) {
                const selectedClass = item.selected ? ' is-selected' : '';
                if (mode === 'multi') {
                    html += '<button type="button" class="ns-t3al-combobox__option ns-t3al-combobox__option--multi'
                        + selectedClass + '"'
                        + ' role="option"'
                        + ' data-value="' + escapeHtml(item.value) + '"'
                        + ' aria-selected="' + (item.selected ? 'true' : 'false') + '">'
                        + '<span class="ns-t3al-combobox__check" aria-hidden="true"></span>'
                        + '<span class="ns-t3al-combobox__option-body">'
                        + '<span class="ns-t3al-combobox__option-title">' + escapeHtml(item.title) + '</span>'
                        + '<span class="ns-t3al-combobox__option-key">' + escapeHtml(item.subtitle) + '</span>'
                        + '</span>'
                        + '</button>';
                } else {
                    html += '<button type="button" class="ns-t3al-combobox__option' + selectedClass + '"'
                        + ' role="option"'
                        + ' data-value="' + escapeHtml(item.value) + '"'
                        + ' aria-selected="' + (item.selected ? 'true' : 'false') + '">'
                        + '<span class="ns-t3al-combobox__option-title">' + escapeHtml(item.title) + '</span>'
                        + '<span class="ns-t3al-combobox__option-key">' + escapeHtml(item.subtitle) + '</span>'
                        + '</button>';
                }
            });
        });
        menu.innerHTML = html;
    }

    if (options.keepOpen) {
        setComboboxOpen(wrapper, true);
    }
    if (!options.skipLabelSync) {
        updateClosedInputLabel(wrapper, select, items, mode);
    }
}

function selectComboboxValue(wrapper, select, value, options) {
    options = options || {};
    const mode = wrapper.getAttribute('data-ns-t3al-combobox-mode') || 'single';
    const input = wrapper.querySelector('.ns-t3al-combobox__input');

    if (mode === 'multi') {
        const option = Array.from(select.options).find(function (item) {
            return item.value === value;
        });
        if (!option) {
            return;
        }
        option.selected = !option.selected;
        renderComboboxMenu(wrapper, select, {
            keepOpen: true,
            queryOverride: options.queryOverride !== undefined ? options.queryOverride : '',
            skipLabelSync: false
        });
        if (options.submit !== false) {
            // Debounce multi-select submits so users can pick several languages.
            if (wrapper._nsT3alSubmitTimer) {
                clearTimeout(wrapper._nsT3alSubmitTimer);
            }
            wrapper._nsT3alSubmitTimer = setTimeout(function () {
                submitFilterForm(select);
            }, 650);
        }
        return;
    }

    select.value = value || '0';
    Array.from(select.options).forEach(function (option) {
        option.selected = option.value === select.value;
    });
    const items = readComboboxItems(wrapper, select);
    const selectedItem = items.find(function (item) {
        return item.value === select.value;
    });
    if (input) {
        input.value = selectedItem ? getSelectedLabel(selectedItem) : '';
    }
    setComboboxOpen(wrapper, false);
    if (options.submit !== false) {
        submitFilterForm(select);
    }
}

function bindSearchableComboboxes(root) {
    const container = root || document;
    container.querySelectorAll('[data-ns-t3al-combobox]').forEach(function (wrapper) {
        if (wrapper.dataset.nsT3alComboboxBound === '1') {
            return;
        }
        const selectSelector = wrapper.getAttribute('data-ns-t3al-combobox-select');
        const select = selectSelector
            ? container.querySelector(selectSelector) || document.querySelector(selectSelector)
            : wrapper.querySelector('select');
        const input = wrapper.querySelector('.ns-t3al-combobox__input');
        const toggle = wrapper.querySelector('.ns-t3al-combobox__toggle');
        const menu = wrapper.querySelector('.ns-t3al-combobox__menu');
        const mode = wrapper.getAttribute('data-ns-t3al-combobox-mode') || 'single';
        if (!select || !input || !menu) {
            return;
        }

        wrapper.dataset.nsT3alComboboxBound = '1';
        renderComboboxMenu(wrapper, select, { keepOpen: false });

        input.addEventListener('focus', function () {
            if (mode === 'multi' || select.value === '0' || select.value === '') {
                input.value = '';
            } else {
                const selectedItem = readComboboxItems(wrapper, select).find(function (item) {
                    return item.selected;
                });
                if (selectedItem && input.value === getSelectedLabel(selectedItem)) {
                    input.select();
                }
            }
            renderComboboxMenu(wrapper, select, { keepOpen: true, queryOverride: '', skipLabelSync: true });
        });

        input.addEventListener('input', function () {
            renderComboboxMenu(wrapper, select, {
                keepOpen: true,
                queryOverride: input.value,
                skipLabelSync: true
            });
        });

        input.addEventListener('keydown', function (event) {
            const options = Array.from(menu.querySelectorAll('.ns-t3al-combobox__option'));
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (menu.hidden) {
                    renderComboboxMenu(wrapper, select, { keepOpen: true, skipLabelSync: true });
                    return;
                }
                const active = menu.querySelector('.ns-t3al-combobox__option.is-active');
                let index = active ? options.indexOf(active) : -1;
                index = Math.min(options.length - 1, index + 1);
                options.forEach(function (opt) {
                    opt.classList.remove('is-active');
                });
                if (options[index]) {
                    options[index].classList.add('is-active');
                    options[index].scrollIntoView({ block: 'nearest' });
                }
                return;
            }
            if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (menu.hidden) {
                    return;
                }
                const active = menu.querySelector('.ns-t3al-combobox__option.is-active');
                let index = active ? options.indexOf(active) : options.length;
                index = Math.max(0, index - 1);
                options.forEach(function (opt) {
                    opt.classList.remove('is-active');
                });
                if (options[index]) {
                    options[index].classList.add('is-active');
                    options[index].scrollIntoView({ block: 'nearest' });
                }
                return;
            }
            if (event.key === 'Enter') {
                event.preventDefault();
                const active = menu.querySelector('.ns-t3al-combobox__option.is-active')
                    || (!menu.hidden && options[0]);
                if (active) {
                    selectComboboxValue(wrapper, select, active.getAttribute('data-value') || '', {
                        queryOverride: ''
                    });
                }
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                setComboboxOpen(wrapper, false);
                updateClosedInputLabel(wrapper, select, readComboboxItems(wrapper, select), mode);
            }
        });

        if (toggle) {
            toggle.addEventListener('click', function (event) {
                event.preventDefault();
                const willOpen = menu.hidden;
                if (willOpen) {
                    input.focus();
                    renderComboboxMenu(wrapper, select, {
                        keepOpen: true,
                        queryOverride: '',
                        skipLabelSync: true
                    });
                } else {
                    setComboboxOpen(wrapper, false);
                    updateClosedInputLabel(wrapper, select, readComboboxItems(wrapper, select), mode);
                }
            });
        }

        menu.addEventListener('click', function (event) {
            const option = event.target.closest('.ns-t3al-combobox__option');
            if (!option || !menu.contains(option)) {
                return;
            }
            event.preventDefault();
            selectComboboxValue(wrapper, select, option.getAttribute('data-value') || '', {
                queryOverride: mode === 'multi' ? '' : undefined
            });
        });

        const chips = wrapper.querySelector('.ns-t3al-combobox__chips');
        if (chips) {
            chips.addEventListener('click', function (event) {
                const chip = event.target.closest('.ns-t3al-combobox__chip');
                if (!chip) {
                    return;
                }
                event.preventDefault();
                selectComboboxValue(wrapper, select, chip.getAttribute('data-value') || '', {
                    queryOverride: ''
                });
            });
        }

        document.addEventListener('click', function onDocClick(event) {
            if (!wrapper.contains(event.target)) {
                if (!menu.hidden) {
                    setComboboxOpen(wrapper, false);
                    updateClosedInputLabel(wrapper, select, readComboboxItems(wrapper, select), mode);
                }
            }
        });
    });
}

function bindFilterAutoSubmit() {
    const filterSelects = document.querySelectorAll('#ns-t3al-file, select[data-name="file"]');
    filterSelects.forEach(function (select) {
        select.addEventListener('change', function () {
            submitFilterForm(select);
        });
    });
}

function findExtTypeInput(form) {
    return form.querySelector('#ns-t3al-ext-type')
        || Array.from(form.querySelectorAll('input[type="hidden"]')).find(function (input) {
            return input.name === 'extType' || /(?:^|\[)extType]$/.test(input.name);
        })
        || null;
}

function bindExtTypeFilter() {
    document.querySelectorAll('[data-ns-t3al-ext-type]').forEach(function (button) {
        if (button.dataset.nsT3alExtTypeBound === '1') {
            return;
        }
        button.dataset.nsT3alExtTypeBound = '1';
        button.addEventListener('click', function (event) {
            event.preventDefault();
            const form = button.closest('form') || document.getElementById('ns-t3al-filter');
            if (!form) {
                return;
            }
            const type = button.getAttribute('data-ns-t3al-ext-type') || 'Local';
            let hidden = findExtTypeInput(form);
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'extType';
                hidden.id = 'ns-t3al-ext-type';
                form.appendChild(hidden);
            }
            hidden.value = type;
            const extSelect = form.querySelector('#ns-t3al-extkey');
            if (extSelect) {
                extSelect.value = '0';
            }
            showNsT3alLoader();
            form.submit();
        });
    });
}

function bindEditorForm() {
    const translateForm = document.getElementById('translate_labels');
    if (!translateForm) {
        return;
    }

    const translateUri = translateForm.getAttribute('data-translate-uri');
    const saveUri = translateForm.getAttribute('data-save-uri') || translateForm.getAttribute('action');
    const submitButton = document.getElementsByName('fileLocation')[0];

    document.querySelectorAll('button[name="translate_ai"], input[name="translate_ai"]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            if (!translateUri || button.disabled) {
                return;
            }
            const loaderEl = getNsT3alLoader();
            const translateMessage = (loaderEl && loaderEl.getAttribute('data-loader-translate'))
                || (typeof TYPO3 !== 'undefined' && TYPO3.lang && TYPO3.lang['loader_text_translate'])
                || 'AI translation in progress… Please wait.';
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            translateForm.setAttribute('action', translateUri);
            showNsT3alLoaderThen(function () {
                translateForm.submit();
            }, translateMessage);
        });
    });

    document.querySelectorAll('.ns-t3al-crowdin-save, button[name="fileLocation"][value="crowdin"], input[name="fileLocation"][value="crowdin"]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            const projectIdInput = translateForm.querySelector('input[name="projectId"], input[name$="[projectId]"]');
            const projectId = projectIdInput ? projectIdInput.value.trim() : '';
            if (!projectId) {
                event.preventDefault();
                showNsT3alError(TYPO3.lang['projectId.missing'], TYPO3.lang['projectId.missing_field_desc']);
                if (projectIdInput) {
                    projectIdInput.focus();
                }
                return;
            }
            translateForm.setAttribute('action', saveUri);
            showNsT3alLoader();
        });
    });

    document.querySelectorAll('button[name="fileLocation"][value="local"], input[name="fileLocation"][value="local"]').forEach(function (button) {
        button.addEventListener('click', function () {
            translateForm.setAttribute('action', saveUri);
            showNsT3alLoader();
        });
    });

    translateForm.addEventListener('change', function (event) {
        if (event.target.form && event.target.form !== translateForm) {
            return;
        }
        if (submitButton) {
            submitButton.classList.add('btn-success');
            submitButton.classList.remove('btn-default');
        }
    });

    initTranslateGrid(translateForm);
}

function autosizeGridTextarea(textarea) {
    textarea.rows = 1;
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + 'px';
}

function autosizeGridTextareas(scope) {
    scope.querySelectorAll('.translate-row textarea').forEach(autosizeGridTextarea);
}

function initTranslateGrid(translateForm) {
    var elAct = null;
    var elLast = null;
    var dragging = false;
    var rows = translateForm.getElementsByClassName('translate-row');
    var i;
    var l;
    for (i = 1, l = rows.length; i < l; i++) {
        initRow(rows[i]);
    }

    autosizeGridTextareas(translateForm);
    translateForm.addEventListener('input', function (event) {
        if (event.target instanceof HTMLTextAreaElement) {
            autosizeGridTextarea(event.target);
        }
    });
    // Column widths change with the window, and with them the wrapped line count.
    var resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            autosizeGridTextareas(translateForm);
        }, 100);
    });

    var newindex = 0;

    // Row moves / deletes change no field value, so announce them like an edit (Save highlight, leave guard).
    function notifyGridChanged() {
        translateForm.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function initRow(row) {
        var button = row.querySelector('.ns-t3al-row-actions .add');
        if (button) {
            initAdd(row, button);
        }
        button = row.querySelector('.ns-t3al-row-actions .del');
        if (button) {
            initDel(row, button);
        }
        button = row.querySelector('.ns-t3al-row-actions .move');
        if (button) {
            initMove(row, button);
        }
    }

    function initMove(row, button) {
        button.setAttribute('draggable', 'true');
        button.addEventListener('dragstart', function (e) {
            e.dataTransfer.effectAllowed = 'move';
            elAct = row;
            elAct.style.opacity = '0.5';
            dragging = true;
        });
        button.addEventListener('dragend', function () {
            dragging = false;
            if (elAct) {
                elAct.style.opacity = '';
            }
            if (elLast) {
                elLast.classList.remove('over');
            }
        });
        row.addEventListener('dragover', function (e) {
            e.preventDefault();
            if (!dragging) {
                return false;
            }
            e.dataTransfer.dropEffect = 'move';
            var elNext = this.nextElementSibling;
            var rect = this.getBoundingClientRect();
            var vcenter = (rect.top + rect.bottom) / 2;
            if (elLast) {
                elLast.classList.remove('over');
            }
            elLast = (elNext && e.clientY >= vcenter) ? elNext : this;
            elLast.classList.add('over');
        });
        row.addEventListener('drop', function (e) {
            e.stopPropagation();
            e.preventDefault();
            if (!dragging) {
                return false;
            }
            var rect = this.getBoundingClientRect();
            var vcenter = (rect.top + rect.bottom) / 2;
            var previous = elAct.previousElementSibling;
            if (e.clientY < vcenter) {
                this.parentNode.insertBefore(elAct, this);
            } else {
                this.parentNode.insertBefore(elAct, this.nextSibling);
            }
            if (elAct.previousElementSibling !== previous) {
                notifyGridChanged();
            }
        });
    }

    function initAdd(row, button) {
        button.addEventListener('click', function (e) {
            e.stopPropagation();
            e.preventDefault();
            var refnode = row.nextSibling;
            var newnode = row.cloneNode(true);
            newnode.className = 'translate-row is-new';
            newnode.style.opacity = '';
            Array.prototype.forEach.call(newnode.children, function (cell) {
                cell.classList.remove('same', 'nd', 'nt');
            });
            var inputs = newnode.getElementsByTagName('input');
            var textareas = newnode.getElementsByTagName('textarea');
            var newkey = '_newkey' + newindex++;
            var key = '';
            if (inputs[0]) {
                var oname = inputs[0].getAttribute('name');
                key = oname.substring(oname.lastIndexOf('[') + 1, oname.lastIndexOf(']'));
                inputs[0].setAttribute('name', oname.replace('[' + key + ']', '[' + newkey + ']'));
                inputs[0].value = '';
            }
            for (i = 0; i < textareas.length; i++) {
                var taname = textareas[i].getAttribute('name');
                textareas[i].setAttribute('name', taname.replace('[' + key + '][', '[' + newkey + ']['));
                textareas[i].value = '';
                textareas[i].style.height = '';
            }
            initRow(newnode);
            row.parentNode.insertBefore(newnode, refnode);
            button.blur();
            if (inputs[0]) {
                inputs[0].focus({ preventScroll: true });
                newnode.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    function initDel(row, button) {
        button.addEventListener('click', function (e) {
            e.preventDefault();
            confirmRowDelete(row).then(function (confirmed) {
                if (!confirmed || !row.parentNode) {
                    return;
                }
                row.parentNode.removeChild(row);
                notifyGridChanged();
            });
        });
    }

    async function confirmRowDelete(row) {
        const lang = (typeof TYPO3 !== 'undefined' && TYPO3.lang) ? TYPO3.lang : {};
        const keyInput = row.querySelector('.ns-t3al-col-key input');
        const key = keyInput ? keyInput.value.trim() : '';
        const message = (lang['row.delete.message'] || 'Delete the label "%s" with all its translations? The file changes only when you save.')
            .replace('%s', key || '–');
        const [ModalModule, SeverityModule] = await Promise.all([
            import('@typo3/backend/modal.js'),
            import('@typo3/backend/severity.js'),
        ]);
        const Modal = ModalModule.default || ModalModule;
        const Severity = SeverityModule.default || SeverityModule;
        return new Promise(function (resolve) {
            let confirmed = false;
            const modal = Modal.confirm(lang['row.delete.title'] || 'Delete label', message, Severity.warning, [
                {
                    text: lang['row.delete.cancel'] || 'Cancel',
                    btnClass: 'btn-default',
                    name: 'cancel',
                    active: true,
                    trigger: function () {
                        modal.hideModal();
                    },
                },
                {
                    text: lang['row.delete.confirm'] || 'Delete',
                    btnClass: 'btn-warning',
                    name: 'delete',
                    trigger: function () {
                        confirmed = true;
                        modal.hideModal();
                    },
                },
            ]);
            modal.addEventListener('typo3-modal-hidden', function () {
                resolve(confirmed);
            });
        });
    }
}

/**
 * Logs tab: #expandAllButton expands/collapses all log panels.
 * Use document-level delegation so it still works when the ES module was
 * already evaluated on a prior module action (Translate/Search) in the same
 * backend document lifecycle.
 */
function toggleAllLogPanels(expand) {
    const scope = document.querySelector('.ns-t3al-module')
        || document.querySelector('.ns-t3al')
        || document;
    scope.querySelectorAll('.panel-collapse.collapse, .panel-collapse[id^="collapse-tab-logs"]').forEach(function (panel) {
        panel.classList.toggle('show', expand);
        if (expand) {
            panel.style.height = '';
            panel.style.display = '';
        }
    });
    scope.querySelectorAll('.panel-title[data-bs-toggle="collapse"], .panel-heading .panel-title').forEach(function (title) {
        title.classList.toggle('collapsed', !expand);
        title.setAttribute('aria-expanded', expand ? 'true' : 'false');
    });
}

function bindLogsToggleCollapseDelegation() {
    if (document.documentElement.dataset.nsT3alLogsToggleDelegation === '1') {
        return;
    }
    document.documentElement.dataset.nsT3alLogsToggleDelegation = '1';

    document.addEventListener('change', function (event) {
        const target = event.target;
        if (!(target instanceof HTMLInputElement) || target.id !== 'expandAllButton') {
            return;
        }
        toggleAllLogPanels(target.checked);
    });

    // Label click with btn-check can be flaky in some BE layouts — sync explicitly.
    document.addEventListener('click', function (event) {
        const label = event.target instanceof Element
            ? event.target.closest('label.expand-all-button[for="expandAllButton"]')
            : null;
        if (!label) {
            return;
        }
        const expandButton = document.getElementById('expandAllButton');
        if (!(expandButton instanceof HTMLInputElement)) {
            return;
        }
        // Let the browser toggle the checkbox first, then apply on next tick.
        window.setTimeout(function () {
            toggleAllLogPanels(expandButton.checked);
        }, 0);
    });
}

function initLogsToggleCollapse(root) {
    bindLogsToggleCollapseDelegation();
    const container = root || document;
    const expandButton = container.querySelector('#expandAllButton');
    if (!(expandButton instanceof HTMLInputElement)) {
        return;
    }
    // Sync panel state with current checkbox (e.g. after back/forward).
    if (expandButton.checked) {
        toggleAllLogPanels(true);
    }
}

function bootstrapModuleUiHelpers() {
    initApiModelPanels(document);
    bindSearchableComboboxes(document);
    bindFilterAutoSubmit();
    bindExtTypeFilter();
    bindEditorForm();
    initLogsToggleCollapse(document);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapModuleUiHelpers, { once: true });
} else {
    bootstrapModuleUiHelpers();
}

// Always register Logs toggle delegation at module evaluation time.
bindLogsToggleCollapseDelegation();

const logFileSelect = document.getElementById('logFile');
if (logFileSelect) {
    const translationLogs = document.getElementById('translationLogs');
    logFileSelect.addEventListener('change', function () {
        showNsT3alLoader();
        translationLogs.submit();
    });
}

const targetLanguageSelect = document.getElementById('targetLanguage');
if (targetLanguageSelect) {
    const translationLogs = document.getElementById('translationLogs');
    if (translationLogs) {
        targetLanguageSelect.addEventListener('change', function () {
            showNsT3alLoader();
            translationLogs.submit();
        });
    }
}

async function showNsT3alError(title, message) {
    const NotificationModule = await import('@typo3/backend/notification.js');
    (NotificationModule.default || NotificationModule).error(title, message);
}
