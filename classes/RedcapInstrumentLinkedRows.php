<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * Answers a batch of linked-row refs (`type=import-rows` in
 * `PedigreeInstrumentService.php`) for open-pedigree's check on opening a
 * pedigree (pedigree-editor-stale-linked-rows): each ref gets its row's
 * answers, shaped exactly as the single-row import shapes them, or says why
 * it can't.
 *
 * Pure/testable: takes the record's already-fetched instrument rows (the
 * {@see RedcapInstrumentGateway::fetchInstrumentRows()} shape) and the
 * resolved fields; no REDCap access here.
 */
class RedcapInstrumentLinkedRows
{
    /**
     * Refs per request. A real pedigree has far fewer linked people (a clinical one is typically
     * 15-40 people, and only some are linked); the bound keeps the GET URL under Apache's ~8 KB
     * request-line limit. RedcapInstrumentPatientProvider splits a larger set into several requests.
     */
    const MAX_REFS = 100;

    /**
     * @param string[] $refs Encoded refs ({@see RedcapInstrumentReference}).
     * @param string $record The record the editor was opened from: only its rows are answered.
     * @param array $rows That record's rows of the instrument ({@see RedcapInstrumentGateway::fetchInstrumentRows()}).
     * @param array $resolvedFields As for {@see RedcapInstrumentRowImporter::buildAnswers()}.
     * @return array ref => `['answers' => [...]]`, `['missing' => true]` (no such instance), or
     *   `['otherRecord' => true]` (another record's row, or a ref that doesn't parse - neither can
     *   be read from this record). Each ref appears once, however often it was asked for.
     */
    public static function answer(array $refs, string $record, array $rows, array $resolvedFields): array
    {
        $byInstance = [];
        foreach ($rows as $row) {
            if ($row['record'] === $record) {
                $byInstance[$row['instance']] = $row;
            }
        }

        $result = [];
        foreach ($refs as $ref) {
            $ref = (string) $ref;
            if (array_key_exists($ref, $result)) {
                continue;
            }
            $decoded = RedcapInstrumentReference::decode($ref);
            if ($decoded === null || $decoded['record'] !== $record) {
                $result[$ref] = ['otherRecord' => true];
            } elseif (!isset($byInstance[$decoded['instance']])) {
                $result[$ref] = ['missing' => true];
            } else {
                $result[$ref] = ['answers' => RedcapInstrumentRowImporter::buildAnswers($byInstance[$decoded['instance']]['fields'], $resolvedFields)];
            }
        }
        return $result;
    }

    /**
     * The `refs` query parameter, as a list of strings, or null if it isn't a JSON array of
     * strings of at most {@see self::MAX_REFS} entries.
     *
     * @param mixed $raw
     * @return string[]|null
     */
    public static function parseRefs($raw): ?array
    {
        if (!is_string($raw)) {
            return null;
        }
        $refs = json_decode($raw, true);
        if (!is_array($refs) || count($refs) > self::MAX_REFS) {
            return null;
        }
        // A list, not an object (array_is_list() is PHP 8.1; this module's floor is 8.0).
        if ($refs !== [] && array_keys($refs) !== range(0, count($refs) - 1)) {
            return null;
        }
        foreach ($refs as $ref) {
            if (!is_string($ref)) {
                return null;
            }
        }
        return $refs;
    }
}
