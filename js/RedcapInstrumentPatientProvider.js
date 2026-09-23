/**
 * Duck-types open-pedigree's `AbstractRecordLinkProvider` contract against
 * REDCap repeating-instrument rows (originally delivered against
 * `AbstractPatientProvider` by `pedigree-repeating-instrument-import`;
 * re-targeted onto `RecordLinkProvider` by
 * `pedigree-editor-redcap-extension-extraction`, at feature parity - search
 * a row, link it, one-time read-only import via a button. No deep-link/
 * refresh/create-new-row behavior yet; `canCreateNew` stays `false` until
 * `pedigree-editor-repeating-instrument-sync` adds that), via the module's
 * `PedigreeInstrumentService.php` AJAX endpoint.
 *
 * Loaded into `open-pedigree/localEditor.html`'s window (a separate
 * document from the main REDCap data-entry page), which is why this is
 * plain vanilla JS matching that file's own style — not the jQuery style
 * used by `pedigreeEditorEM.js` on the REDCap page side, and not part of
 * the `open-pedigree` TypeScript/webpack build (this class isn't
 * `import`-able there; it just needs to structurally match
 * `AbstractRecordLinkProvider`'s method signatures at runtime).
 *
 * Reference pattern: `open-pedigree`'s own `SmartPatientProvider.ts`, which
 * reads node state via `window.editor.getView().getNode(nodeId)` from
 * inside a concrete provider the same way `openEditor` below does to find
 * the record ref to edit (`AbstractRecordLinkProvider.openEditor` is
 * nodeId-only - it doesn't receive the ref as a parameter).
 *
 * Reuses the `msdialog-*` CSS classes (injected into the page by the bundle
 * itself) for visually-matching modal chrome, since `NativeModal` itself
 * isn't exported on `window.OpenPedigree` and so isn't reachable from here.
 */
(function (global) {
    var REDCAP_REF_PREFIX = 'record:';

    function encodeRef(record, instance) {
        return REDCAP_REF_PREFIX + record + '/instance:' + instance;
    }

    function decodeRef(ref) {
        var match = /^record:(.+)\/instance:(\d+)$/.exec(ref || '');
        return match ? { record: match[1], instance: parseInt(match[2], 10) } : null;
    }

    function RedcapInstrumentPatientProvider(options) {
        options = options || {};
        this._endpoint = options.endpoint;
        this._configured = !!options.configured;
    }

    RedcapInstrumentPatientProvider.prototype.isConfigured = function () {
        return this._configured;
    };

    // AbstractPatientProvider's canLinkPatient(nodeId) delegated to
    // canLinkProband()/canSearchFamilyMembers(), both hardcoded `true` -
    // i.e. it was already unconditionally `this._configured` regardless of
    // nodeId. RecordLinkProvider has no proband/family-member split, so
    // that indirection is dropped rather than reimplemented.
    RedcapInstrumentPatientProvider.prototype.canLink = function (nodeId) {
        return this._configured;
    };

    // No create-new-row behavior yet (pedigree-editor-repeating-instrument-sync's
    // job, layered on top of this once it lands) - always false for now.
    RedcapInstrumentPatientProvider.prototype.canCreateNew = function (nodeId) {
        return false;
    };

    // GET, not POST: every action this endpoint supports (search/import/
    // lookup/questionnaire) only reads data, never writes - REDCap only
    // requires a `redcap_csrf_token` for POST requests to module pages, so
    // using GET here needs no token at all (avoiding an earlier version of
    // this file that carried one in the URL, where it would end up in
    // server access logs and browser history).
    RedcapInstrumentPatientProvider.prototype._get = function (params) {
        var query = Object.keys(params).map(function (key) {
            return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
        }).join('&');
        var url = this._endpoint + (this._endpoint.indexOf('?') >= 0 ? '&' : '?') + query;

        return fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok) {
                    throw new Error((json && (json.error_description || json.error)) || ('HTTP ' + response.status));
                }
                if (json && json.error) {
                    throw new Error(json.error_description || json.error);
                }
                return json;
            });
        });
    };

    function createModal(titleText) {
        var overlay = document.createElement('div');
        overlay.className = 'msdialog-modal-container';
        var box = document.createElement('div');
        box.className = 'msdialog-box';
        overlay.appendChild(box);

        if (titleText) {
            var title = document.createElement('div');
            title.className = 'msdialog-title';
            title.textContent = titleText;
            box.appendChild(title);
        }
        var closeBtn = document.createElement('div');
        closeBtn.className = 'msdialog-close-btn';
        closeBtn.textContent = '✕';
        closeBtn.addEventListener('click', function () { close(); });
        box.appendChild(closeBtn);

        var content = document.createElement('div');
        content.className = 'content';
        box.appendChild(content);

        function close() {
            if (overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
        }

        document.body.appendChild(overlay);
        return { content: content, close: close };
    }

    // Returns { M, F, U } -> allowed(bool) for the node, per open-pedigree's
    // own partnership-consistency rule (e.g. a node already partnered with a
    // known-gender person cannot take that same gender) - or null if
    // unavailable (no window.editor, or an older open-pedigree build without
    // getPossibleGenders). null means "don't filter", not "nothing allowed".
    function getPossibleGenders(nodeId) {
        try {
            return window.editor.getGraph().getPossibleGenders(nodeId);
        } catch (e) {
            return null;
        }
    }

    RedcapInstrumentPatientProvider.prototype.openPicker = function (nodeId, onLinked) {
        var self = this;
        var modal = createModal('Link to a family member record');

        var searchRow = document.createElement('div');
        searchRow.className = 'patient-picker-search-row';
        var input = document.createElement('input');
        input.type = 'text';
        input.placeholder = 'Search…';
        var searchBtn = document.createElement('button');
        searchBtn.type = 'button';
        searchBtn.textContent = 'Search';
        searchRow.appendChild(input);
        searchRow.appendChild(searchBtn);

        var hiddenNotice = document.createElement('div');
        hiddenNotice.style.fontSize = '0.85em';
        hiddenNotice.style.color = '#666';

        var results = document.createElement('div');
        results.className = 'patient-picker-results';

        modal.content.appendChild(searchRow);
        modal.content.appendChild(hiddenNotice);
        modal.content.appendChild(results);

        function doSearch() {
            results.textContent = 'Searching…';
            hiddenNotice.textContent = '';
            self._get({ type: 'search', query: input.value.trim() })
                .then(function (matches) {
                    results.innerHTML = '';
                    if (!matches || matches.length === 0) {
                        results.textContent = 'No matches found.';
                        return;
                    }

                    // A result whose mapped gender field value is a gender this
                    // node cannot currently take (e.g. the node is already
                    // partnered with a known-gender person) would silently fail
                    // to import that value later (open-pedigree's own
                    // partnership-consistency rule rejects it with no feedback -
                    // see the "father" gender-import investigation) - filtering
                    // here instead means the user never picks a record that
                    // can't actually be linked meaningfully. A result with no
                    // gender info at all (no mapsTo="gender" field configured)
                    // is never filtered.
                    var possibleGenders = getPossibleGenders(nodeId);
                    var shown = matches;
                    var hiddenCount = 0;
                    if (possibleGenders) {
                        shown = matches.filter(function (match) {
                            var allowed = !match.gender || possibleGenders[match.gender] !== false;
                            if (!allowed) { hiddenCount++; }
                            return allowed;
                        });
                    }

                    if (hiddenCount > 0) {
                        hiddenNotice.textContent = hiddenCount + ' result(s) hidden - incompatible gender for this position.';
                    }
                    if (shown.length === 0) {
                        results.textContent = 'No matches found.';
                        return;
                    }

                    shown.forEach(function (match) {
                        var row = document.createElement('div');
                        row.className = 'patient-picker-result-row';
                        row.textContent = match.display;
                        row.style.cursor = 'pointer';
                        row.style.padding = '4px 8px';
                        row.addEventListener('mouseenter', function () { row.style.background = '#e8f0fe'; });
                        row.addEventListener('mouseleave', function () { row.style.background = ''; });
                        row.addEventListener('click', function () {
                            modal.close();
                            onLinked(encodeRef(match.record, match.instance), { firstName: match.display });
                        });
                        results.appendChild(row);
                    });
                })
                .catch(function (e) {
                    results.textContent = 'Search failed: ' + String(e && e.message || e);
                });
        }

        searchBtn.addEventListener('click', doSearch);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                doSearch();
            }
        });
        doSearch();
    };

    // AbstractRecordLinkProvider.openEditor is nodeId-only (no recordRef
    // parameter - see its own header comment / record-link-provider design
    // D2's implementation note): the ref is looked up here via the live
    // node, the same pattern SmartPatientProvider.ts already uses.
    RedcapInstrumentPatientProvider.prototype.openEditor = function (nodeId, onDone) {
        var node = window.editor && window.editor.getView().getNode(nodeId);
        var ref = decodeRef(node && node.getLinkedRecordRef && node.getLinkedRecordRef());
        var modal = createModal('Import from linked record');
        modal.content.textContent = 'Loading…';

        if (!ref) {
            modal.content.textContent = 'Not a REDCap instrument reference.';
            return;
        }

        this._get({ type: 'import', record: ref.record, instance: ref.instance })
            .then(function (answers) {
                modal.content.innerHTML = '';
                if (!answers || answers.length === 0) {
                    modal.content.textContent = 'No importable answers found on the linked record.';
                    return;
                }

                var intro = document.createElement('p');
                intro.textContent = 'Import the following ' + answers.length + ' answer(s) from the linked record:';
                modal.content.appendChild(intro);

                var list = document.createElement('ul');
                answers.forEach(function (answer) {
                    var li = document.createElement('li');
                    li.textContent = answer.linkId + ': ' + JSON.stringify(answer.value);
                    list.appendChild(li);
                });
                modal.content.appendChild(list);

                var importBtn = document.createElement('button');
                importBtn.type = 'button';
                importBtn.textContent = 'Import';
                importBtn.addEventListener('click', function () {
                    modal.close();
                    onDone(answers);
                });
                modal.content.appendChild(importBtn);
            })
            .catch(function (e) {
                modal.content.textContent = 'Import failed: ' + String(e && e.message || e);
            });
    };

    // Not supported yet - canCreateNew() always returns false, so the host
    // never offers a "Create new" action that would reach this method.
    RedcapInstrumentPatientProvider.prototype.createNew = function (nodeId, onCreated) {
        console.warn('RedcapInstrumentPatientProvider.createNew() is not yet supported');
    };

    global.RedcapInstrumentPatientProvider = RedcapInstrumentPatientProvider;
})(window);
