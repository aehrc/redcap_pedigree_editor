<?php

namespace AEHRC\PedigreeEditorExternalModule\Tests;

use AEHRC\PedigreeEditorExternalModule\RedcapInstrumentEventChooser;
use PHPUnit\Framework\TestCase;

class RedcapInstrumentEventChooserTest extends TestCase
{
    // Arm 1: events 10, 11; arm 2: events 20, 21.
    private const EVENTS = [10, 11, 20, 21];
    private const ARMS = [10 => 1, 11 => 1, 20 => 2, 21 => 2];

    public function testClassicSingleEventProject(): void
    {
        $this->assertSame(44, RedcapInstrumentEventChooser::choose([44], [44 => 1], [44], 44));
    }

    public function testPicksTheArmsRepeatingEventFromAnotherEventInTheArm(): void
    {
        $this->assertSame(11, RedcapInstrumentEventChooser::choose(self::EVENTS, self::ARMS, [11, 20], 10));
        $this->assertSame(11, RedcapInstrumentEventChooser::choose(self::EVENTS, self::ARMS, [11, 20], 11));
    }

    public function testEachArmUsesItsOwnRepeatingEvent(): void
    {
        $this->assertSame(21, RedcapInstrumentEventChooser::choose(self::EVENTS, self::ARMS, [11, 21], 20));
    }

    public function testRefusesToGuessWhenTheArmHasSeveralRepeatingEvents(): void
    {
        // Even from one of those events: another event of the arm would read other rows for the same ref.
        $this->assertNull(RedcapInstrumentEventChooser::choose(self::EVENTS, self::ARMS, [10, 11], 11));
        $this->assertNull(RedcapInstrumentEventChooser::choose(self::EVENTS, self::ARMS, [10, 11], 10));
    }

    public function testAnotherArmHavingSeveralDoesNotAffectThisOne(): void
    {
        $this->assertSame(11, RedcapInstrumentEventChooser::choose(self::EVENTS, self::ARMS, [11, 20, 21], 10));
    }

    public function testNeverPicksAnotherArmsEvent(): void
    {
        $this->assertNull(RedcapInstrumentEventChooser::choose(self::EVENTS, self::ARMS, [20], 10));
    }

    public function testUnknownCurrentEventNeedsASingleRepeatingEventInTheProject(): void
    {
        $this->assertSame(20, RedcapInstrumentEventChooser::choose(self::EVENTS, self::ARMS, [20], null));
        $this->assertNull(RedcapInstrumentEventChooser::choose(self::EVENTS, self::ARMS, [11, 20], null));
    }

    public function testCurrentEventNotInTheProject(): void
    {
        $this->assertNull(RedcapInstrumentEventChooser::choose(self::EVENTS, self::ARMS, [11], 99));
    }

    public function testNotRepeatingAnywhere(): void
    {
        $this->assertNull(RedcapInstrumentEventChooser::choose(self::EVENTS, self::ARMS, [], 10));
    }

    public function testArmNumbersComparedAsStrings(): void
    {
        // REDCap hands arm numbers back as strings in places.
        $this->assertSame(11, RedcapInstrumentEventChooser::choose(self::EVENTS, [10 => '1', 11 => 1, 20 => '2', 21 => '2'], [11], 10));
    }

    public function testArmsWithSeveralRepeatingEvents(): void
    {
        $this->assertSame([], RedcapInstrumentEventChooser::armsWithSeveralRepeatingEvents(self::EVENTS, self::ARMS, [11, 20]));
        $this->assertSame([], RedcapInstrumentEventChooser::armsWithSeveralRepeatingEvents([44], [44 => 1], [44]));
        $this->assertSame(
            ['2' => [20, 21]],
            RedcapInstrumentEventChooser::armsWithSeveralRepeatingEvents(self::EVENTS, self::ARMS, [11, 21, 20])
        );
    }
}
