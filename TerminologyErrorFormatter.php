<?php

/**
 * Builds the human-readable error message shown to browser (Accept: text/html)
 * clients ahead of the JSON OperationOutcome. $error/$error_description can
 * include request-derived content (e.g. raw request params echoed back for a
 * "missing required parameter" error), so both must be HTML-escaped before
 * this un-Content-Type'd, pre-JSON-header echo - otherwise it's a reflected-XSS
 * sink (Psalm's taint analysis doesn't track this pattern when the caller is a
 * variable-stored closure invoked by call - see pedigree-editor-trunk-based-
 * workflow-migration task 3.6 for how this was actually found).
 */
function pedigree_editor_format_browser_error_message($error, $error_description) {
    $safeError = htmlspecialchars($error, ENT_QUOTES);
    $safeDescription = htmlspecialchars($error_description, ENT_QUOTES);
    return "A browser was detected.  The OperationOutcome will be prefixed with a human readable version of the error details:\n\n$safeError\n\n$safeDescription\n\n";
}
