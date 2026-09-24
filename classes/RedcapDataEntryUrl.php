<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * Builds the URL of REDCap's own data-entry form for one repeating-instrument
 * row, which "Edit in REDCap" opens in a new window
 * (pedigree-editor-repeating-instrument-sync, task 2.1).
 *
 * Built without `instance`: the pedigree editor window is scoped to one
 * record (a family's person rows live on its own record), so everything but
 * the instance is known when the form page renders, and the client adds the
 * instance of whichever node's row it opens. `DataEntry/index.php` is
 * addressable as `?pid=&page=&id=&event_id=&instance=` (confirmed against
 * REDCap 16.0.32 core).
 */
class RedcapDataEntryUrl
{
    /**
     * @param string $webroot REDCap's version webroot, e.g.
     *   `/redcap/redcap_v16.0.32/` (core's `APP_PATH_WEBROOT`).
     */
    public static function build(string $webroot, int $projectId, string $instrument, string $record, int $eventId): string
    {
        return rtrim($webroot, '/') . '/DataEntry/index.php?' . http_build_query([
            'pid' => $projectId,
            'page' => $instrument,
            'id' => $record,
            'event_id' => $eventId,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
