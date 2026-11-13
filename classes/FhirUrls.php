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
     * The retired URLs a Questionnaire (as JSON) still uses where they are read - extension `url`s
     * and item `definition`s - each with its replacement: [retired => replacement], in the order
     * first found. Free text, the Questionnaire's own canonical, identifier systems and plain links
     * to the repository aren't included.
     */
    public static function retiredUrlsIn(string $json): array
    {
        $questionnaire = json_decode($json, true);
        return is_array($questionnaire) ? self::retiredUrlsInQuestionnaire($questionnaire) : [];
    }

    /** {@see retiredUrlsIn()}, for a Questionnaire already decoded. */
    public static function retiredUrlsInQuestionnaire(array $questionnaire): array
    {
        $found = [];
        self::collectRetired($questionnaire['item'] ?? [], $found);
        self::collectRetired($questionnaire['extension'] ?? [], $found);
        return $found;
    }

    /** Walks items and extensions, noting each retired extension url and item definition. */
    private static function collectRetired($node, array &$found): void
    {
        if (!is_array($node)) {
            return;
        }
        foreach (['url', 'definition'] as $key) {
            $value = $node[$key] ?? null;
            if (is_string($value) && strpos($value, self::RETIRED_BASE) === 0) {
                $retired = explode('#', $value)[0];
                $rest = substr($retired, strlen(self::RETIRED_BASE));
                if (preg_match('#^(questionnaire-[a-z-]+|StructureDefinition/[A-Za-z-]+)$#', $rest)) {
                    $found[$retired] = self::BASE . (strpos($rest, 'questionnaire-') === 0 ? 'StructureDefinition/' : '') . $rest;
                }
            }
        }
        foreach ($node as $key => $child) {
            if (is_array($child)) {
                self::collectRetired($child, $found);
            }
        }
    }
}
