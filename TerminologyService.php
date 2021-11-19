<?php


$sendErrorResponse = function($error, $error_description){
    if(strpos($_SERVER['HTTP_ACCEPT'], 'text/html') === 0){
        echo "A browser was detected.  The OperationOutcome will be prefixed with a human readable version of the error details:\n\n$error\n\n$error_description\n\n";
    }
    $errorArr = ['error' => $error, 'error_description' => $error_description];
    header('Content-type: application/json');
    http_response_code(400);
    echo json_encode($errorArr, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    exit();
};

$method = $_SERVER['REQUEST_METHOD'];
if ('GET' === $method){
    $params = $_GET;
}
elseif ('POST' === $method){
    $params = $_POST;
}
else {
    $sendErrorResponse('Invalid Method', 'Request method must be GET or POST');
}

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