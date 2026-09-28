/**
 * Duck-types open-pedigree's `AbstractRecordLinkProvider` contract against
 * REDCap repeating-instrument rows (originally delivered against
 * `AbstractPatientProvider` by `pedigree-repeating-instrument-import`;
 * re-targeted onto `RecordLinkProvider` by
 * `pedigree-editor-redcap-extension-extraction`), via the module's
 * `PedigreeInstrumentService.php` AJAX endpoint. `pedigree-editor-repeating-
 * instrument-sync` adds: link/edit only once the record exists and only
 * against this record's rows, and "Edit in REDCap" (`openEditor`) opening
 * REDCap's own form for the row and re-importing when that window closes,
 * and "Create in REDCap" (`createNew`) doing the same for a new row, then
 * linking the person to it.
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
        // The event of the form this editor was opened from: the server reads the
        // linked instrument's rows from the one event of that arm it repeats in.
        this._formEvent = options.formEvent || '';
        // REDCap data-entry URL for this record's linked-instrument rows, minus
        // &instance= (PedigreeEditorExternalModule builds it; see _editUrlFor()).
        this._editUrl = options.editUrl || '';
        // One entry per open REDCap window: { window, node, nodeId, ref, onDone, timer }
        // for "Edit in REDCap" (openEditor()), and { window, node, nodeId, ref, create:
        // true, createUrl, saved, onCreated, timer } for "Create in REDCap" (createNew()).
        this._editSessions = [];
        // The "Create in REDCap" session until its row is linked (or not) - see createNew().
        this._createInProgress = null;
        this._focusListenerAdded = false;
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

    // open-pedigree's optional label hook (AbstractRecordLinkProvider.getActionLabel,
    // from its record-link-action-labels change): the edit action opens REDCap's own
    // form, so say so. Bundles older than that change simply don't call this.
    var ACTION_LABELS = { editRecord: 'Edit in REDCap', createNewRecord: 'Create in REDCap' };

    RedcapInstrumentPatientProvider.prototype.getActionLabel = function (action) {
        return ACTION_LABELS[action];
    };

    // Only for a person not linked yet - one who is already has their row.
    RedcapInstrumentPatientProvider.prototype.canCreateNew = function (nodeId) {
        var node = window.editor && window.editor.getView().getNode(nodeId);
        return this._configured && !!node && !node.getLinkedRecordRef();
    };

    // GET, not POST: every action this endpoint supports (search/import/
    // questionnaire/nextInstance) only reads data, never writes - REDCap only
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
                if (!response.ok || (json && json.error)) {
                    var error = new Error((json && (json.error_description || json.error)) || ('HTTP ' + response.status));
                    // The server's error name (e.g. 'Not Found'), for callers that treat one differently.
                    error.code = json && json.error;
                    throw error;
                }
                return json;
            });
        });
    };

    // One linked row's answers, from the server (type=import) - for linking, the
    // "Edit in REDCap" refresh and "Create in REDCap" alike. A row that doesn't exist is
    // an error with code ROW_NOT_FOUND (requireRow - see PedigreeInstrumentService.php),
    // so [] only ever means a row with nothing set up to import.
    RedcapInstrumentPatientProvider.prototype._importRow = function (record, instance) {
        return this._get({
            type: 'import', record: record, currentRecord: this._record, formEvent: this._formEvent,
            instance: instance, requireRow: '1'
        });
    };

    var ROW_NOT_FOUND = 'Not Found';
    function isRowNotFound(e) {
        return !!e && e.code === ROW_NOT_FOUND;
    }

    // Shown when a row answers nothing: nothing is set up to import from it.
    var NOTHING_TO_IMPORT = 'The project\'s pedigree import settings may need checking (no fields tagged '
        + '@PEDIGREE_FIELD or, in Advanced mode, no Questionnaire items linked to this instrument?).';

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
        return { content: content, close: close, isOpen: function () { return !!overlay.parentNode; } };
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
        var modal = createModal(LINK_TITLE);
        // The person, and their link, as the picker opens: anything that changes them before a row
        // is linked (a Create in REDCap window closing, a refresh) is caught, not overwritten.
        var nodeAtOpen = window.editor && window.editor.getView().getNode(nodeId);
        var refAtOpen = nodeAtOpen ? nodeAtOpen.getLinkedRecordRef() : '';

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
            var searchParams = { type: 'search', record: self._record, formEvent: self._formEvent, query: input.value.trim() };
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
                            self._linkPickedRow(modal, { nodeId: nodeId, node: nodeAtOpen, ref: refAtOpen }, match, onLinked);
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

    var LINK_TITLE = 'Link to a family member record';

    // Links the person to the row picked in the picker, bringing the row's values
    // with it (onLinked's third argument, from open-pedigree 1.4.0; older bundles
    // ignore it and only store the ref). The picker stays open, showing
    // "Linking…", while the row is fetched; closing it meanwhile cancels the link,
    // and so does a failed fetch. `target` is the person as the picker opened
    // ({ nodeId, node, ref }): if they've changed since, nothing is linked over them.
    RedcapInstrumentPatientProvider.prototype._linkPickedRow = function (modal, target, match, onLinked) {
        var ref = encodeRef(match.record, match.instance);
        var details = { firstName: match.display };
        modal.content.textContent = 'Linking…';
        var self = this;
        // then(ok, failed), so an error from onLinked itself isn't reported as a failed
        // fetch - the final catch reports it.
        this._importRow(match.record, match.instance).then(function (answers) {
            if (!modal.isOpen()) {
                return;
            }
            modal.close();
            if (!target.node || !self._stillTargets(target)) {
                showMessage(LINK_TITLE, 'This person wasn\'t linked: the pedigree changed while the picker was '
                    + 'open (this person was moved, linked or deleted). Try again.');
                return;
            }
            if (Array.isArray(answers) && answers.length > 0) {
                onLinked(ref, details, answers);
                return;
            }
            // The row exists but answers nothing. A new link still applies the empty list, so the
            // previous row's values don't linger; re-picking the same row changes nothing.
            if (target.ref !== ref) {
                onLinked(ref, details, []);
            }
            showMessage(LINK_TITLE, 'This person is linked to the row, but none of its values could be brought '
                + 'in. ' + NOTHING_TO_IMPORT);
        }, function (e) {
            if (!modal.isOpen()) {
                return;
            }
            modal.close();
            showMessage(LINK_TITLE, isRowNotFound(e)
                ? 'This person wasn\'t linked: that row no longer exists in REDCap (it may have been deleted since '
                    + 'the search).'
                : 'This person wasn\'t linked: the row couldn\'t be read from REDCap (' + String(e && e.message || e)
                    + '). Try again.');
        }).catch(function (e) {
            console.error('Linking to ' + ref + ' failed', e);
            showMessage(LINK_TITLE, 'Something went wrong linking this person: ' + String(e && e.message || e));
        });
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

        // Keyed by the node object and the row, not the nodeId: open-pedigree
        // renumbers node IDs when a node is deleted, and a re-linked person's
        // open window shows the old row.
        var refAtOpen = node.getLinkedRecordRef();
        var existing = this._findEditSession(node, refAtOpen);
        if (existing) {
            existing.window.focus();
            return;
        }

        var editWindow = window.open(url, '_blank');
        // Some blockers return null, others a window that is already closed.
        if (!editWindow || editWindow.closed) {
            showMessage('Edit in REDCap', 'Your browser blocked the REDCap window. '
                + 'Allow pop-ups for this site, then try again.');
            return;
        }

        var session = { window: editWindow, node: node, nodeId: nodeId, ref: refAtOpen, onDone: onDone, timer: null };
        this._editSessions.push(session);
        this._listenForEditorFocus();
        this._watchEditWindow(session);
    };

    // Waits for the session's REDCap window to close - closing it for the user once
    // they've saved and left the form - then finishes the session: a refresh for
    // "Edit in REDCap", linking the new row for "Create in REDCap".
    RedcapInstrumentPatientProvider.prototype._watchEditWindow = function (session) {
        var self = this;
        session.timer = setInterval(function () {
            if (session.create && !session.window.closed && self._landedAfterSave(session.window)) {
                session.saved = true;
            }
            // Saved and done: close it for the user (closed is true straight away, so the
            // session finishes in this same tick).
            if (!session.window.closed && self._savedAndExited(session.window)) {
                session.window.close();
            }
            if (!session.window.closed) {
                return;
            }
            self._endSession(session);
            if (session.create) {
                self._linkCreatedRow(session);
            } else {
                self._refreshFromRedcap(session, true);
            }
        }, EDIT_WINDOW_POLL_MS);
    };

    RedcapInstrumentPatientProvider.prototype._endSession = function (session) {
        clearInterval(session.timer);
        this._editSessions = this._editSessions.filter(function (s) { return s !== session; });
    };

    // Whether the REDCap window has just saved this record and left the form
    // (pedigree-editor-repeating-instrument-sync group 3). A clean "Save & Exit
    // Form" lands on Record Home with id=<record>&msg=edit, "Save & Exit Record"
    // with edit_id=<record>&msg=edit, and msg=add for a record's first save
    // (DataEntry/index.php:520-569, REDCap 16.0.32 - tied to those redirects; the
    // e2e suite checks them). A save with validation problems goes
    // back to the form instead, as do "Save & Stay" and "Save & Go to Next Form" /
    // "Add New Instance": the user is still working. "Save & Go to Next Record"
    // lands on Record Home too, but with both edit_id and id (the next record), and
    // a save of another record the user moved to in the window names that record -
    // neither closes the window. Checked here rather than by a script in REDCap's
    // page, so it only happens while this editor is still there to refresh.
    // The URL the window's page was loaded with, or null (another origin, or not
    // loaded yet). Not location.href: Record Home drops msg= from the address bar
    // straight after loading (modifyURL() in Classes/DataEntry.php), which a poll
    // could otherwise see first.
    function landedUrl(editWindow) {
        try {
            var navigation = editWindow.performance.getEntriesByType('navigation')[0];
            return new URL(navigation ? navigation.name : editWindow.location.href);
        } catch (e) {
            return null;
        }
    }

    // Whether the window has landed on a page REDCap shows after a save: msg=edit
    // (or add/draft-preview) on Record Home ("Save & Exit") or on the form itself
    // ("Save & Stay"). "Create in REDCap" links a new row only after seeing this,
    // so a row someone else saved at the same instance number isn't taken for it.
    RedcapInstrumentPatientProvider.prototype._landedAfterSave = function (editWindow) {
        var landed = landedUrl(editWindow);
        return !!landed && landed.origin === window.location.origin
            && SAVED_AND_EXITED_MESSAGES.indexOf(landed.searchParams.get('msg')) !== -1;
    };

    // Record Home's msg= after a clean save: an edit, a record's first save, or any
    // save while the project is in Draft Preview. Not __rename_failed__ - the user
    // should see why.
    var SAVED_AND_EXITED_MESSAGES = ['edit', 'add', 'draft-preview'];

    RedcapInstrumentPatientProvider.prototype._savedAndExited = function (editWindow) {
        var landed = landedUrl(editWindow);
        if (!landed) {
            return false;
        }
        // The edit window was opened from _editUrlFor(), so the template parses.
        var editUrl = this._parsedEditUrl || (this._parsedEditUrl = new URL(this._editUrl, window.location.href));
        if (landed.origin !== editUrl.origin || landed.pathname.indexOf('/DataEntry/record_home.php') === -1) {
            return false;
        }
        var params = landed.searchParams;
        if (SAVED_AND_EXITED_MESSAGES.indexOf(params.get('msg')) === -1
            || params.get('pid') !== editUrl.searchParams.get('pid')) {
            return false;
        }
        var saved = params.has('edit_id') ? (params.has('id') ? null : params.get('edit_id')) : params.get('id');
        return saved === this._record;
    };

    RedcapInstrumentPatientProvider.prototype._findEditSession = function (node, ref) {
        for (var i = 0; i < this._editSessions.length; i++) {
            var s = this._editSessions[i];
            if (!s.create && s.node === node && s.ref === ref && !s.window.closed) {
                return s;
            }
        }
        return null;
    };

    // Secondary trigger (design.md): coming back to the pedigree editor while a
    // REDCap window is still open (e.g. after "Save & Stay" there) refreshes too,
    // so saving the pedigree right afterwards doesn't store stale values. Quiet -
    // messages are left to the final refresh on close.
    RedcapInstrumentPatientProvider.prototype._listenForEditorFocus = function () {
        if (this._focusListenerAdded) {
            return;
        }
        this._focusListenerAdded = true;
        var self = this;
        window.addEventListener('focus', function () {
            self._editSessions.forEach(function (session) {
                // A new row is linked once, when its window closes.
                if (!session.create && !session.window.closed) {
                    self._refreshFromRedcap(session, false);
                }
            });
        });
    };

    // Whether the session's person is still where onDone will write: open-pedigree's
    // own editRecord handler binds onDone to the click-time nodeId, so the node must
    // still be at that ID (not moved by a deletion elsewhere) and still linked to
    // the same row (not re-linked or unlinked).
    RedcapInstrumentPatientProvider.prototype._stillTargets = function (session) {
        var nodeNow = window.editor && window.editor.getView().getNode(session.nodeId);
        return nodeNow === session.node && nodeNow.getLinkedRecordRef() === session.ref;
    };

    // Re-imports the session's row and applies it. `final` is the window-close
    // refresh, which explains anything it couldn't do; focus refreshes stay quiet.
    RedcapInstrumentPatientProvider.prototype._refreshFromRedcap = function (session, final) {
        var self = this;
        var changedMessage = 'The pedigree changed while the REDCap window was open (this person was '
            + 'moved, re-linked or deleted), so they weren\'t refreshed. Use Edit in REDCap again and '
            + 'close it to refresh them.';
        if (!this._stillTargets(session)) {
            if (final) {
                showMessage('Edit in REDCap', changedMessage);
            }
            return;
        }
        var ref = decodeRef(session.ref);
        this._importRow(ref.record, ref.instance)
            .then(function (answers) {
                // Checked again here: the person may have changed during the fetch.
                if (!self._stillTargets(session)) {
                    if (final) {
                        showMessage('Edit in REDCap', changedMessage);
                    }
                    return;
                }
                if (!answers || answers.length === 0) {
                    if (final) {
                        showMessage('Edit in REDCap', 'Nothing could be imported for this person from REDCap, '
                            + 'so their details here were left unchanged. ' + NOTHING_TO_IMPORT);
                    }
                    return;
                }
                session.onDone(answers);
            })
            .catch(function (e) {
                if (final) {
                    showMessage('Edit in REDCap', isRowNotFound(e)
                        ? 'This person\'s linked row no longer exists in REDCap (it may have been deleted), so '
                            + 'their details here were left unchanged.'
                        : 'Couldn\'t refresh this person from REDCap: ' + String(e && e.message || e));
                }
            });
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

    // "Create in REDCap" (pedigree-editor-repeating-instrument-sync group 4): opens
    // REDCap's own form for a new row of the linked instrument - the next instance
    // on this record - in the same kind of window as "Edit in REDCap". REDCap
    // creates the row when the user saves it; once the window closes, the person
    // is linked to it and its values applied. Closed without saving, nothing
    // changes.
    //
    // The window opens synchronously (popup blockers), blank, and is pointed at the
    // form once the server says which instance is next - checking there, not just
    // here, that the record has been saved. One new row at a time: a second would
    // be given the same instance number while the first is still unsaved.
    RedcapInstrumentPatientProvider.prototype.createNew = function (nodeId, onCreated) {
        if (!this._requireExistingRecord()) {
            return;
        }
        var node = window.editor && window.editor.getView().getNode(nodeId);
        if (!node) {
            return;
        }
        if (!this._editUrl) {
            showMessage('Create in REDCap', 'Adding rows in REDCap isn\'t available for this project '
                + '(the linked instrument isn\'t set up as repeating in this record\'s arm).');
            return;
        }
        // Also while a closed window's row is still being linked - the person may be
        // about to get it.
        if (this._createInProgress) {
            if (!this._createInProgress.window.closed) {
                this._createInProgress.window.focus();
            }
            return;
        }

        var createWindow = window.open('', '_blank');
        if (!createWindow || createWindow.closed) {
            showMessage('Create in REDCap', 'Your browser blocked the REDCap window. '
                + 'Allow pop-ups for this site, then try again.');
            return;
        }
        try {
            createWindow.document.title = 'REDCap';
            createWindow.document.body.textContent = 'Opening REDCap…';
        } catch (e) { /* cosmetic only */ }

        var self = this;
        // ref is set once the instance is known; until then the window can only be closed.
        var session = {
            window: createWindow, node: node, nodeId: nodeId, ref: '', create: true,
            createUrl: '', saved: false, onCreated: onCreated, timer: null
        };
        this._editSessions.push(session);
        this._createInProgress = session;
        this._watchEditWindow(session);
        this._get({ type: 'nextInstance', record: this._record, formEvent: this._formEvent })
            .then(function (json) {
                var url = json && self._editUrlFor(json.instance);
                if (!url) {
                    throw new Error('REDCap didn\'t say where the new row goes.');
                }
                if (createWindow.closed) {
                    return;
                }
                session.ref = encodeRef(self._record, json.instance);
                session.createUrl = url;
                createWindow.location.href = url;
            })
            .catch(function (e) {
                var closedByUser = createWindow.closed;
                self._endSession(session);
                self._createInProgress = null;
                if (closedByUser) {
                    return; // the user already gave up on it
                }
                createWindow.close();
                showMessage('Create in REDCap', 'Couldn\'t open a new row in REDCap: ' + String(e && e.message || e));
            });
    };

    // After a "Create in REDCap" window closes: if it was seen saving the row, link
    // the person to it and apply its values (open-pedigree's onCreated does both).
    RedcapInstrumentPatientProvider.prototype._linkCreatedRow = function (session) {
        var self = this;
        var done = function () {
            self._createInProgress = null;
        };
        if (!session.ref) {
            done();
            return; // closed before the form opened
        }
        var ref = decodeRef(session.ref);
        var notLinked = 'Use Link to existing record to link this person to it.';
        this._importRow(ref.record, ref.instance)
            .then(function (answers) {
                // The row exists (a missing one is ROW_NOT_FOUND, below); it may answer nothing.
                var hasValues = Array.isArray(answers) && answers.length > 0;
                if (!session.saved) {
                    // Closed without saving - yet a row is at this instance, saved some other way
                    // or by someone else, which may not be this person.
                    showMessage('Create in REDCap', 'REDCap has a row at instance ' + ref.instance
                        + ', but it wasn\'t saved from this window, so it may belong to someone else. '
                        + 'If it\'s this person, ' + notLinked.charAt(0).toLowerCase() + notLinked.slice(1));
                    return;
                }
                // As for editing: onCreated writes to the click-time nodeId, so the person must
                // still be there, and still unlinked.
                var nodeNow = window.editor && window.editor.getView().getNode(session.nodeId);
                if (nodeNow !== session.node || nodeNow.getLinkedRecordRef()) {
                    showMessage('Create in REDCap', 'The new REDCap row was saved, but the pedigree changed while '
                        + 'its window was open (this person was moved, linked or deleted), so they weren\'t linked '
                        + 'to it. ' + notLinked);
                    return;
                }
                session.onCreated(session.ref, hasValues ? answers : []);
                if (!hasValues) {
                    showMessage('Create in REDCap', 'This person is now linked to the new REDCap row, but none '
                        + 'of its values could be brought in. ' + NOTHING_TO_IMPORT);
                }
            }, function (e) {
                if (isRowNotFound(e)) {
                    // Nothing at the instance: closed without saving (quiet), or a save that didn't
                    // land where expected.
                    if (session.saved) {
                        showMessage('Create in REDCap', 'The new row wasn\'t found in REDCap at instance '
                            + ref.instance + ' after saving, so this person wasn\'t linked. If it was saved as '
                            + 'another row, ' + notLinked.charAt(0).toLowerCase() + notLinked.slice(1));
                    }
                    return;
                }
                showMessage('Create in REDCap', 'The new row couldn\'t be read back from REDCap, so this person '
                    + 'wasn\'t linked to it: ' + String(e && e.message || e) + '. ' + notLinked);
            })
            .catch(function (e) {
                console.error('Linking the new row ' + session.ref + ' failed', e);
                showMessage('Create in REDCap', 'Something went wrong linking this person to the new REDCap row: '
                    + String(e && e.message || e) + '. Use Link to existing record to link them.');
            })
            .then(done);
    };

    global.RedcapInstrumentPatientProvider = RedcapInstrumentPatientProvider;
})(window);
