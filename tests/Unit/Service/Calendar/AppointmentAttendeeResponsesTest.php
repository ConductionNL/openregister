<?php

/**
 * Behaviour tests for AppointmentAttendeeService response reading: unknown objects and unusable attendees.
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

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Calendar\AppointmentAttendeeService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Calendar\AppointmentAttendeeService
 * @uses   \OCA\OpenRegister\Service\WriteCause
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class AppointmentAttendeeResponsesTest extends TestCase
{
    private function object(mixed $responses): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('obj-1');
        $object->setObject(['title' => 'Intake', 'attendeeResponses' => $responses]);
        return $object;
    }//end object()

    public function testAnUnknownObjectIsRefusedByUuid(): void
    {
        $objects = $this->createMock(ObjectOperationsInterface::class);
        $objects->method('find')->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("No object 'missing-uuid' to read attendee responses from.");
        (new AppointmentAttendeeService($objects))->responsesFor('missing-uuid');
    }//end testAnUnknownObjectIsRefusedByUuid()

    public function testAFoundObjectAnswersWithItsReadableResponses(): void
    {
        $response = ['attendee' => 'alice@example.org', 'status' => 'ACCEPTED'];
        $objects  = $this->createMock(ObjectOperationsInterface::class);
        $objects->expects($this->once())->method('find')->with('obj-1')->willReturn($this->object([$response, 'garbage']));

        $this->assertSame([$response], (new AppointmentAttendeeService($objects))->responsesFor('obj-1'));
    }//end testAFoundObjectAnswersWithItsReadableResponses()

    public function testResponsesWithoutAUsableAttendeeAreLeftOutOfTheLookup(): void
    {
        $alice   = ['attendee' => 'alice@example.org', 'status' => 'ACCEPTED'];
        $later   = ['attendee' => 'alice@example.org', 'status' => 'DECLINED'];
        $service = new AppointmentAttendeeService($this->createMock(ObjectOperationsInterface::class));

        $keyed = $service->responsesByAttendee(
            $this->object([$alice, ['attendee' => '', 'status' => 'ACCEPTED'], ['attendee' => 7, 'status' => 'ACCEPTED'], $later])
        );

        $this->assertSame(['alice@example.org' => $later], $keyed);
        $this->assertSame([], $service->responsesByAttendee($this->object('not a list')));
    }//end testResponsesWithoutAUsableAttendeeAreLeftOutOfTheLookup()
}//end class
