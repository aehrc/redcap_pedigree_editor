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

    public function testPrefersTheCurrentEventWhenTheInstrumentRepeatsThere(): void
    {
        $this->assertSame(11, RedcapInstrumentEventChooser::choose(self::EVENTS, self::ARMS, [10, 11], 11));
    }

    public function testFallsBackToFirstRepeatingEventInTheSameArm(): void
    {
        $this->assertSame(11, RedcapInstrumentEventChooser::choose(self::EVENTS, self::ARMS, [11, 20], 10));
    }

    public function testNeverPicksAnotherArmsEvent(): void
    {
        $this->assertNull(RedcapInstrumentEventChooser::choose(self::EVENTS, self::ARMS, [20, 21], 10));
    }

    public function testUnknownCurrentEventSearchesAllArmsInOrder(): void
    {
        $this->assertSame(20, RedcapInstrumentEventChooser::choose(self::EVENTS, self::ARMS, [21, 20], null));
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
}
