<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * The relationship topology sync settings (pedigree-editor-relationship-
 * topology-sync D8): reading them, and the rules saving them must pass.
 * Pure: {@see RedcapInstrumentGateway} supplies the project's metadata.
 */
class RelationshipSettings
{
    const PEOPLE_INSTRUMENT = 'project_pedigree_import_instrument';
    const SOURCE_FIELD = 'project_relationship_source_field';
    const INSTRUMENT = 'project_relationship_instrument';
    const PERSON_A_FIELD = 'project_relationship_person_a_field';
    const PERSON_B_FIELD = 'project_relationship_person_b_field';
    const TYPE_FIELD = 'project_relationship_type_field';
    const TO_PROBAND_FIELD = 'project_relationship_to_proband_field';

    /** The settings that name a field. */
    const FIELD_KEYS = [self::SOURCE_FIELD, self::PERSON_A_FIELD, self::PERSON_B_FIELD, self::TYPE_FIELD, self::TO_PROBAND_FIELD];
    /** Every relationship setting: using any of them turns the feature on. */
    const KEYS = [self::SOURCE_FIELD, self::INSTRUMENT, self::PERSON_A_FIELD, self::PERSON_B_FIELD, self::TYPE_FIELD, self::TO_PROBAND_FIELD];
    /** Storage formats whose saved pedigree is a GA4GH Bundle (fhir_v1 saves as GA4GH since 1.0.0). */
    const READABLE_FORMATS = ['GA4GH', 'fhir_v1'];
    /** Field types each field may have. */
    const PERSON_FIELD_TYPES = ['text'];
    const TYPE_FIELD_TYPES = ['dropdown', 'radio', 'text'];
    const TO_PROBAND_FIELD_TYPES = ['dropdown', 'radio', 'text'];

    /**
     * The choice label of every KIN code a relationship row can have (D3):
     * exactly {@see PedigreeRelationships::ROW_CODES}, which a unit test checks.
     */
    const KIN_CODES = [
        'KIN:027' => 'Biological mother', 'KIN:028' => 'Biological father', 'KIN:003' => 'Biological parent',
        'KIN:022' => 'Adoptive parent', 'KIN:026' => 'Partner', 'KIN:048' => 'Separated partner',
        'KIN:030' => 'Consanguineous partner', 'KIN:049' => 'Separated consanguineous partner',
        'KIN:009' => 'Twin', 'KIN:010' => 'Monozygotic twin', 'KIN:011' => 'Polyzygotic twin',
    ];

    /**
     * The value stored for a KIN code. REDCap's choice codes can only hold
     * letters, digits, `.`, `_` and `-` (`MetaData.php` in 16.0.32), so a
     * dropdown or radio field stores `KIN:027` as `KIN_027`; a text field
     * (plain, or one using an ontology provider) stores the code as it is.
     *
     * @param bool $choiceField Whether the relationship type field is a
     *   dropdown or radio field.
     */
    public static function storedKinCode(string $kinCode, bool $choiceField = true): string
    {
        return $choiceField ? str_replace(':', '_', $kinCode) : $kinCode;
    }

    const REQUIRED = [
        self::SOURCE_FIELD => 'the @PEDIGREE field to read them from',
        self::INSTRUMENT => 'the relationship instrument',
        self::PERSON_A_FIELD => 'the person A field',
        self::PERSON_B_FIELD => 'the person B field',
        self::TYPE_FIELD => 'the relationship type field',
    ];

    /**
     * @param callable $get Setting key => value (`getProjectSetting`, or the
     *   submitted settings).
     * @return array{people: string, source: string, instrument: string, a: string,
     *   b: string, type: string, toProband: string|null}|null Null unless the
     *   People instrument and every required relationship setting are set -
     *   so nothing is ever written for a half-configured project.
     */
    public static function read(callable $get): ?array
    {
        $value = function (string $key) use ($get): ?string {
            return self::value($get($key));
        };
        $config = [
            'people' => $value(self::PEOPLE_INSTRUMENT),
            'source' => $value(self::SOURCE_FIELD),
            'instrument' => $value(self::INSTRUMENT),
            'a' => $value(self::PERSON_A_FIELD),
            'b' => $value(self::PERSON_B_FIELD),
            'type' => $value(self::TYPE_FIELD),
        ];
        if (in_array(null, $config, true)) {
            return null;
        }
        $config['toProband'] = $value(self::TO_PROBAND_FIELD);
        return $config;
    }

    /** Whether any relationship setting is used. */
    public static function isUsed(array $settings): bool
    {
        foreach (self::KEYS as $key) {
            if (self::value($settings[$key] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] The fields the settings name, for fetching their Data Dictionary rows. */
    public static function fieldNames(array $settings): array
    {
        $names = [];
        foreach (self::FIELD_KEYS as $key) {
            $name = self::value($settings[$key] ?? null);
            if ($name !== null) {
                $names[] = $name;
            }
        }
        return array_values(array_unique($names));
    }

    private static function value($v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    /**
     * @param array $settings The submitted settings.
     * @param array $dictionary Data Dictionary rows (field => row) for every
     *   field named in the settings ({@see fieldNames()}); a missing field is absent.
     * @param array<int, string>|null $peopleEvents Event ID => name, where the
     *   People instrument repeats (null: couldn't check).
     * @param array<int, string>|null $relationshipEvents The same for the
     *   relationship instrument.
     * @param string|null $format The storage format pedigrees are saved in
     *   (the project's, or else the system's).
     * @param array<int, array{arm: string, repeats: bool}>|null $sourceEvents
     *   The events the source field's instrument is designated to
     *   ({@see RedcapInstrumentGateway::findFormEvents()}); null: couldn't check.
     * @return string Errors, one per line; empty when the settings are fine
     *   or no relationship setting is used.
     */
    public static function validate(array $settings, array $dictionary, ?array $peopleEvents, ?array $relationshipEvents, ?string $format, ?array $sourceEvents = null): string
    {
        if (!self::isUsed($settings)) {
            return '';
        }
        $prefix = 'Relationships: ';
        $errors = '';
        $people = self::value($settings[self::PEOPLE_INSTRUMENT] ?? null);
        if ($people === null) {
            $errors .= $prefix . 'set the Repeating instrument to link pedigree people to first; relationships are between its rows.' . "\n";
        }
        foreach (self::REQUIRED as $key => $what) {
            if (self::value($settings[$key] ?? null) === null) {
                $errors .= $prefix . $what . ' is required.' . "\n";
            }
        }
        if ($errors !== '') {
            return $errors;
        }
        $config = self::read(function (string $key) use ($settings) {
            return $settings[$key] ?? null;
        });

        if (!in_array($format, self::READABLE_FORMATS, true)) {
            $errors .= $prefix . 'they can only be read from a pedigree saved in the GA4GH format; set the Storage Format to'
                . ' "GA4GH - Recommended Format" (it is ' . ($format ? '"' . $format . '"' : 'not set') . ').' . "\n";
        }

        $source = $dictionary[$config['source']] ?? null;
        if ($source === null) {
            $errors .= $prefix . 'the field "' . $config['source'] . '" doesn\'t exist.' . "\n";
        } elseif (($source['field_type'] ?? null) !== 'notes' || !preg_match('/@PEDIGREE(?![A-Za-z0-9_])/', (string) ($source['field_annotation'] ?? ''))) {
            $errors .= $prefix . 'the field "' . $config['source'] . '" isn\'t a Notes Box tagged @PEDIGREE.' . "\n";
        } elseif (in_array($source['form_name'] ?? null, [$people, $config['instrument']], true)) {
            // One pedigree per record: on a person's (or a relationship's) row, each row's save would
            // rewrite the whole record's relationships from that one row's pedigree.
            $errors .= $prefix . 'the @PEDIGREE field "' . $config['source'] . '" can\'t be on the Repeating instrument or the'
                . ' relationship instrument; it holds the whole family\'s pedigree.' . "\n";
        } elseif ($sourceEvents !== null) {
            // Likewise one copy of it per record's arm: several copies would take turns rewriting the relationships.
            $arms = array_count_values(array_column($sourceEvents, 'arm'));
            if (in_array(true, array_column($sourceEvents, 'repeats'), true) || max($arms ?: [0]) > 1) {
                $errors .= $prefix . 'the @PEDIGREE field "' . $config['source'] . '" must be on a form that doesn\'t repeat and is in only'
                    . ' one event per arm, so each record has one pedigree to read.' . "\n";
            }
        }

        if ($config['instrument'] === $people) {
            $errors .= $prefix . 'the relationship instrument must be a different instrument from the Repeating instrument.' . "\n";
        } elseif ($peopleEvents !== null && $relationshipEvents !== null) {
            $missing = array_diff_key($peopleEvents, $relationshipEvents);
            if (!$peopleEvents || $missing) {
                $errors .= $prefix . 'the relationship instrument ("' . $config['instrument'] . '") must be a repeating instrument in '
                    . ($peopleEvents ? 'every event where "' . $people . '" repeats (not in: ' . implode(', ', $missing) . ')'
                        : 'the event where "' . $people . '" repeats')
                    . '. Set it up in Project Setup > Repeating Instruments and Events.' . "\n";
            }
        }

        $types = ['a' => self::PERSON_FIELD_TYPES, 'b' => self::PERSON_FIELD_TYPES, 'type' => self::TYPE_FIELD_TYPES];
        foreach (['a' => 'person A', 'b' => 'person B', 'type' => 'relationship type'] as $part => $what) {
            $field = $dictionary[$config[$part]] ?? null;
            if ($field === null || ($field['form_name'] ?? null) !== $config['instrument']) {
                $errors .= $prefix . 'the ' . $what . ' field "' . $config[$part] . '" isn\'t on the relationship instrument ("' . $config['instrument'] . '").' . "\n";
            } elseif (!in_array($field['field_type'] ?? null, $types[$part], true)) {
                $errors .= $prefix . 'the ' . $what . ' field "' . $config[$part] . '" must be ' . self::describeTypes($types[$part]) . '.' . "\n";
            }
        }
        if (count(array_unique([$config['a'], $config['b'], $config['type']])) < 3) {
            $errors .= $prefix . 'the person A, person B and relationship type fields must be three different fields.' . "\n";
        }
        $type = $dictionary[$config['type']] ?? null;
        $typeChoices = $type !== null ? self::choiceCodes($type) : null;
        if ($typeChoices !== null) {
            $missingCodes = array_diff(array_map(function ($code) {
                return self::storedKinCode($code);
            }, array_keys(self::KIN_CODES)), $typeChoices);
            if ($missingCodes) {
                $errors .= $prefix . 'the relationship type field "' . $config['type'] . '" is missing the choices ' . implode(', ', $missingCodes)
                    . '. The module README lists the choices to paste in.' . "\n";
            }
        }

        if ($config['toProband'] !== null) {
            $field = $dictionary[$config['toProband']] ?? null;
            if ($field === null || ($field['form_name'] ?? null) !== $people) {
                $errors .= $prefix . 'the relationship to proband field "' . $config['toProband'] . '" isn\'t on the Repeating instrument ("' . $people . '").' . "\n";
            } elseif (!in_array($field['field_type'] ?? null, self::TO_PROBAND_FIELD_TYPES, true)) {
                $errors .= $prefix . 'the relationship to proband field "' . $config['toProband'] . '" must be ' . self::describeTypes(self::TO_PROBAND_FIELD_TYPES) . '.' . "\n";
            } elseif (PedigreeFieldTag::parse((string) ($field['field_annotation'] ?? ''))->present) {
                // The module owns the field (it clears it on rows not linked), so it can't also be one people fill in.
                $errors .= $prefix . 'the relationship to proband field "' . $config['toProband'] . '" is tagged @PEDIGREE_FIELD; use a field'
                    . ' of its own (with @READONLY), since the module overwrites it and clears it on rows not in the pedigree.' . "\n";
            }
        }
        return $errors;
    }

    private static function describeTypes(array $types): string
    {
        $names = ['text' => 'a Text Box', 'dropdown' => 'a Drop-down List', 'radio' => 'Radio Buttons'];
        $described = array_map(function ($type) use ($names) {
            return $names[$type];
        }, $types);
        return count($described) > 1 ? implode(', ', array_slice($described, 0, -1)) . ' or ' . end($described) : $described[0];
    }

    /**
     * @return string[]|null A dropdown or radio field's choice codes, or null
     *   for a field that takes any text.
     */
    public static function choiceCodes(array $field): ?array
    {
        if (!in_array($field['field_type'] ?? null, ['dropdown', 'radio'], true)) {
            return null;
        }
        return array_map(function ($option) {
            return (string) $option['valueCoding']['code'];
        }, QuestionnaireDerivation::parseChoices((string) ($field['select_choices_or_calculations'] ?? '')));
    }
}
