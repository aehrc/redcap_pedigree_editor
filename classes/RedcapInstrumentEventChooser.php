<?php

namespace AEHRC\PedigreeEditorExternalModule;

/**
 * Picks the event whose repeating instances of the linked instrument "Edit
 * in REDCap" opens (pedigree-editor-repeating-instrument-sync task 2.1):
 * the current form's own event if the instrument repeats there, else the
 * first event, in project order, in the same arm where it does. A record
 * belongs to an arm, so another arm's event would be the wrong record
 * context. Pure: {@see RedcapInstrumentGateway::findRepeatingEventId()}
 * supplies the project's events.
 */
class RedcapInstrumentEventChooser
{
    /**
     * @param int[] $eventIds The project's event IDs, in project order.
     * @param array<int, int|string> $armByEvent Event ID => arm number.
     * @param int[] $repeatingEventIds Events where the instrument repeats.
     * @param int|null $currentEventId The form's own event, if known. When
     *   null, no arm restriction applies (the arm is unknown).
     */
    public static function choose(array $eventIds, array $armByEvent, array $repeatingEventIds, ?int $currentEventId): ?int
    {
        $repeating = array_flip(array_map('intval', $repeatingEventIds));
        if ($currentEventId !== null && isset($repeating[$currentEventId])) {
            return $currentEventId;
        }
        $arm = $currentEventId !== null && isset($armByEvent[$currentEventId]) ? (string) $armByEvent[$currentEventId] : null;
        foreach ($eventIds as $eventId) {
            $eventId = (int) $eventId;
            if ($arm !== null && (string) ($armByEvent[$eventId] ?? '') !== $arm) {
                continue;
            }
            if (isset($repeating[$eventId])) {
                return $eventId;
            }
        }
        return null;
    }
}
