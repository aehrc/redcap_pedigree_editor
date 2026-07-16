<?php
/**
 * AJAX endpoint backing `RedcapInstrumentPatientProvider` (task 6.2):
 * search/import against the project's configured repeating instrument.
 *
 * Deliberately NOT listed in config.json's `no-auth-pages` — REDCap
 * authenticates the session and enforces the standard `redcap_csrf_token`
 * check automatically for this page, same as any other module page.
 */

$sendErrorResponse = function ($error, $error_description) {
    $errorArr = ['error' => $error, 'error_description' => $error_description];
    header('Content-type: application/json');
    http_response_code(400);
    echo json_encode($errorArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
};

$method = $_SERVER['REQUEST_METHOD'];
$params = ('POST' === $method) ? $_POST : (('GET' === $method) ? $_GET : null);
if ($params === null) {
    $sendErrorResponse('Invalid Method', 'Request method must be GET or POST');
}

if (!isset($params['type'])) {
    $sendErrorResponse('Invalid Request', 'Missing required parameter "type".');
}
if (!isset($_GET['pid'])) {
    $sendErrorResponse('Invalid Request', 'Missing required project context ("pid").');
}
$project_id = (int) $_GET['pid'];

header('Content-type: application/json');

if ('questionnaire' === $params['type']) {
    $questionnaire = $module->getPedigreeDerivedQuestionnaire($project_id);
    if ($questionnaire === null) {
        $sendErrorResponse('Not Configured', 'No repeating instrument is configured for pedigree import on this project.');
    }
    echo json_encode($questionnaire, JSON_UNESCAPED_SLASHES);
} elseif ('search' === $params['type']) {
    $query = $params['query'] ?? '';
    echo json_encode($module->searchPedigreeInstrumentRows($project_id, $query), JSON_UNESCAPED_SLASHES);
} elseif ('import' === $params['type']) {
    if (!isset($params['record']) || !isset($params['instance'])) {
        $sendErrorResponse('Invalid Request', 'Missing required parameter "record" or "instance" for import action.');
    }
    echo json_encode(
        $module->getPedigreeInstrumentRowAnswers($project_id, $params['record'], $params['instance']),
        JSON_UNESCAPED_SLASHES
    );
} elseif ('lookup' === $params['type']) {
    if (!isset($params['record']) || !isset($params['instance'])) {
        $sendErrorResponse('Invalid Request', 'Missing required parameter "record" or "instance" for lookup action.');
    }
    $displayName = $module->getPedigreeInstrumentRowDisplayName($project_id, $params['record'], $params['instance']);
    echo json_encode(['displayName' => $displayName], JSON_UNESCAPED_SLASHES);
} else {
    $sendErrorResponse('Invalid Request', 'Invalid "type" parameter "' . $params['type'] . '".');
}

exit();
