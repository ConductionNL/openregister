<?php

/**
 * Behaviour tests for UserCalendarSelector: the pinned calendar, re-selection and VEVENT detection.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\CalendarEvent
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\CalendarEvent;

use Exception;
use OCA\OpenRegister\Service\CalendarEvent\UserCalendarSelector;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\CalendarEvent\UserCalendarSelector
 */
class UserCalendarSelectorBehaviourTest extends TestCase
{
    private const SET = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

    /** @var IConfig&MockObject */
    private IConfig $config;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(IConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }//end setUp()

    private function selector(): UserCalendarSelector
    {
        return new UserCalendarSelector($this->config, $this->logger);
    }//end selector()

    private static function calendar(int $id, string $uri, mixed $components): array
    {
        return ['id' => $id, 'uri' => $uri, self::SET => $components];
    }//end calendar()

    public function testThePinnedVeventCalendarIsUsedWithoutRepinning(): void
    {
        $this->config->method('getUserValue')->with('alice', 'openregister', 'events_calendar_uri', '')->willReturn('work');
        $this->config->expects($this->never())->method('setUserValue');

        $this->assertSame(
            ['id' => 2, 'uri' => 'work'],
            $this->selector()->select('alice', [self::calendar(1, 'personal', 'VEVENT'), self::calendar(2, 'work', ['VEVENT'])])
        );
    }//end testThePinnedVeventCalendarIsUsedWithoutRepinning()

    public function testAPinThatNoLongerResolvesIsLoggedAndThePersonalCalendarRepinned(): void
    {
        $this->config->method('getUserValue')->willReturn('gone');
        $this->logger->expects($this->once())->method('info')->with($this->stringContains('Pinned calendar URI gone no longer resolves'));
        $this->config->expects($this->once())->method('setUserValue')->with('alice', 'openregister', 'events_calendar_uri', 'personal');

        $chosen = $this->selector()->select(
            'alice',
            [self::calendar(1, 'tasks', ['VTODO']), self::calendar(2, 'birthdays', 'VEVENT'), self::calendar(3, 'personal', 'VEVENT,VTODO')]
        );

        $this->assertSame(['id' => 3, 'uri' => 'personal'], $chosen);
    }//end testAPinThatNoLongerResolvesIsLoggedAndThePersonalCalendarRepinned()

    public function testWithoutPersonalTheFirstVeventCalendarIsChosenAndAFailedPinIsOnlyAWarning(): void
    {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('setUserValue')->willThrowException(new Exception('read-only'));
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Failed to persist calendar URI pin: read-only', ['userId' => 'alice', 'uri' => 'team']);

        $this->assertSame(
            ['id' => 5, 'uri' => 'team'],
            $this->selector()->select('alice', [self::calendar(4, 'todo', null), self::calendar(5, 'team', ['vevent'])])
        );
    }//end testWithoutPersonalTheFirstVeventCalendarIsChosenAndAFailedPinIsOnlyAWarning()

    public function testNoVeventCalendarAtAllIsAnError(): void
    {
        $this->config->method('getUserValue')->willReturn('');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No VEVENT-supporting calendar found for user alice');
        $this->selector()->select('alice', [self::calendar(1, 'tasks', 'VTODO')]);
    }//end testNoVeventCalendarAtAllIsAnError()

    public function testComponentSetsAreReadFromStringsListsAndValueObjects(): void
    {
        $set = new class {
            public function getValue(): array
            {
                return ['VTODO', 'VEVENT'];
            }
        };
        $selector = $this->selector();

        $this->assertTrue($selector->calendarSupportsVevent([self::SET => $set]));
        $this->assertTrue($selector->calendarSupportsVevent([self::SET => new \ArrayIterator(['VEVENT'])]));
        $this->assertTrue($selector->calendarSupportsVevent([self::SET => 'vjournal,vevent']));
        $this->assertFalse($selector->calendarSupportsVevent([self::SET => 42]));
        $this->assertFalse($selector->calendarSupportsVevent([]));
        $this->assertFalse($selector->calendarSupportsVevent([self::SET => ['VTODO']]));
    }//end testComponentSetsAreReadFromStringsListsAndValueObjects()
}//end class
