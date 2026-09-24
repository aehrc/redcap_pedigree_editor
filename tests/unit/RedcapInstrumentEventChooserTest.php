<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\RedcapInstrumentEventChooser as Chooser;
use PHPUnit\Framework\TestCase;

class RedcapInstrumentEventChooserTest extends TestCase
{
    // Arm 1: events 10, 11; arm 2: events 20, 21.
    private const EVENTS = [10, 11, 20, 21];
    private const ARMS = [10 => 1, 11 => 1, 20 => 2, 21 => 2];

    private static function pick(array $repeating, ?int $current, array $events = self::EVENTS, array $arms = self::ARMS): array
    {
        return Chooser::pick($events, $arms, $repeating, $current);
    }

    private static function picked(int $eventId): array
    {
        return ['eventId' => $eventId, 'reason' => null, 'events' => []];
    }

    private static function refused(string $reason, array $events = []): array
    {
        return ['eventId' => null, 'reason' => $reason, 'events' => $events];
    }

    public function testClassicSingleEventProject(): void
    {
        $this->assertSame(self::picked(44), self::pick([44], 44, [44], [44 => 1]));
        // An unknown event is fine while the project has just the one.
        $this->assertSame(self::picked(44), self::pick([44], null, [44], [44 => 1]));
    }

    public function testPicksTheArmsRepeatingEventFromAnyEventInTheArm(): void
    {
        $this->assertSame(self::picked(11), self::pick([11, 20], 10));
        $this->assertSame(self::picked(11), self::pick([11, 20], 11));
    }

    public function testEachArmUsesItsOwnRepeatingEvent(): void
    {
        $this->assertSame(self::picked(21), self::pick([11, 21], 20));
    }

    public function testRefusesToGuessWhenTheArmHasSeveralRepeatingEvents(): void
    {
        // Even from one of those events: another event of the arm would read other rows for the same ref.
        $this->assertSame(self::refused(Chooser::SEVERAL, [10, 11]), self::pick([11, 10], 11));
        $this->assertSame(self::refused(Chooser::SEVERAL, [10, 11]), self::pick([10, 11], 10));
    }

    public function testAnotherArmHavingSeveralDoesNotAffectThisOne(): void
    {
        $this->assertSame(self::picked(11), self::pick([11, 20, 21], 10));
    }

    public function testNeverPicksAnotherArmsEvent(): void
    {
        $this->assertSame(self::refused(Chooser::NONE), self::pick([20], 10));
    }

    public function testUnknownCurrentEvent(): void
    {
        $this->assertSame(self::picked(20), self::pick([20], null));
        // One per arm, but which arm is meant can't be told.
        $this->assertSame(self::refused(Chooser::ARM_UNKNOWN), self::pick([11, 20], null));
        // A crowded arm is the reason to report, whichever arm is meant.
        $this->assertSame(self::refused(Chooser::SEVERAL, [20, 21]), self::pick([11, 20, 21], null));
        $this->assertSame(self::refused(Chooser::NONE), self::pick([], null));
    }

    public function testCurrentEventNotInTheProject(): void
    {
        $this->assertSame(self::refused(Chooser::NOT_AN_EVENT), self::pick([11], 99));
    }

    public function testNotRepeatingAnywhere(): void
    {
        $this->assertSame(self::refused(Chooser::NONE), self::pick([], 10));
    }

    public function testArmNumbersComparedAsStrings(): void
    {
        // REDCap hands arm numbers back as strings in places.
        $this->assertSame(self::picked(11), self::pick([11], 10, self::EVENTS, [10 => '1', 11 => 1, 20 => '2', 21 => '2']));
    }

    public function testArmsWithSeveralRepeatingEvents(): void
    {
        $this->assertSame([], Chooser::armsWithSeveralRepeatingEvents(self::EVENTS, self::ARMS, [11, 20]));
        $this->assertSame([], Chooser::armsWithSeveralRepeatingEvents([44], [44 => 1], [44]));
        $this->assertSame(
            ['2' => [20, 21]],
            Chooser::armsWithSeveralRepeatingEvents(self::EVENTS, self::ARMS, [11, 21, 20])
        );
    }
}
