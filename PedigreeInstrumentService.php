<?php
/**
 * AJAX endpoint backing `RedcapInstrumentPatientProvider` (task 6.2):
 * search/import against the project's configured repeating instrument, all of
 * a record's linked rows at once (import-rows), the instance number for a
 * new row (nextInstance), and whether this user has saved that new row since
 * (rowSaved).
 *
 * Deliberately NOT listed in config.json's `no-auth-pages` — REDCap
 * authenticates the session for this page like any other module page.
 * GET-only, deliberately: every action here only reads data, never writes,
 * and REDCap only requires a `redcap_csrf_token` for POST requests to
 * module pages - staying GET-only means this endpoint needs no CSRF token
 * at all (avoiding the need to hand one to the client, which would
 * otherwise end up in server access logs and browser history via the URL).
 */

$sendErrorResponse = function ($error, $error_description, $status = 400) {
    $errorArr = ['error' => $error, 'error_description' => $error_description];
    header('Content-type: application/json');
    http_response_code($status);
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
// page runs. `instance` in particular is always forced to an int >= 1 by core
// (System.php), so for it this check can't fail - a missing/garbage instance means 1.
$requireString = function ($name, $action) use ($params, $sendErrorResponse) {
    if (!isset($params[$name]) || !is_scalar($params[$name]) || (string) $params[$name] === '') {
        $sendErrorResponse('Invalid Request', 'Missing required parameter "' . $name . '" for ' . $action . ' action.');
    }
    return (string) $params[$name];
};

// The event to read the instrument's rows from, picked from the event of the form the editor was
// opened from (`formEvent`, from pedigreeEvent - see resolvePedigreeInstrumentEvent()). Not
// `event_id`, which REDCap core itself reads on module pages. Absent/empty means unknown: fine while
// the instrument repeats in just one event of the project, e.g. any classic project. If no event can
// be picked, say why rather than returning no rows.
$resolveEventId = function () use ($params, $module, $project_id, $sendErrorResponse) {
    $raw = $params['formEvent'] ?? '';
    if (!is_string($raw) || ($raw !== '' && !ctype_digit($raw))) {
        $sendErrorResponse('Invalid Request', 'Parameter "formEvent" must be an event ID.');
    }
    $resolved = $module->resolvePedigreeInstrumentEvent($project_id, $raw === '' ? null : (int) $raw);
    if ($resolved['eventId'] === null) {
        $sendErrorResponse('Not Configured', $resolved['problem']);
    }
    return $resolved['eventId'];
};

// Sends a RedcapInstrumentRowsResponse answer for a read. A server-side error (a failed read, a
// Data Access Group refusal, the opening check finding the record gone) logs the read's reason, as
// the editor only shows that it failed. A row with text that isn't valid UTF-8 (e.g. from an old
// import) mustn't empty the whole answer, so it's substituted.
$respond = function (array $read, array $response) use ($sendErrorResponse, $project_id) {
    if ($response['log'] && $read['reason'] !== null) {
        error_log('Pedigree Editor (project ' . $project_id . '): ' . $read['reason']);
    }
    if ($response['status'] >= 400) {
        $sendErrorResponse($response['body']['error'], $response['body']['error_description'], $response['status']);
    }
    // (any other encoding failure throws to the endpoint's catch: a JSON 500, not an empty 200)
    echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
};

header('Content-type: application/json');

// Whatever an action throws (a failed read of REDCap's data, metadata, the module log or settings)
// is answered as a JSON 500, so the editor can show it, rather than REDCap's HTML error page. Its
// message isn't logged: it may quote the record (e.g. in SQL), which may identify someone.
try {
    if ('questionnaire' === $params['type']) {
        $questionnaire = $module->getPedigreeDerivedQuestionnaire($project_id);
        if ($questionnaire === null) {
            $sendErrorResponse('Not Configured', 'No repeating instrument is configured for pedigree import on this project.');
        }
        echo json_encode($questionnaire, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    } elseif ('search' === $params['type']) {
        // Required, never defaulted to "all records" - see searchPedigreeInstrumentRows().
        $record = $requireString('record', 'search');
        $eventId = $resolveEventId();
        // Optional params: an array (`query[]=`) is treated as absent rather than cast.
        $query = isset($params['query']) && is_string($params['query']) ? $params['query'] : '';
        // Comma-separated allowed gender codes (e.g. "M,U") - see
        // RedcapInstrumentSearch::search()'s $allowedGenders param. Filtered to
        // the only 3 recognized codes so an unexpected value can't be smuggled
        // through to the in_array() comparison downstream.
        $allowedGenders = null;
        if (isset($params['allowedGenders']) && is_string($params['allowedGenders']) && $params['allowedGenders'] !== '') {
            $allowedGenders = array_values(array_intersect(
                explode(',', $params['allowedGenders']),
                ['M', 'F', 'U']
            ));
        }
        echo json_encode($module->searchPedigreeInstrumentRows($project_id, $record, $eventId, $query, $allowedGenders), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    } elseif ('import' === $params['type']) {
        $record = $requireString('record', 'import');
        $instance = $requireString('instance', 'import');
        // Same rule as search: only rows on the record the editor was opened from. A
        // consistency check on what the editor asks for, NOT access control - both values
        // come from the client, and the user can already read any row in their DAG
        // through REDCap itself. The real enforcement is RedcapInstrumentPatientProvider
        // refusing cross-record links before asking.
        // Case-insensitively, as REDCap's database matches record names (and the read finds the
        // record by its stored name).
        if (strcasecmp($requireString('currentRecord', 'import'), $record) !== 0) {
            $sendErrorResponse('Invalid Request', 'Import is only allowed from a row on the current record ("currentRecord").');
        }
        $eventId = $resolveEventId();
        // The same read as import-rows, so the two agree about the row (RedcapInstrumentRowsResponse).
        // requireRow=1 (what RedcapInstrumentPatientProvider always sends): say when the row doesn't
        // exist, rather than answer [] as for a row with nothing tagged to import. Without it (an editor
        // window from before this was added), a missing row still answers [].
        $read = $module->readPedigreeInstrumentRows($project_id, $record, $eventId, (int) $instance);
        $respond($read, \AEHRC\PedigreeEditorExternalModule\RedcapInstrumentRowsResponse::forImport(
            $read, $record, (int) $instance, isset($params['requireRow']) && $params['requireRow'] === '1'
        ));
    } elseif ('import-rows' === $params['type']) {
        // open-pedigree's check on opening a pedigree: every row of this record's linked instrument,
        // keyed by its link ref, each with its answers as `import` shapes them (the same read).
        // RedcapInstrumentPatientProvider answers each ref from this: a ref not among them is a deleted
        // row, or another record's. Anything that would make every row look deleted - nothing set up to
        // import, a failed read, a record gone or outside the user's Data Access Group - is an error
        // instead, so the check says nothing.
        $record = $requireString('currentRecord', 'import-rows');
        $eventId = $resolveEventId();
        $read = $module->readPedigreeInstrumentRows($project_id, $record, $eventId);
        $respond($read, \AEHRC\PedigreeEditorExternalModule\RedcapInstrumentRowsResponse::forImportRows($read));
    } elseif ('nextInstance' === $params['type']) {
        // "Create in REDCap": where a new linked row on this record goes. Still read-only - REDCap
        // itself creates the row, when the user saves it in its own form.
        $record = $requireString('record', 'nextInstance');
        $eventId = $resolveEventId();
        $next = $module->getNextPedigreeInstrumentInstance($project_id, $record, $eventId);
        if ($next['instance'] === null) {
            // A failure is a server fault (500, as import's failed read), a refusal is 400.
            if (!empty($next['failed'])) {
                $sendErrorResponse('Server Error', $next['problem'], 500);
            }
            $sendErrorResponse('Not Available', $next['problem']);
        }
        // savedAfter: where the module log is now, for rowSaved to ask about saves since
        // (pedigree-editor-create-row-save-confirmed).
        echo json_encode(['instance' => $next['instance'], 'savedAfter' => $module->getRowSavedMarker($project_id)]);
    } elseif ('rowSaved' === $params['type']) {
        // "Create in REDCap": has the current user saved this new row since its window opened
        // (savedAfter, from nextInstance)? Answered from the notes the save hook makes once REDCap
        // has stored a save - not from anything the window showed. Read-only, and only about the
        // session's own user.
        $record = $requireString('record', 'rowSaved');
        $instance = $requireString('instance', 'rowSaved');
        if (strcasecmp($requireString('currentRecord', 'rowSaved'), $record) !== 0) {
            $sendErrorResponse('Invalid Request', 'Only a row on the current record ("currentRecord") can be asked about.');
        }
        $savedAfter = $requireString('savedAfter', 'rowSaved');
        if (!ctype_digit($savedAfter)) {
            $sendErrorResponse('Invalid Request', 'Parameter "savedAfter" must be a whole number.');
        }
        $eventId = $resolveEventId();
        echo json_encode(['saved' => $module->wasRowSavedSince($project_id, $record, $eventId, (int) $instance, (int) $savedAfter)]);
    } else {
        $sendErrorResponse('Invalid Request', 'Invalid "type" parameter "' . $params['type'] . '".');
    }
} catch (\Throwable $e) {
    // (By now the type is one of the actions above; reduced to letters and dashes all the same.)
    $action = is_string($params['type']) ? preg_replace('/[^a-zA-Z-]/', '', $params['type']) : '';
    error_log('[redcap_pedigree_editor] PedigreeInstrumentService ' . $action . ' failed (project ' . $project_id . '): '
        . \AEHRC\PedigreeEditorExternalModule\PedigreeEditorExternalModule::describeFailure($e));
    $sendErrorResponse('Server Error', 'Something went wrong in the Pedigree Editor while talking to REDCap. Try again.', 500);
}

exit();
