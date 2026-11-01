<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * All of a record's linked rows at once (`type=import-rows` in `PedigreeInstrumentService.php`),
 * for open-pedigree's check on opening a pedigree (pedigree-editor-stale-linked-rows): each row's
 * answers, shaped exactly as the single-row import shapes them, keyed by the row's link ref.
 * `RedcapInstrumentPatientProvider` answers the refs it's asked about from this - a ref not among
 * them is a row that no longer exists, or another record's.
 *
 * Pure/testable: takes the record's already-fetched instrument rows (the
 * {@see RedcapInstrumentGateway::fetchInstrumentRows()} shape) and the resolved fields; no REDCap
 * access here.
 */
class RedcapInstrumentLinkedRows
{
    /**
     * @param array $rows The record's rows of the instrument ({@see RedcapInstrumentGateway::fetchInstrumentRows()}).
     * @param array $resolvedFields As for {@see RedcapInstrumentRowImporter::buildAnswers()}.
     * @return array ref ({@see RedcapInstrumentReference::encode()}, with the record name as stored) => answers.
     */
    public static function answerAll(array $rows, array $resolvedFields): array
    {
        $result = [];
        foreach ($rows as $row) {
            $ref = RedcapInstrumentReference::encode((string) $row['record'], (int) $row['instance']);
            $result[$ref] = RedcapInstrumentRowImporter::buildAnswers($row['fields'], $resolvedFields);
        }
        return $result;
    }
}
