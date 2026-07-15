<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * Parses the `@PEDIGREE_FIELD` action tag used to opt a repeating-instrument
 * field into Questionnaire derivation, along with its optional `mapsTo=`/
 * `legend=`/`predicate=` parameters.
 *
 * Distinct from the module's existing `@PEDIGREE`/`@PEDIGREE_HPO`/
 * `@PEDIGREE_SCT` tags, which mark the single field storing the whole
 * pedigree diagram, not a per-person repeating-instrument field.
 */
class PedigreeFieldTag
{
    const TAG_PATTERN = '/@PEDIGREE_FIELD\b(\s*\(([^)]*)\))?/';
    const PARAM_PATTERN = '/([a-zA-Z]+)\s*=\s*(["\'])(.*?)\2/';

    /** @var bool */
    public $present;

    /** @var string|null */
    public $mapsTo;

    /** @var string|null */
    public $legend;

    /** @var string|null */
    public $predicate;

    private function __construct(bool $present, ?string $mapsTo, ?string $legend, ?string $predicate)
    {
        $this->present = $present;
        $this->mapsTo = $mapsTo;
        $this->legend = $legend;
        $this->predicate = $predicate;
    }

    /**
     * Parses a field's `field_annotation` value.
     *
     * @param string|null $annotation
     * @return PedigreeFieldTag A tag with `present === false` if the field
     *   is not tagged `@PEDIGREE_FIELD` at all.
     */
    public static function parse(?string $annotation): PedigreeFieldTag
    {
        if (!$annotation || !preg_match(self::TAG_PATTERN, $annotation, $tagMatch)) {
            return new self(false, null, null, null);
        }

        $params = ['mapsTo' => null, 'legend' => null, 'predicate' => null];
        if (!empty($tagMatch[2])) {
            if (preg_match_all(self::PARAM_PATTERN, $tagMatch[2], $paramMatches, PREG_SET_ORDER)) {
                foreach ($paramMatches as $paramMatch) {
                    $key = $paramMatch[1];
                    if (array_key_exists($key, $params)) {
                        $params[$key] = $paramMatch[3];
                    }
                }
            }
        }

        return new self(true, $params['mapsTo'], $params['legend'], $params['predicate']);
    }
}
