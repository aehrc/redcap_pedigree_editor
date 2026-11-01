<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * What `PedigreeInstrumentService.php` answers for a read of a record's linked rows
 * ({@see PedigreeEditorExternalModule::readPedigreeInstrumentRows()}), for each endpoint that
 * reads them: `type=import` (one row - linking, Edit in REDCap, Create in REDCap) and
 * `type=import-rows` (every row - the check on opening a pedigree). One read, mapped two ways
 * (pedigree-editor-single-row-import-via-batch design D2), so the two can't disagree about a row.
 *
 * Pure/testable: a read result in, `['status' => HTTP status, 'body' => array]` out. The service
 * logs the read's `reason` when it answers a server-side error.
 */
class RedcapInstrumentRowsResponse
{
    /** The rows were read: `rows` holds them, keyed by link ref. */
    const OK = 'ok';
    /** The rows can't be read for want of setup: no instrument, or one no longer in the project. (ADVANCED mode without a Questionnaire is OK, every row answering no values.) */
    const NOT_CONFIGURED = 'notConfigured';
    /** The record no longer exists (deleted, or renamed, since the editor was opened). */
    const RECORD_GONE = 'recordGone';
    /** The record isn't in the user's Data Access Group. */
    const NOT_IN_GROUP = 'notInGroup';
    /** The rows couldn't be read. */
    const READ_FAILED = 'readFailed';

    /**
     * `type=import`: one row's answers. A row that isn't there - including every row of a record
     * that's gone - is `Not Found` when asked with requireRow (RedcapInstrumentPatientProvider always
     * is), and `[]` otherwise (editor windows from before requireRow). `[]` with requireRow always
     * means the row exists (with nothing to import - the editor explains that itself), so rows that
     * can't be read for want of setup, a Data Access Group refusal and a failed read each say so,
     * rather than reading as an empty or a deleted row.
     *
     * @param array $read A read result ({@see PedigreeEditorExternalModule::readPedigreeInstrumentRows()}).
     * @param string $record The record asked for; the row is looked up under the record name as
     *   stored, as the read keys rows, if the read found the record.
     */
    public static function forImport(array $read, string $record, int $instance, bool $requireRow): array
    {
        $ref = RedcapInstrumentReference::encode($read['record'] ?? $record, $instance);
        // The editor puts the message mid-sentence ("Couldn't refresh this person from REDCap: ..."),
        // so it's a lower-case fragment without a full stop.
        switch ($read['status']) {
            case self::NOT_CONFIGURED:
                return self::error(400, 'Not Configured', 'nothing is set up to read the linked instrument\'s rows');
            case self::NOT_IN_GROUP:
                return self::error(403, 'Not Available', 'this record isn\'t in your Data Access Group');
            case self::READ_FAILED:
                return self::error(500, 'Server Error', 'the read failed - the web server\'s error log has the reason');
            case self::OK:
                if (array_key_exists($ref, $read['rows'])) {
                    return self::answer($read['rows'][$ref]);
                }
                break;
        }
        // OK without the row, or RECORD_GONE: the row isn't there.
        return $requireRow ? self::error(404, 'Not Found', 'That row no longer exists in REDCap') : self::answer([]);
    }

    /**
     * `type=import-rows`: every row, keyed by ref. Anything else is an error, so the check on
     * opening says nothing rather than reporting every row as deleted.
     *
     * @param array $read A read result ({@see PedigreeEditorExternalModule::readPedigreeInstrumentRows()}).
     */
    public static function forImportRows(array $read): array
    {
        switch ($read['status']) {
            case self::OK:
                return self::answer((object) $read['rows']);
            case self::NOT_CONFIGURED:
                return self::error(400, 'Not Configured', 'Nothing is set up to import from the linked instrument.');
            default:
                return self::error(500, 'Server Error', 'The linked instrument\'s rows could not be read.');
        }
    }

    private static function answer($body): array
    {
        return ['status' => 200, 'body' => $body];
    }

    private static function error(int $status, string $error, string $description): array
    {
        return ['status' => $status, 'body' => ['error' => $error, 'error_description' => $description]];
    }
}
