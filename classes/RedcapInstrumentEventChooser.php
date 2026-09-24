<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * Picks the event whose repeating instances of the linked instrument link
 * search, import and "Edit in REDCap" all read (pedigree-editor-repeating-
 * instrument-sync tasks 2.1/2.8): the one event in the form's arm where the
 * instrument repeats. A record belongs to an arm, so another arm's event
 * would be the wrong record context.
 *
 * Link refs carry no event (`record:<r>/instance:<n>`), so the rule is that
 * the instrument repeats in at most one event per arm - enforced by the
 * module's settings validation ({@see armsWithSeveralRepeatingEvents()}).
 * If a project's events change afterwards and an arm ends up with several,
 * no event is picked rather than guessing, since the same ref could then
 * mean a different row depending on which event the editor was opened
 * from. Pure: {@see RedcapInstrumentGateway} supplies the project's events.
 */
class RedcapInstrumentEventChooser
{
    /** The arm has several events where the instrument repeats (`events` lists them). */
    public const SEVERAL = 'several';
    /** The instrument repeats in no event of the arm. */
    public const NONE = 'none';
    /** The form's event isn't one of the project's events. */
    public const NOT_AN_EVENT = 'not-an-event';
    /** The form's event is unknown and several arms each have one - which is meant can't be told. */
    public const ARM_UNKNOWN = 'arm-unknown';

    /**
     * @param int[] $eventIds The project's event IDs, in project order.
     * @param array<int, int|string> $armByEvent Event ID => arm number.
     * @param int[] $repeatingEventIds Events where the instrument repeats.
     * @param int|null $currentEventId The form's own event, if known. When
     *   null the arm is unknown, so the whole project must have just one.
     * @return array{eventId: int|null, reason: string|null, events: int[]}
     *   The picked event and a null reason, or a null event and why (one of
     *   this class's constants). For {@see SEVERAL}, `events` holds the
     *   crowded arm's events (with an unknown arm, the first crowded arm's).
     */
    public static function pick(array $eventIds, array $armByEvent, array $repeatingEventIds, ?int $currentEventId): array
    {
        $byArm = self::repeatingEventsByArm($eventIds, $armByEvent, $repeatingEventIds);
        if ($currentEventId !== null) {
            if (!isset($armByEvent[$currentEventId])) {
                return self::result(null, self::NOT_AN_EVENT);
            }
            $armEventIds = $byArm[(string) $armByEvent[$currentEventId]] ?? [];
            if (count($armEventIds) > 1) {
                return self::result(null, self::SEVERAL, $armEventIds);
            }
            return $armEventIds ? self::result($armEventIds[0], null) : self::result(null, self::NONE);
        }
        $all = $byArm ? array_merge(...array_values($byArm)) : [];
        if (count($all) === 1) {
            return self::result($all[0], null);
        }
        foreach ($byArm as $armEventIds) {
            if (count($armEventIds) > 1) {
                return self::result(null, self::SEVERAL, $armEventIds);
            }
        }
        return self::result(null, $all ? self::ARM_UNKNOWN : self::NONE);
    }

    /**
     * @return array<string, int[]> Arm number => its events where the
     *   instrument repeats, for each arm with more than one (in project
     *   order). Empty when the project follows the one-event-per-arm rule.
     */
    public static function armsWithSeveralRepeatingEvents(array $eventIds, array $armByEvent, array $repeatingEventIds): array
    {
        return array_filter(
            self::repeatingEventsByArm($eventIds, $armByEvent, $repeatingEventIds),
            function ($armEventIds) {
                return count($armEventIds) > 1;
            }
        );
    }

    private static function result(?int $eventId, ?string $reason, array $events = []): array
    {
        return ['eventId' => $eventId, 'reason' => $reason, 'events' => $events];
    }

    /**
     * @return array<string, int[]> Arm number => its events where the instrument repeats.
     */
    private static function repeatingEventsByArm(array $eventIds, array $armByEvent, array $repeatingEventIds): array
    {
        $repeating = array_flip(array_map('intval', $repeatingEventIds));
        $byArm = [];
        foreach ($eventIds as $eventId) {
            $eventId = (int) $eventId;
            if (isset($repeating[$eventId])) {
                $byArm[(string) ($armByEvent[$eventId] ?? '')][] = $eventId;
            }
        }
        return $byArm;
    }
}
