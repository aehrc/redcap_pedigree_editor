<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * Turns a pedigree read by {@see PedigreeBundleReader} into the relationship
 * instrument's rows and each person's relationship to the proband
 * (pedigree-editor-relationship-topology-sync D3-D5). Pure: no REDCap access.
 */
class PedigreeRelationships
{
    /** What the relative is to the person: the relative is the parent. */
    const PARENT_CODES = ['KIN:027', 'KIN:028', 'KIN:003', 'KIN:022'];
    const PARTNER_CODES = ['KIN:026', 'KIN:048', 'KIN:030', 'KIN:049'];
    const TWIN_CODES = ['KIN:009', 'KIN:010', 'KIN:011'];
    /** The same both ways round, so stored once per pair. */
    const SYMMETRIC_CODES = [...self::PARTNER_CODES, ...self::TWIN_CODES];
    /** Every code a relationship row can have. */
    const ROW_CODES = [...self::PARENT_CODES, ...self::SYMMETRIC_CODES];

    /**
     * Relationship-to-proband codes (D5). {@see relationshipsToProband()}
     * tries them in this order, and the README's table lists them in it.
     */
    const PROBAND = 'proband';
    const OTHER = 'other';
    const PROBAND_CODES = [
        'proband', 'mother', 'father', 'sibling', 'half_sibling_maternal', 'half_sibling_paternal',
        'child', 'partner', 'grandparent_maternal', 'grandparent_paternal',
        'aunt_uncle_maternal', 'aunt_uncle_paternal', 'cousin_maternal', 'cousin_paternal',
        'niece_nephew', 'grandchild', 'other',
    ];

    /**
     * People instance numbers of the pedigree's people linked to rows of
     * `$record`. A link to another record's row is ignored (D3), and so is a
     * second person linked to an instance already taken.
     *
     * @param array $pedigree A successful {@see PedigreeBundleReader::read()}.
     * @param int[]|null $existingInstances Only these People instances count
     *   (a link to a row since deleted points at nothing); null for any.
     * @return array<string, int> Person key => instance, in Bundle order.
     */
    public static function linkedInstances(array $pedigree, string $record, ?array $existingInstances = null): array
    {
        $existing = $existingInstances === null ? null : array_flip(array_map('intval', $existingInstances));
        $linked = [];
        foreach ($pedigree['people'] as $key => $person) {
            $link = RedcapInstrumentReference::decode($person['ref']);
            if ($link === null || $link['record'] !== $record) {
                continue;
            }
            if (($existing !== null && !isset($existing[$link['instance']])) || in_array($link['instance'], $linked, true)) {
                continue;
            }
            $linked[(string) $key] = $link['instance'];
        }
        return $linked;
    }

    /**
     * One row per direct relationship between two linked people (D3): the
     * parent first for a parent relationship, the lower instance first for
     * partners and twins. KIN codes open-pedigree doesn't write are left out.
     *
     * @param array<string, int> $linked {@see linkedInstances()}.
     * @return array<int, array{a: int, b: int, type: string}> Sorted and
     *   without duplicates, so equal pedigrees give equal lists.
     */
    public static function rows(array $pedigree, array $linked): array
    {
        $rows = [];
        foreach ($pedigree['relationships'] as $relationship) {
            $person = $linked[$relationship['person']] ?? null;
            $relative = $linked[$relationship['relative']] ?? null;
            $code = $relationship['code'];
            if ($person === null || $relative === null || $person === $relative) {
                continue;
            }
            if (in_array($code, self::PARENT_CODES, true)) {
                $row = ['a' => $relative, 'b' => $person, 'type' => $code];
            } elseif (in_array($code, self::SYMMETRIC_CODES, true)) {
                $row = ['a' => min($person, $relative), 'b' => max($person, $relative), 'type' => $code];
            } else {
                continue;
            }
            $rows[self::rowKey($row)] = $row;
        }
        usort($rows, function ($x, $y) {
            return [$x['a'], $x['b'], $x['type']] <=> [$y['a'], $y['b'], $y['type']];
        });
        return $rows;
    }

    public static function rowKey(array $row): string
    {
        return $row['a'] . '|' . $row['b'] . '|' . $row['type'];
    }

    /**
     * Each person's relationship to the proband (D4, D5), over the whole
     * pedigree - linked or not, so a label can go through someone unlinked.
     *
     * The side (maternal/paternal) comes from which of the proband's parents
     * the relationship goes through, and a parent's sex from KIN:027/KIN:028,
     * or the parent's own gender for KIN:003 and adoptive parents (KIN:022,
     * who count as parents, as the diagram draws them). Where a person
     * qualifies for more than one code (a family with consanguinity), the
     * first in {@see PROBAND_CODES} order wins. Anyone else reachable from
     * the proband through parent, child, partner or twin relationships is
     * `other`; anyone unreachable, or everyone when the proband is unknown,
     * gets nothing.
     *
     * @param array $pedigree A successful {@see PedigreeBundleReader::read()}.
     * @return array<string, string> Person key => code, for reachable people.
     */
    public static function relationshipsToProband(array $pedigree): array
    {
        $proband = $pedigree['probandId'];
        if ($proband === null || !isset($pedigree['people'][$proband])) {
            return [];
        }
        $graph = self::graph($pedigree);
        $labels = [$proband => self::PROBAND];
        $assign = function (array $people, string $code) use (&$labels) {
            foreach ($people as $person) {
                if (!isset($labels[$person])) {
                    $labels[$person] = $code;
                }
            }
        };
        $parentsOf = function (string $person) use ($graph): array {
            return $graph['parents'][$person] ?? [];
        };
        $childrenOf = function (array $people) use ($graph): array {
            $children = [];
            foreach ($people as $person) {
                foreach (array_keys($graph['children'][$person] ?? []) as $child) {
                    $children[$child] = true;
                }
            }
            return array_map('strval', array_keys($children));
        };

        // The proband's parents, by side. A parent of unknown sex has no side.
        $bySide = ['maternal' => [], 'paternal' => []];
        foreach ($parentsOf($proband) as $parent => $sex) {
            if ($sex === 'F') {
                $bySide['maternal'][] = (string) $parent;
            } elseif ($sex === 'M') {
                $bySide['paternal'][] = (string) $parent;
            }
        }
        $assign($bySide['maternal'], 'mother');
        $assign($bySide['paternal'], 'father');

        // A twin is a sibling, whatever parents are recorded.
        $twins = array_map('strval', array_keys($graph['twins'][$proband] ?? []));
        $assign($twins, 'sibling');

        // Siblings have exactly the proband's parents; half-siblings share just one.
        $probandParents = array_map('strval', array_keys($parentsOf($proband)));
        $siblings = [];
        foreach ($childrenOf($probandParents) as $child) {
            if ($child === $proband) {
                continue;
            }
            $childParents = array_map('strval', array_keys($parentsOf($child)));
            $shared = array_values(array_intersect($probandParents, $childParents));
            if (count($shared) === count($probandParents) && count($childParents) === count($probandParents)) {
                $assign([$child], 'sibling');
            } elseif (count($shared) === 1) {
                $side = in_array($shared[0], $bySide['maternal'], true) ? 'maternal'
                    : (in_array($shared[0], $bySide['paternal'], true) ? 'paternal' : null);
                if ($side !== null) {
                    $assign([$child], 'half_sibling_' . $side);
                }
            }
            $siblings[] = $child;
        }
        $siblings = array_values(array_unique(array_merge($siblings, $twins)));

        $children = $childrenOf([$proband]);
        $assign($children, 'child');
        $assign(array_map('strval', array_keys($graph['partners'][$proband] ?? [])), 'partner');

        $grandparents = [];
        foreach ($bySide as $side => $parents) {
            $grandparents[$side] = [];
            foreach ($parents as $parent) {
                $grandparents[$side] = array_merge($grandparents[$side], array_map('strval', array_keys($parentsOf($parent))));
            }
            $assign($grandparents[$side], 'grandparent_' . $side);
        }
        $auntsUncles = [];
        foreach ($bySide as $side => $parents) {
            $auntsUncles[$side] = array_values(array_diff($childrenOf($grandparents[$side]), $parents));
            $assign($auntsUncles[$side], 'aunt_uncle_' . $side);
        }
        foreach ($bySide as $side => $parents) {
            $assign($childrenOf($auntsUncles[$side]), 'cousin_' . $side);
        }
        $assign($childrenOf($siblings), 'niece_nephew');
        $assign($childrenOf($children), 'grandchild');

        // Everyone else connected to the proband.
        $queue = [$proband];
        $seen = [$proband => true];
        while ($queue) {
            $person = array_shift($queue);
            foreach (array_keys($graph['neighbours'][$person] ?? []) as $next) {
                $next = (string) $next;
                if (!isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }
        $assign(array_map('strval', array_keys($seen)), self::OTHER);

        return $labels;
    }

    /**
     * @return array{parents: array<string, array<string, string|null>>,
     *   children: array<string, array<string, true>>,
     *   partners: array<string, array<string, true>>,
     *   twins: array<string, array<string, true>>,
     *   neighbours: array<string, array<string, true>>}
     *   `parents` maps a person to their parents' sex (`F`, `M` or null).
     */
    private static function graph(array $pedigree): array
    {
        $graph = ['parents' => [], 'children' => [], 'partners' => [], 'twins' => [], 'neighbours' => []];
        foreach ($pedigree['relationships'] as $relationship) {
            $person = $relationship['person'];
            $relative = $relationship['relative'];
            $code = $relationship['code'];
            if (in_array($code, self::PARENT_CODES, true)) {
                $graph['parents'][$person][$relative] = self::parentSex($code, $pedigree['people'][$relative]['gender'] ?? null);
                $graph['children'][$relative][$person] = true;
            } elseif (in_array($code, self::PARTNER_CODES, true)) {
                $graph['partners'][$person][$relative] = true;
                $graph['partners'][$relative][$person] = true;
            } elseif (in_array($code, self::TWIN_CODES, true)) {
                $graph['twins'][$person][$relative] = true;
                $graph['twins'][$relative][$person] = true;
            } else {
                continue;
            }
            $graph['neighbours'][$person][$relative] = true;
            $graph['neighbours'][$relative][$person] = true;
        }
        return $graph;
    }

    private static function parentSex(string $code, ?string $gender): ?string
    {
        if ($code === 'KIN:027') {
            return 'F';
        }
        if ($code === 'KIN:028') {
            return 'M';
        }
        return $gender === 'female' ? 'F' : ($gender === 'male' ? 'M' : null);
    }
}
