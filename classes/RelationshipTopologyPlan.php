<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * Works out what relationship topology sync writes (pedigree-editor-
 * relationship-topology-sync D6): compares what REDCap holds now with what
 * the saved pedigree says, and returns only the changes. Pure: the rows come
 * from, and the plan goes to, {@see RedcapInstrumentGateway}.
 */
class RelationshipTopologyPlan
{
    /**
     * The value `$field` was just saved with, from `\REDCap::getData()`'s
     * `json-array` rows for the record and the saved form's event. Every row
     * carries every requested field, so the row is picked by what it is:
     * the form's own instance when the form repeats, else the event's
     * instance when the whole event repeats, else the record's plain row.
     *
     * @param int|string|null $instance The saved instance (1 when not repeating).
     */
    public static function savedValue(array $rows, string $instrument, $instance, string $field): ?string
    {
        $instance = (int) ($instance ?: 1);
        $match = function (callable $isRow) use ($rows, $field): ?string {
            foreach ($rows as $row) {
                if (array_key_exists($field, $row) && $isRow($row['redcap_repeat_instrument'] ?? '', (string) ($row['redcap_repeat_instance'] ?? ''))) {
                    return (string) $row[$field];
                }
            }
            return null;
        };
        return $match(function ($rowInstrument, $rowInstance) use ($instrument, $instance) {
            return $rowInstrument === $instrument && (int) $rowInstance === $instance;
        }) ?? $match(function ($rowInstrument, $rowInstance) use ($instance) {
            return $rowInstrument === '' && $rowInstance !== '' && (int) $rowInstance === $instance;
        }) ?? $match(function ($rowInstrument, $rowInstance) {
            return $rowInstrument === '' && $rowInstance === '';
        });
    }

    /**
     * The changes that bring the relationship rows in line with the pedigree:
     * a saved row whose relationship is no longer in it (or that repeats
     * another row) is deleted, and a relationship with no row yet is added.
     * Rows that still hold keep their instance numbers, so a save that
     * doesn't change the diagram deletes and adds nothing.
     *
     * @param array<int, array{instance: int, fields: array}> $currentRows The
     *   record's relationship instrument rows ({@see RedcapInstrumentGateway::fetchInstrumentRows()}).
     * @param array<int, array{a: int, b: int, type: string}> $desiredRows {@see PedigreeRelationships::rows()},
     *   with each type as it's stored.
     * @param array{a: string, b: string, type: string} $fields The instrument's
     *   `person_a`, `person_b` and `relationship_type` field names.
     * @return array{delete: int[], add: array<int, array{a: int, b: int, type: string}>}
     *   Both empty when nothing changed.
     */
    public static function rows(array $currentRows, array $desiredRows, array $fields): array
    {
        $wanted = [];
        foreach ($desiredRows as $row) {
            $wanted[PedigreeRelationships::rowKey($row)] = $row;
        }
        usort($currentRows, function ($x, $y) {
            return (int) $x['instance'] <=> (int) $y['instance'];
        });
        $kept = [];
        $delete = [];
        foreach ($currentRows as $row) {
            $key = PedigreeRelationships::rowKey([
                'a' => trim((string) ($row['fields'][$fields['a']] ?? '')),
                'b' => trim((string) ($row['fields'][$fields['b']] ?? '')),
                'type' => trim((string) ($row['fields'][$fields['type']] ?? '')),
            ]);
            if (isset($wanted[$key]) && !isset($kept[$key])) {
                $kept[$key] = true; // the lowest instance of a relationship stays
            } else {
                $delete[] = (int) $row['instance'];
            }
        }
        return ['delete' => $delete, 'add' => array_values(array_diff_key($wanted, $kept))];
    }

    /**
     * The relationship-to-proband value each People row should now have, for
     * the rows where it differs from what's saved. A row whose person isn't
     * linked (or isn't connected to the proband) gets an empty value, which
     * clears any earlier one: the field is module-managed. Only existing rows
     * are written - writing a value to an instance that doesn't exist would
     * create a row.
     *
     * @param array<int, array{instance: int, fields: array}> $peopleRows The
     *   record's People rows, with at least `$field`.
     * @param array<int, string> $codesByInstance People instance => code.
     * @param string[]|null $choices The field's choice codes, or null when
     *   it's free text (any code can be written).
     * @return array{writes: array<int, string>, warnings: string[]} Instance =>
     *   value to write (`''` to clear).
     */
    public static function probandValues(array $peopleRows, array $codesByInstance, string $field, ?array $choices): array
    {
        $writes = [];
        $missing = []; // code => rows
        $choiceSet = $choices === null ? null : array_flip(array_map('strval', $choices));
        $hasOther = $choiceSet !== null && isset($choiceSet[PedigreeRelationships::OTHER]);
        foreach ($peopleRows as $row) {
            $instance = (int) $row['instance'];
            $current = (string) ($row['fields'][$field] ?? '');
            $desired = $codesByInstance[$instance] ?? '';
            if ($desired !== '' && $choiceSet !== null && !isset($choiceSet[$desired])) {
                $missing[$desired][] = $instance;
                if (!$hasOther) {
                    continue;
                }
                $desired = PedigreeRelationships::OTHER;
            }
            if ($desired !== $current) {
                $writes[$instance] = $desired;
            }
        }
        ksort($writes);
        $warnings = [];
        if ($missing) {
            ksort($missing);
            $described = [];
            foreach ($missing as $code => $instances) {
                sort($instances);
                $described[] = '"' . $code . '" (row' . (count($instances) > 1 ? 's ' : ' ') . implode(', ', $instances) . ')';
            }
            // One warning per save, however many rows it affects.
            $warnings[] = 'The relationship-to-proband field "' . $field . '" has no choice for ' . implode(', ', $described)
                . ($hasOther ? ', so those rows get "' . PedigreeRelationships::OTHER . '".'
                    : ', and no "' . PedigreeRelationships::OTHER . '" choice, so those rows are left as they are.');
        }
        return ['writes' => $writes, 'warnings' => $warnings];
    }
}
