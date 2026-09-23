<?php

// Deliberately NOT listed in config.json's `no-auth-pages` — REDCap
// authenticates the session for this page like any other module page.
// GET-only, deliberately: both actions below only read data (proxying a
// lookup/query to the configured FHIR terminology server), never write, and
// REDCap only requires a `redcap_csrf_token` for POST requests to module
// pages - staying GET-only means this endpoint needs no CSRF token at all
// (avoiding the need to hand one to the client, which would otherwise end
// up in server access logs and browser history via the URL).

require_once __DIR__ . '/TerminologyErrorFormatter.php';

$sendErrorResponse = function($error, $error_description){
    if(strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') === 0){
        header('Content-type: text/html');
        http_response_code(400);
        echo pedigree_editor_format_browser_error_message($error, $error_description);
        exit();
    }
    $errorArr = ['error' => $error, 'error_description' => $error_description];
    header('Content-type: application/json');
    http_response_code(400);
    echo json_encode($errorArr, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    exit();
};

if ('GET' !== $_SERVER['REQUEST_METHOD']) {
    $sendErrorResponse('Invalid Method', 'Request method must be GET');
}
$params = $_GET;

//* Lookup
// * type -> 'lookup'
// * system -> code system to be looked up
// * code -> the id to lookup.
// *
// * Query
// * type -> 'query'
// * url -> valueset url
// * filter -> search term
// * count -> number of rows to return

if (!isset($params['type'])){
    $sendErrorResponse('Invalid Request', 'Missing required parameter "type". Params = '.json_encode($params));
} else {
    $type = $params['type'];
    if ('lookup' === $type){
        if (!isset($params['system'])){
            $sendErrorResponse('Invalid Request', 'Missing required parameter "system" for lookup action.');
        }
        $system = $params['system'];
        if (!isset($params['code'])){
            $sendErrorResponse('Invalid Request', 'Missing required parameter "code" for lookup action.');
        }
        $code = $params['code'];
        $module->lookupTerminologyCode($system, $code);
    }
    elseif ('query' === $type){
        if (!isset($params['url'])){
            $sendErrorResponse('Invalid Request', 'Missing required parameter "url" for query action.');
        }
        $valueSet = $params['url'];
        if (!isset($params['filter'])){
            $sendErrorResponse('Invalid Request', 'Missing required parameter "filter" for query action.');
        }
        $filter = $params['filter'];
        $count = (isset($params['count'])) ? $params['count'] : 20;
        $module->queryTerminology($valueSet, $filter, $count);
    }
    else {
        $sendErrorResponse('Invalid Request', 'Invalid "type" parameter "'.$type.'".');
    }
}

exit();