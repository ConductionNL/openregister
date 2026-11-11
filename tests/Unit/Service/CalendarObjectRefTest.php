<?php

/**
 * Unit test for CalendarObjectRef, the address of one stored calendar object.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
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

namespace OCA\OpenRegister\Tests\Unit\Service;

use Error;
use OCA\OpenRegister\Service\CalendarObjectRef;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\CalendarObjectRef
 */
class CalendarObjectRefTest extends TestCase
{
    public function testTheAddressIsFixedOnceMade(): void
    {
        $ref = new CalendarObjectRef(calendarId: 12, uri: 'or-obj-1.ics');

        $this->assertSame([12, 'or-obj-1.ics'], [$ref->calendarId, $ref->uri]);
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $ref->uri = 'other.ics';
    }//end testTheAddressIsFixedOnceMade()
}//end class
