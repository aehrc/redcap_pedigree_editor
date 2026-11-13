<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * open-pedigree's FHIR definitions the module writes and reads, in one place. Each URL resolves to
 * a page describing it on open-pedigree's GitHub Pages site (open-pedigree 2.0.0+: its fhirUrls.ts
 * and fhir/ folder hold the same URLs and the definitions). These must match the bundled
 * open-pedigree: the two read each other's Questionnaires and saved pedigrees.
 */
final class FhirUrls
{
    const BASE = 'https://aehrc.github.io/open-pedigree/fhir/';
    const STRUCTURE_DEFINITION = self::BASE . 'StructureDefinition/';

    // On a pedigree person's Patient: their link to a repeating-instrument row.
    const LINKED_RECORD_REF = self::STRUCTURE_DEFINITION . 'linked-record-ref';

    // On Questionnaire items.
    const QUESTIONNAIRE_FIELD_MAPPING = self::STRUCTURE_DEFINITION . 'questionnaire-field-mapping';
    const QUESTIONNAIRE_ENABLE_PREDICATE = self::STRUCTURE_DEFINITION . 'questionnaire-enable-predicate';
    const QUESTIONNAIRE_ACTION = self::STRUCTURE_DEFINITION . 'questionnaire-action';
    const QUESTIONNAIRE_REDCAP_SOURCE = self::STRUCTURE_DEFINITION . 'questionnaire-redcap-source';
    const QUESTIONNAIRE_LINKED_RECORD_SOURCE = self::STRUCTURE_DEFINITION . 'questionnaire-linked-record-source';

    // The logical model an item's definition points at (#PedigreeIndividual.<element>).
    const PEDIGREE_INDIVIDUAL = self::STRUCTURE_DEFINITION . 'PedigreeIndividual';

    // Where these lived before open-pedigree 2.0.0 (never resolvable, no longer read).
    const RETIRED_BASE = 'https://github.com/aehrc/open-pedigree/';

    /**
     * The retired URLs a Questionnaire (as JSON) still uses, each with its replacement:
     * [retired => replacement], in the order first found. Identifier systems and plain links to the
     * repository aren't included.
     */
    public static function retiredUrlsIn(string $json): array
    {
        $found = [];
        // (JSON may escape the slashes)
        $text = str_replace('\\/', '/', $json);
        if (preg_match_all('#https://github\.com/aehrc/open-pedigree/((?:StructureDefinition/|Questionnaire/)[A-Za-z-]+|questionnaire-[a-z-]+)#', $text, $matches)) {
            foreach ($matches[0] as $i => $retired) {
                $rest = $matches[1][$i];
                $found[$retired] = self::BASE . (strpos($rest, 'questionnaire-') === 0 ? 'StructureDefinition/' : '') . $rest;
            }
        }
        return $found;
    }
}
