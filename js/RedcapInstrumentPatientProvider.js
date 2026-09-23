/**
 * Duck-types open-pedigree's `AbstractRecordLinkProvider` contract against
 * REDCap repeating-instrument rows (originally delivered against
 * `AbstractPatientProvider` by `pedigree-repeating-instrument-import`;
 * re-targeted onto `RecordLinkProvider` by
 * `pedigree-editor-redcap-extension-extraction`), via the module's
 * `PedigreeInstrumentService.php` AJAX endpoint. `pedigree-editor-repeating-
 * instrument-sync` adds: link/edit only once the record exists and only
 * against this record's rows, and "Edit in REDCap" (`openEditor`) opening
 * REDCap's own form for the row and re-importing when that window closes.
 * No create-new-row yet - `canCreateNew` stays `false` until that change's
 * task group 4.
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
        // Server-evaluated at page render (PedigreeEditorExternalModule::findStoredRecordName()):
        // whether the REDCap record this editor was opened from has ever been saved.
        // Fixed for this window's lifetime: a parent-form save doesn't re-navigate an
        // already-open editor, so "false" can go stale until the editor is reopened
        // from the form (which re-renders the URL) - hence the reopen hint below.
        // Defaults to false - an absent flag means "not known to exist", which only
        // costs a "save first" message, never a link to a record that isn't there.
        this._recordExists = !!options.recordExists;
        // The REDCap record this editor was opened from - the link picker only
        // offers that record's rows (a family's person rows live on its record).
        this._record = options.record || '';
        // REDCap data-entry URL for this record's linked-instrument rows, minus
        // &instance= (PedigreeEditorExternalModule builds it; see _editUrlFor()).
        this._editUrl = options.editUrl || '';
    }

    // Linking/editing/creating a repeating-instrument row all need the current
    // REDCap record to exist first (it has no persisted identity until its first
    // save). These actions stay offered - open-pedigree's action buttons only
    // support shown/hidden, and a silently-missing button explains nothing - but
    // each one stops here with an explanation instead of proceeding. Synchronous
    // on purpose: the planned "Edit in REDCap"/"create new row" actions (later
    // task groups of pedigree-editor-repeating-instrument-sync) must reach
    // window.open() within the same click handler (popup blockers), so no fetch
    // may sit between the click and this decision. Diagram-only editing never calls this.
    RedcapInstrumentPatientProvider.prototype._requireExistingRecord = function () {
        if (this._recordExists) {
            return true;
        }
        var modal = createModal('Save this form first');
        modal.content.textContent = 'Save this form once before linking family members to REDCap records. '
            + 'This record hasn\'t been saved yet, so it doesn\'t exist in REDCap to link from. '
            + 'If you have saved it since opening this editor, close the editor and reopen it from the form. '
            + 'Drawing and saving the pedigree diagram itself works as normal in the meantime.';
        return false;
    };

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
    // questionnaire) only reads data, never writes - REDCap only
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

    function showMessage(titleText, text) {
        var modal = createModal(titleText);
        modal.content.textContent = text;
        return modal;
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
        if (!this._requireExistingRecord()) {
            return;
        }
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

        // Computed once (doesn't change over the picker's lifetime) and sent
        // to the server so it can filter *before* applying its own result
        // limit - filtering only after a client-side fetch would let
        // incompatible-gender rows within that limit crowd out compatible
        // ones that exist beyond it. A result whose mapped gender field value
        // is a gender this node cannot currently take (e.g. the node is
        // already partnered with a known-gender person) would otherwise
        // silently fail to import that value later (open-pedigree's own
        // partnership-consistency rule rejects it with no feedback - see the
        // "father" gender-import investigation). A record with no gender
        // info at all (no mapsTo="gender" field configured) is never
        // filtered - see RedcapInstrumentSearch::search()'s own doc comment.
        var possibleGenders = getPossibleGenders(nodeId);
        var allowedGendersParam = '';
        var isFiltering = false;
        if (possibleGenders) {
            var allowed = ['M', 'F', 'U'].filter(function (g) { return possibleGenders[g] !== false; });
            isFiltering = allowed.length < 3;
            allowedGendersParam = allowed.join(',');
        }
        if (isFiltering) {
            hiddenNotice.textContent = 'Showing only records with a gender compatible with this position.';
        }

        // Only the latest search may render: the automatic empty-query search on
        // open (or an earlier click) can otherwise answer after a newer one and
        // overwrite its results.
        var latestSearch = 0;

        function doSearch() {
            var thisSearch = ++latestSearch;
            results.textContent = 'Searching…';
            var searchParams = { type: 'search', record: self._record, query: input.value.trim() };
            if (isFiltering) {
                searchParams.allowedGenders = allowedGendersParam;
            }
            self._get(searchParams)
                .then(function (matches) {
                    if (thisSearch !== latestSearch) {
                        return;
                    }
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
                            onLinked(encodeRef(match.record, match.instance), { firstName: match.display });
                        });
                        results.appendChild(row);
                    });
                })
                .catch(function (e) {
                    if (thisSearch !== latestSearch) {
                        return;
                    }
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

    // How often to check whether the REDCap window has been closed. Short enough
    // that the refresh feels immediate, long enough to be negligible work.
    var EDIT_WINDOW_POLL_MS = 500;

    // "Edit in REDCap": opens REDCap's own data-entry form for the linked row in
    // a new window and, once that window closes, re-imports the row onto the node
    // - REDCap's form is the only editor of linked fields. No preview or confirm
    // step: closing the window is the trigger, whether the user saved, cancelled
    // or changed nothing (the re-import is read-only and idempotent).
    //
    // AbstractRecordLinkProvider.openEditor is nodeId-only (no recordRef
    // parameter - see its own header comment / record-link-provider design
    // D2's implementation note): the ref is looked up here via the live
    // node, the same pattern SmartPatientProvider.ts already uses.
    //
    // Everything up to window.open() is synchronous: this runs inside the node
    // menu button's click handler, and a window.open() after any async hop would
    // lose the user gesture and be popup-blocked.
    RedcapInstrumentPatientProvider.prototype.openEditor = function (nodeId, onDone) {
        if (!this._requireExistingRecord()) {
            return;
        }
        var node = window.editor && window.editor.getView().getNode(nodeId);
        var ref = decodeRef(node && node.getLinkedRecordRef && node.getLinkedRecordRef());
        if (!ref) {
            showMessage('Edit in REDCap', 'Not a REDCap instrument reference.');
            return;
        }
        // Same rule as the picker: a family's person rows live on its own record,
        // so a link to another record's row (e.g. from an imported pedigree file)
        // is refused rather than opened.
        if (ref.record !== this._record) {
            showMessage('Edit in REDCap', 'This person is linked to a row on REDCap record "' + ref.record
                + '", not this record. Only rows on this record can be used. If this record has been '
                + 'renamed since the link was made, or the pedigree was imported from elsewhere, link the '
                + 'person again to one of this record\'s rows.');
            return;
        }
        var url = this._editUrlFor(ref.instance);
        if (!url) {
            showMessage('Edit in REDCap', 'Editing in REDCap isn\'t available for this project '
                + '(the linked instrument isn\'t set up as repeating in this record\'s arm).');
            return;
        }

        var editWindow = window.open(url, '_blank');
        if (!editWindow) {
            showMessage('Edit in REDCap', 'Your browser blocked the REDCap window. '
                + 'Allow pop-ups for this site, then try again.');
            return;
        }

        var self = this;
        var timer = setInterval(function () {
            if (!editWindow.closed) {
                return;
            }
            clearInterval(timer);
            self._get({ type: 'import', record: ref.record, currentRecord: self._record, instance: ref.instance })
                .then(function (answers) {
                    onDone(answers || []);
                })
                .catch(function (e) {
                    showMessage('Edit in REDCap', 'Couldn\'t refresh this person from REDCap: '
                        + String(e && e.message || e));
                });
        }, EDIT_WINDOW_POLL_MS);
    };

    // The row's data-entry URL: the server-built template (pedigreeEditUrl,
    // everything but the instance) plus &instance=N. Only ever same-origin
    // http(s) - the template arrives via this window's own query string, so a
    // crafted link must not be able to turn it into e.g. a javascript: URL.
    RedcapInstrumentPatientProvider.prototype._editUrlFor = function (instance) {
        if (!this._editUrl) {
            return null;
        }
        var url;
        try {
            url = new URL(this._editUrl, window.location.href);
        } catch (e) {
            return null;
        }
        if (url.origin !== window.location.origin || (url.protocol !== 'http:' && url.protocol !== 'https:')) {
            return null;
        }
        url.searchParams.set('instance', String(instance));
        return url.toString();
    };

    // Not supported yet - canCreateNew() always returns false, so the host
    // never offers a "Create new" action that would reach this method.
    RedcapInstrumentPatientProvider.prototype.createNew = function (nodeId, onCreated) {
        if (!this._requireExistingRecord()) {
            return;
        }
        console.warn('RedcapInstrumentPatientProvider.createNew() is not yet supported');
    };

    global.RedcapInstrumentPatientProvider = RedcapInstrumentPatientProvider;
})(window);
