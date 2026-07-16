/**
 * Duck-types open-pedigree's `AbstractPatientProvider` contract against
 * REDCap repeating-instrument rows (design D6 of the
 * pedigree-repeating-instrument-import OpenSpec change), via the module's
 * `PedigreeInstrumentService.php` AJAX endpoint.
 *
 * Loaded into `open-pedigree/localEditor.html`'s window (a separate
 * document from the main REDCap data-entry page), which is why this is
 * plain vanilla JS matching that file's own style — not the jQuery style
 * used by `pedigreeEditorEM.js` on the REDCap page side, and not part of
 * the `open-pedigree` TypeScript/webpack build (this class isn't
 * `import`-able there; it just needs to structurally match
 * `AbstractPatientProvider`'s method signatures at runtime).
 *
 * Reference pattern: `open-pedigree`'s own `FHIRPatientProvider.ts`. Reuses
 * its `msdialog-*` CSS classes (injected into the page by the bundle
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
        this._csrfToken = options.csrfToken;
        this._configured = !!options.configured;
    }

    RedcapInstrumentPatientProvider.prototype.isConfigured = function () {
        return this._configured;
    };

    RedcapInstrumentPatientProvider.prototype.canImportClinicalData = function () {
        return this._configured;
    };

    // These three mirror AbstractPatientProvider's *default* (non-abstract)
    // implementations. This class doesn't literally extend that TypeScript
    // base class (it's duck-typed from plain JS, loaded outside the
    // webpack build - see the file header), so the defaults aren't
    // inherited for free and must be reimplemented here.
    RedcapInstrumentPatientProvider.prototype.canSearchFamilyMembers = function () {
        return true;
    };

    RedcapInstrumentPatientProvider.prototype.canLinkProband = function () {
        return true;
    };

    RedcapInstrumentPatientProvider.prototype.canLinkPatient = function (nodeId) {
        return this._configured && (nodeId === 0 ? this.canLinkProband() : this.canSearchFamilyMembers());
    };

    RedcapInstrumentPatientProvider.prototype._post = function (params) {
        var body = [];
        Object.keys(params).forEach(function (key) {
            body.push(encodeURIComponent(key) + '=' + encodeURIComponent(params[key]));
        });
        body.push('redcap_csrf_token=' + encodeURIComponent(this._csrfToken));

        return fetch(this._endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body.join('&'),
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        }).then(function (json) {
            if (json && json.error) {
                throw new Error(json.error_description || json.error);
            }
            return json;
        });
    };

    RedcapInstrumentPatientProvider.prototype.lookupPatient = function (patientRef, onSuccess, onError) {
        var ref = decodeRef(patientRef);
        if (!ref) {
            onError('Not a REDCap instrument reference: ' + patientRef);
            return;
        }
        this._post({ type: 'lookup', record: ref.record, instance: ref.instance })
            .then(function (result) {
                onSuccess((result && result.displayName) || (ref.record + ' #' + ref.instance));
            })
            .catch(function (e) {
                onError(String(e && e.message || e));
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

    RedcapInstrumentPatientProvider.prototype.openPatientPickerModal = function (nodeId, onSelected) {
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

        var results = document.createElement('div');
        results.className = 'patient-picker-results';

        modal.content.appendChild(searchRow);
        modal.content.appendChild(results);

        function doSearch() {
            results.textContent = 'Searching…';
            self._post({ type: 'search', query: input.value.trim() })
                .then(function (matches) {
                    results.innerHTML = '';
                    if (!matches || matches.length === 0) {
                        results.textContent = 'No matches found.';
                        return;
                    }
                    matches.forEach(function (match) {
                        var row = document.createElement('div');
                        row.className = 'patient-picker-result-row';
                        row.textContent = match.display;
                        row.style.cursor = 'pointer';
                        row.style.padding = '4px 8px';
                        row.addEventListener('mouseenter', function () { row.style.background = '#e8f0fe'; });
                        row.addEventListener('mouseleave', function () { row.style.background = ''; });
                        row.addEventListener('click', function () {
                            modal.close();
                            onSelected(encodeRef(match.record, match.instance), { firstName: match.display });
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

    RedcapInstrumentPatientProvider.prototype.openClinicalImportModal = function (nodeId, patientRef, onImported) {
        var ref = decodeRef(patientRef);
        var modal = createModal('Import from linked record');
        modal.content.textContent = 'Loading…';

        if (!ref) {
            modal.content.textContent = 'Not a REDCap instrument reference.';
            return;
        }

        this._post({ type: 'import', record: ref.record, instance: ref.instance })
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
                    onImported(answers);
                });
                modal.content.appendChild(importBtn);
            })
            .catch(function (e) {
                modal.content.textContent = 'Import failed: ' + String(e && e.message || e);
            });
    };

    global.RedcapInstrumentPatientProvider = RedcapInstrumentPatientProvider;
})(window);
