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
 * {@see choose()} returns null rather than guessing, since the same ref could
 * then mean a different row depending on which event the editor was opened
 * from. Pure: {@see RedcapInstrumentGateway} supplies the project's events.
 */
class RedcapInstrumentEventChooser
{
    /**
     * @param int[] $eventIds The project's event IDs, in project order.
     * @param array<int, int|string> $armByEvent Event ID => arm number.
     * @param int[] $repeatingEventIds Events where the instrument repeats.
     * @param int|null $currentEventId The form's own event, if known. When
     *   null the arm is unknown, so the whole project must have just one.
     * @return int|null The event, or null if the instrument repeats in no
     *   event, or in several, of the arm.
     */
    public static function choose(array $eventIds, array $armByEvent, array $repeatingEventIds, ?int $currentEventId): ?int
    {
        $arm = $currentEventId !== null && isset($armByEvent[$currentEventId]) ? (string) $armByEvent[$currentEventId] : null;
        if ($currentEventId !== null && $arm === null) {
            return null; // not one of the project's events
        }
        $candidates = [];
        foreach (self::repeatingEventsByArm($eventIds, $armByEvent, $repeatingEventIds) as $armNum => $armEventIds) {
            if ($arm === null || (string) $armNum === $arm) {
                $candidates = array_merge($candidates, $armEventIds);
            }
        }
        return count($candidates) === 1 ? $candidates[0] : null;
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
