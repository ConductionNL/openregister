<?php

/**
 * Behaviour tests for ObjectDateDeclaration: refusing declarations that are not objects or have no property name.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calendar
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

namespace OCA\OpenRegister\Tests\Unit\Service\Calendar;

use OCA\OpenRegister\Exception\CalendarDateKindException;
use OCA\OpenRegister\Service\Calendar\ObjectDateDeclaration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Calendar\ObjectDateDeclaration
 * @uses   \OCA\OpenRegister\Exception\CalendarDateKindException
 * @uses   \OCA\OpenRegister\Service\Calendar\ObjectDateLayout
 * @uses   \OCA\OpenRegister\Service\Calendar\ObjectDateTerm
 */
class ObjectDateDeclarationShapeTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function nonObjectProvider(): array
    {
        return [
            'a string' => ['deadline', 'calendarProvider.dates.dueDate must be an object declaring a kind, string given.'],
            'null'     => [null, 'calendarProvider.dates.dueDate must be an object declaring a kind, null given.'],
            'a number' => [3, 'calendarProvider.dates.dueDate must be an object declaring a kind, int given.'],
        ];
    }//end nonObjectProvider()

    #[DataProvider('nonObjectProvider')]
    public function testADeclarationThatIsNotAnObjectIsRefusedNamingTheProperty(mixed $config, string $message): void
    {
        $this->expectException(CalendarDateKindException::class);
        $this->expectExceptionMessage($message);
        ObjectDateDeclaration::fromArray('dueDate', $config);
    }//end testADeclarationThatIsNotAnObjectIsRefusedNamingTheProperty()

    public function testAnEntryWithABlankPropertyNameIsRefused(): void
    {
        $this->expectException(CalendarDateKindException::class);
        $this->expectExceptionMessage('calendarProvider.dates carries an entry with no property name.');
        ObjectDateDeclaration::allFromConfig(['dates' => ['  ' => ['kind' => 'deadline']]]);
    }//end testAnEntryWithABlankPropertyNameIsRefused()

    public function testNamedEntriesAreReadWithTheirTrimmedPropertyNames(): void
    {
        $declarations = ObjectDateDeclaration::allFromConfig(['dates' => [' dueDate ' => ['kind' => 'deadline']]]);

        $this->assertCount(1, $declarations);
        $this->assertSame(['dueDate', 'deadline'], [$declarations[0]->property, $declarations[0]->kind]);
        $this->assertSame([], ObjectDateDeclaration::allFromConfig([]));
    }//end testNamedEntriesAreReadWithTheirTrimmedPropertyNames()
}//end class
