<?php

/**
 * Unit tests for AppointmentAttendeeService.
 *
 * The requirement is that the RECORD answers who is attending, so the tests
 * assert what lands on the object: the responder, the answer and the moment,
 * a second answer replacing the first rather than accumulating, and an answer
 * that is not an answer being refused.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Calendar;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Calendar\AppointmentAttendeeService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AppointmentAttendeeServiceTest extends TestCase {

	private ObjectService&MockObject $objects;
	private AppointmentAttendeeService $service;

	/**
	 * The object data the last save carried.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $saved = null;

	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectService::class);
		$this->service = new AppointmentAttendeeService(objects: $this->objects);
	}

	private function object(array $data = []): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('object-uuid');
		$object->setRegister('7');
		$object->setSchema('9');
		$object->setObject(array_merge(['title' => 'Hoorzitting'], $data));

		return $object;
	}

	private function captureSaves(): void {
		$this->objects->method('saveObject')->willReturnCallback(
			function (array | ObjectEntity $object): ObjectEntity {
				$this->saved = $object;
				if (is_array($object) === false) {
					$this->saved = $object->getObject();
				}

				return $this->object();
			}
		);
	}

	public function testAnAnswerLandsOnTheRecordWithResponderAndTime(): void {
		$this->objects->method('find')->willReturn($this->object());
		$this->captureSaves();

		$responses = $this->service->recordResponse(
			objectUuid: 'object-uuid',
			attendee: 'ana@example.org',
			status: 'accepted',
			respondedBy: 'ana',
			respondedAt: new DateTime('2026-09-14T10:00:00+02:00')
		);

		$this->assertCount(1, $responses);
		$this->assertSame('ana@example.org', $responses[0]['attendee']);
		$this->assertSame(AppointmentAttendeeService::STATUS_ACCEPTED, $responses[0]['status']);
		$this->assertSame('ana', $responses[0]['respondedBy']);
		$this->assertSame('2026-09-14T10:00:00+02:00', $responses[0]['respondedAt']);

		$this->assertNotNull($this->saved);
		$this->assertSame($responses, $this->saved[AppointmentAttendeeService::RESPONSES_PROPERTY]);
	}

	public function testThreeInviteesAnswerAndAllThreeAreOnTheRecord(): void {
		$stored = [
			['attendee' => 'ana@example.org', 'status' => 'ACCEPTED', 'respondedBy' => 'ana', 'respondedAt' => '2026-09-14T10:00:00+02:00'],
			['attendee' => 'bram@example.org', 'status' => 'ACCEPTED', 'respondedBy' => 'bram', 'respondedAt' => '2026-09-14T10:05:00+02:00'],
		];

		$this->objects->method('find')->willReturn(
			$this->object([AppointmentAttendeeService::RESPONSES_PROPERTY => $stored])
		);
		$this->captureSaves();

		$responses = $this->service->recordResponse(
			objectUuid: 'object-uuid',
			attendee: 'chris@example.org',
			status: 'DECLINED',
			respondedBy: 'chris'
		);

		$this->assertCount(3, $responses);
		$this->assertSame('DECLINED', $responses[2]['status']);
	}

	public function testASecondAnswerReplacesTheFirst(): void {
		$stored = [
			['attendee' => 'ana@example.org', 'status' => 'ACCEPTED', 'respondedBy' => 'ana', 'respondedAt' => '2026-09-14T10:00:00+02:00'],
		];

		$this->objects->method('find')->willReturn(
			$this->object([AppointmentAttendeeService::RESPONSES_PROPERTY => $stored])
		);
		$this->captureSaves();

		$responses = $this->service->recordResponse(
			objectUuid: 'object-uuid',
			attendee: 'ana@example.org',
			status: 'DECLINED'
		);

		$this->assertCount(1, $responses);
		$this->assertSame('DECLINED', $responses[0]['status']);
	}

	public function testAnAnswerThatIsNotAnAnswerIsRefused(): void {
		$this->objects->expects($this->never())->method('find');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/is not an attendee answer/');

		$this->service->recordResponse(objectUuid: 'object-uuid', attendee: 'ana@example.org', status: 'MAYBE');
	}

	public function testAnAnswerWithNoAttendeeIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->recordResponse(objectUuid: 'object-uuid', attendee: '  ', status: 'ACCEPTED');
	}

	public function testAnUnknownObjectIsRefused(): void {
		$this->objects->method('find')->willReturn(null);

		$this->expectException(InvalidArgumentException::class);

		$this->service->recordResponse(objectUuid: 'missing', attendee: 'ana@example.org', status: 'ACCEPTED');
	}

	public function testMalformedStoredEntriesAreIgnoredOnRead(): void {
		$this->objects->method('find')->willReturn(
			$this->object(
				[
					AppointmentAttendeeService::RESPONSES_PROPERTY => [
						'not-an-object',
						['status' => 'ACCEPTED'],
						['attendee' => 'ana@example.org', 'status' => 'ACCEPTED'],
					],
				]
			)
		);

		$responses = $this->service->responsesFor(objectUuid: 'object-uuid');

		$this->assertCount(1, $responses);
		$this->assertSame('ana@example.org', $responses[0]['attendee']);
	}

	public function testResponsesAreKeyedByAttendeeForAPartstatLookup(): void {
		$object = $this->object(
			[
				AppointmentAttendeeService::RESPONSES_PROPERTY => [
					['attendee' => 'ana@example.org', 'status' => 'ACCEPTED'],
					['attendee' => 'bram@example.org', 'status' => 'DECLINED'],
				],
			]
		);

		$keyed = $this->service->responsesByAttendee(object: $object);

		$this->assertSame('ACCEPTED', $keyed['ana@example.org']['status']);
		$this->assertSame('DECLINED', $keyed['bram@example.org']['status']);
	}
}
