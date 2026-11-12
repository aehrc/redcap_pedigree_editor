/**
 * Populates window.pedigreeEditorEM from data-* attributes on this script's own
 * tag, rather than PHP-interpolating values directly into an inline <script>
 * body (see pedigree-editor-trunk-based-workflow-migration's
 * pedigree-editor-inline-js-extraction capability). Values are the same ones
 * PedigreeEditorExternalModule::add_pedigree_to_form() previously emitted
 * inline.
 */
(function () {
    // document.currentScript is this file's own <script> element while it is
    // executing synchronously (this file is loaded without `defer`/`async`,
    // matching pedigreeEditorEM.js's own loading, so this holds).
    var scriptEl = document.currentScript;

    var pedigreeEditorEM = window.pedigreeEditorEM = window.pedigreeEditorEM || {};

    pedigreeEditorEM.fieldsOfInterest = JSON.parse(scriptEl.dataset.fieldsOfInterest);
    pedigreeEditorEM.hpoEditorPage = scriptEl.dataset.hpoEditorPage;
    pedigreeEditorEM.sctEditorPage = scriptEl.dataset.sctEditorPage;
    pedigreeEditorEM.customEditorPage = scriptEl.dataset.customEditorPage;
    pedigreeEditorEM.emptyIcon = '#__pedigree_empty_svg';
    pedigreeEditorEM.dataIcon = '#__pedigree_with_data_svg';
    // Set by pedigreeEditorEM.js: this page's own editor window name, and the
    // current handoff with that window (pedigree-editor-window-transport).
    pedigreeEditorEM.windowName = null;
    pedigreeEditorEM.handoff = null;
    pedigreeEditorEM.format = scriptEl.dataset.format;
    pedigreeEditorEM.allowEdit = scriptEl.dataset.allowEdit;
})();
