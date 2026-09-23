<?php
/**
 * AJAX endpoint backing `RedcapInstrumentPatientProvider` (task 6.2):
 * search/import against the project's configured repeating instrument.
 *
 * Deliberately NOT listed in config.json's `no-auth-pages` — REDCap
 * authenticates the session for this page like any other module page.
 * GET-only, deliberately: every action here only reads data, never writes,
 * and REDCap only requires a `redcap_csrf_token` for POST requests to
 * module pages - staying GET-only means this endpoint needs no CSRF token
 * at all (avoiding the need to hand one to the client, which would
 * otherwise end up in server access logs and browser history via the URL).
 */

$sendErrorResponse = function ($error, $error_description) {
    $errorArr = ['error' => $error, 'error_description' => $error_description];
    header('Content-type: application/json');
    http_response_code(400);
    echo json_encode($errorArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
};

if ('GET' !== $_SERVER['REQUEST_METHOD']) {
    $sendErrorResponse('Invalid Method', 'Request method must be GET');
}
$params = $_GET;

if (!isset($params['type'])) {
    $sendErrorResponse('Invalid Request', 'Missing required parameter "type".');
}
if (!isset($_GET['pid'])) {
    $sendErrorResponse('Invalid Request', 'Missing required project context ("pid").');
}
$project_id = (int) $_GET['pid'];

// A required, non-empty, scalar query parameter, as a string (rejects `name[]=` arrays
// too). Scalar rather than string-only: REDCap core casts some GET params before this
// page runs - `instance` arrives as an int.
$requireString = function ($name, $action) use ($params, $sendErrorResponse) {
    if (!isset($params[$name]) || !is_scalar($params[$name]) || (string) $params[$name] === '') {
        $sendErrorResponse('Invalid Request', 'Missing required parameter "' . $name . '" for ' . $action . ' action.');
    }
    return (string) $params[$name];
};

header('Content-type: application/json');

if ('questionnaire' === $params['type']) {
    $questionnaire = $module->getPedigreeDerivedQuestionnaire($project_id);
    if ($questionnaire === null) {
        $sendErrorResponse('Not Configured', 'No repeating instrument is configured for pedigree import on this project.');
    }
    echo json_encode($questionnaire, JSON_UNESCAPED_SLASHES);
} elseif ('search' === $params['type']) {
    // Required, never defaulted to "all records" - see searchPedigreeInstrumentRows().
    $record = $requireString('record', 'search');
    $query = $params['query'] ?? '';
    // Comma-separated allowed gender codes (e.g. "M,U") - see
    // RedcapInstrumentSearch::search()'s $allowedGenders param. Filtered to
    // the only 3 recognized codes so an unexpected value can't be smuggled
    // through to the in_array() comparison downstream.
    $allowedGenders = null;
    if (isset($params['allowedGenders']) && $params['allowedGenders'] !== '') {
        $allowedGenders = array_values(array_intersect(
            explode(',', $params['allowedGenders']),
            ['M', 'F', 'U']
        ));
    }
    echo json_encode($module->searchPedigreeInstrumentRows($project_id, $record, $query, $allowedGenders), JSON_UNESCAPED_SLASHES);
} elseif ('import' === $params['type']) {
    $record = $requireString('record', 'import');
    $instance = $requireString('instance', 'import');
    // Same rule as search: only rows on the record the editor was opened from. A
    // consistency check on what the editor asks for, NOT access control - both values
    // come from the client, and the user can already read any row in their DAG
    // through REDCap itself. The real enforcement is RedcapInstrumentPatientProvider
    // refusing cross-record links before asking.
    if ($requireString('currentRecord', 'import') !== $record) {
        $sendErrorResponse('Invalid Request', 'Import is only allowed from a row on the current record ("currentRecord").');
    }
    echo json_encode(
        $module->getPedigreeInstrumentRowAnswers($project_id, $record, $instance),
        JSON_UNESCAPED_SLASHES
    );
} else {
    $sendErrorResponse('Invalid Request', 'Invalid "type" parameter "' . $params['type'] . '".');
}

exit();
