<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * Best-effort translation of REDCap `branching_logic` into FHIR Questionnaire
 * `enableWhen` conditions.
 *
 * Only single or AND-chained simple comparisons (`[field] op 'value'`)
 * against other `@PEDIGREE_FIELD`-tagged fields in the same instrument are
 * translated. Anything else (OR chains, cross-instrument references,
 * checkbox-option syntax like `[chk(1)]`, nested parentheses) is left
 * untranslated — the caller should derive the field with no `enableWhen`
 * and log the returned warning.
 */
class BranchingLogicTranslator
{
    const COMPARISON_PATTERN = '/^\s*\[([a-zA-Z][a-zA-Z0-9_]*)\]\s*(<>|>=|<=|=|>|<)\s*(?:\'([^\']*)\'|([^\s]+))\s*$/';

    const OPERATOR_MAP = [
        '=' => '=',
        '<>' => '!=',
        '>' => '>',
        '<' => '<',
        '>=' => '>=',
        '<=' => '<=',
    ];

    /**
     * @param string $branchingLogic Raw REDCap `branching_logic` string.
     * @param array $taggedItemTypes Map of tagged-field-name => derived
     *   Questionnaire item type (from {@see QuestionnaireDerivation}), used
     *   both to validate that a referenced field is itself derivable and to
     *   pick the correct FHIR `answer[x]` field.
     * @param array $mappedFieldNames Field names successfully mapped via
     *   `mapsTo`/`legend` (redcap field name => true) — referencing one of
     *   these is also untranslatable (see {@see QuestionnaireDerivation::applyBranchingLogicAndPredicates()}
     *   for why: a mapped field's value isn't tracked under its linkId in
     *   open-pedigree's generic enableWhen-answer map).
     * @return array{enableWhen: array|null, warning: string|null}
     */
    public static function translate(string $branchingLogic, array $taggedItemTypes, array $mappedFieldNames = []): array
    {
        $trimmed = trim($branchingLogic);
        if ($trimmed === '') {
            return ['enableWhen' => null, 'warning' => null];
        }

        // Quoted comparison values are opaque data, not syntax - a value that is or
        // contains the word "and"/"or" (e.g. [status] = 'and'), or contains a literal
        // parenthesis (e.g. [opt] = '(pending)'), must not be mistaken for the
        // corresponding logic construct. Mask quoted literals out before any of the
        // structural checks/splitting below, then restore them per-comparison.
        $literals = [];
        $masked = self::maskQuotedLiterals($trimmed, $literals);

        if (preg_match('/\(|\)/', $masked)) {
            return self::untranslatable($branchingLogic, 'contains parentheses (nested grouping is not supported)');
        }
        if (preg_match('/\bor\b/i', $masked)) {
            return self::untranslatable($branchingLogic, 'contains an OR chain (only AND-chains are supported)');
        }

        $maskedComparisons = preg_split('/\band\b/i', $masked);
        $enableWhen = [];

        foreach ($maskedComparisons as $maskedComparison) {
            $comparison = self::unmaskQuotedLiterals($maskedComparison, $literals);
            if (!preg_match(self::COMPARISON_PATTERN, $comparison, $m)) {
                return self::untranslatable($branchingLogic, 'contains an unsupported comparison "' . trim($comparison) . '"');
            }

            $fieldName = $m[1];
            $operator = self::OPERATOR_MAP[$m[2]];
            $rawValue = $m[3] !== '' ? $m[3] : $m[4];

            if (!array_key_exists($fieldName, $taggedItemTypes)) {
                return self::untranslatable($branchingLogic, 'references field "' . $fieldName . '" which is not an @PEDIGREE_FIELD-tagged field in this instrument');
            }
            if (isset($mappedFieldNames[$fieldName])) {
                return self::untranslatable($branchingLogic, 'references field "' . $fieldName . '" which is mapped via mapsTo/legend — its value is not visible to enableWhen');
            }

            $enableWhen[] = self::buildCondition($fieldName, $operator, $rawValue, $taggedItemTypes[$fieldName]);
        }

        return ['enableWhen' => $enableWhen, 'warning' => null];
    }

    private static function buildCondition(string $fieldName, string $operator, string $rawValue, string $itemType): array
    {
        $condition = ['question' => $fieldName, 'operator' => $operator];

        switch ($itemType) {
            case 'boolean':
                $condition['answerBoolean'] = in_array(strtolower($rawValue), ['1', 'true', 'yes'], true);
                break;
            case 'integer':
                $condition['answerInteger'] = (int) $rawValue;
                break;
            case 'decimal':
                $condition['answerDecimal'] = (float) $rawValue;
                break;
            case 'date':
                $condition['answerDate'] = $rawValue;
                break;
            case 'choice':
                $condition['answerCoding'] = ['code' => $rawValue];
                break;
            default:
                $condition['answerString'] = $rawValue;
                break;
        }

        return $condition;
    }

    /**
     * Replaces every `'...'`-quoted literal with a NUL-delimited placeholder
     * (one that cannot appear in REDCap branching_logic source), appending
     * each original literal to $literals in the order encountered so
     * {@see unmaskQuotedLiterals()} can restore them positionally.
     */
    private static function maskQuotedLiterals(string $s, array &$literals): string
    {
        return preg_replace_callback("/'[^']*'/", function ($m) use (&$literals) {
            $literals[] = $m[0];
            return "\x00" . (count($literals) - 1) . "\x00";
        }, $s);
    }

    private static function unmaskQuotedLiterals(string $s, array $literals): string
    {
        return preg_replace_callback('/\x00(\d+)\x00/', function ($m) use ($literals) {
            return $literals[(int) $m[1]];
        }, $s);
    }

    private static function untranslatable(string $branchingLogic, string $reason): array
    {
        return [
            'enableWhen' => null,
            'warning' => 'branching_logic "' . $branchingLogic . '" ' . $reason . ' — deriving field with no enableWhen (always visible)',
        ];
    }
}
