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
    pedigreeEditorEM.windowName = 'pedigreeEditor';
    pedigreeEditorEM.editorWindow = null;
    pedigreeEditorEM.format = scriptEl.dataset.format;
    pedigreeEditorEM.allowEdit = scriptEl.dataset.allowEdit;
    pedigreeEditorEM.transportType = scriptEl.dataset.transportType;

    if (pedigreeEditorEM.transportType === 'message') {
        pedigreeEditorEM.sendWhenReady = false;
        pedigreeEditorEM.messageData = null;
        pedigreeEditorEM.editorPageOrigin = scriptEl.dataset.editorPageOrigin;
    } else {
        pedigreeEditorEM.openPedigreeDataKey = 'pedigreeData';
    }
})();
