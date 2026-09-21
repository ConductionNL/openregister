<?php

/**
 * What a refused write tells the person who made it.
 *
 * 🔴 A VERSION NUMBER IN A 409 IS ONLY USEFUL TO A MACHINE THAT WILL RETRY. The
 * refusal used to say "the object was modified since it was read" and hand back
 * two timestamps, so the only move left was reload-and-compare — and between the
 * refusal and the reload the object can change again, so what a person compares
 * is not even what they were refused over.
 *
 * 🔴 THE INTERSECTION IS THE WHOLE DESIGN, AND IT FAILS QUIETLY IN BOTH
 * DIRECTIONS. Report too much and the dialog lists fields nobody touched, which
 * is how people learn to click through it. Report too little and a real
 * collision is invisible. So there are two tests either side of it: a property
 * the caller did not change is not listed, and a property nobody else changed
 * is not listed.
 *
 * 🔴 A REFUSAL IS NOT A READ. An error path that discloses more than the read
 * path is a security bug that looks like a feature, and it is the shape nobody
 * reviews because it only appears when something went wrong.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\ConflictReport;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\ConflictReport
 *
 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md
 */
final class ConflictReportTest extends TestCase {

	private PropertyRbacHandler&MockObject $rbac;

	/**
	 * Everything is readable unless a test says otherwise.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rbac = $this->createMock(PropertyRbacHandler::class);
		$this->rbac->method('canReadProperty')->willReturn(true);
	}

	/**
	 * The service under test.
	 *
	 * @return ConflictReport The service.
	 */
	private function report(): ConflictReport {
		return new ConflictReport($this->rbac);
	}

	/**
	 * The object as it stands now.
	 *
	 * @param array<string, mixed> $values The stored values.
	 *
	 * @return ObjectEntity The object.
	 */
	private function stored(array $values): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setObject($values);

		return $object;
	}

	/**
	 * One intervening change.
	 *
	 * @param array<string, array<string, mixed>> $changed The delta.
	 * @param string                              $user    Who made it.
	 * @param string                              $at      When.
	 *
	 * @return AuditTrail The entry.
	 */
	private function change(array $changed, string $user = 'bram', string $at = '2026-09-18T12:00:00+00:00'): AuditTrail {
		$entry = new AuditTrail();
		$entry->setObjectUuid('obj-1');
		$entry->setChanged($changed);
		$entry->setUser($user);
		$entry->setUserName(ucfirst($user));
		$entry->setCreated(new DateTime($at));

		return $entry;
	}

	/**
	 * 🔴 The second person is told what the first one wrote.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-a-refused-write-names-the-values-that-conflict-req-cso-001
	 */
	public function testTheSecondPersonIsToldWhatTheFirstOneWrote(): void {
		$body = $this->report()->build(
			stored: $this->stored(['status' => 'granted', 'summary' => 'as read']),
			schema: new Schema(),
			sent: ['status' => 'refused', 'summary' => 'my new summary'],
			intervening: [$this->change(changed: ['status' => ['old' => 'in-behandeling', 'new' => 'granted']])],
		);

		self::assertSame(ConflictReport::CODE, $body['code'], 'the machine-readable code');
		self::assertSame(
			ConflictReport::ERROR,
			$body['error'],
			'and the sentence clients have always branched on, unchanged'
		);
		self::assertArrayHasKey('status', $body['conflicts']);

		$status = $body['conflicts']['status'];
		self::assertSame('refused', $status['sent'], 'what I tried to write');
		self::assertSame('in-behandeling', $status['read'], 'what was there when I read it');
		self::assertSame('granted', $status['stored'], 'what is there now');
	}

	/**
	 * 🔴 A property somebody else changed but the caller did not is NOT listed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-a-refused-write-names-the-values-that-conflict-req-cso-001
	 */
	public function testAnUntouchedPropertyIsNotAConflict(): void {
		$body = $this->report()->build(
			stored: $this->stored(['status' => 'granted', 'summary' => 'as read']),
			schema: new Schema(),
			// The caller writes ONLY the summary.
			sent: ['summary' => 'my new summary'],
			intervening: [$this->change(changed: ['status' => ['old' => 'in-behandeling', 'new' => 'granted']])],
		);

		self::assertSame(
			[],
			array_keys($body['conflicts']),
			'reporting the whole object is how people learn to click through the dialog'
		);
	}

	/**
	 * 🔴 A property the caller changed that nobody else touched is NOT listed.
	 *
	 * The other half of the intersection. Without this test, an implementation
	 * that listed everything the caller sent would still pass the one above.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-a-refused-write-names-the-values-that-conflict-req-cso-001
	 */
	public function testAPropertyOnlyTheCallerChangedIsNotAConflict(): void {
		$body = $this->report()->build(
			stored: $this->stored(['status' => 'granted', 'summary' => 'as read']),
			schema: new Schema(),
			sent: ['status' => 'refused', 'summary' => 'my new summary'],
			intervening: [$this->change(changed: ['status' => ['old' => 'in-behandeling', 'new' => 'granted']])],
		);

		self::assertArrayNotHasKey('summary', $body['conflicts']);
	}

	/**
	 * Sending a value back unchanged is agreement, not a conflict.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-a-refused-write-names-the-values-that-conflict-req-cso-001
	 */
	public function testSendingTheStoredValueBackIsNotAConflict(): void {
		$body = $this->report()->build(
			stored: $this->stored(['status' => 'granted']),
			schema: new Schema(),
			// The caller happens to be writing exactly what is already there.
			sent: ['status' => 'granted'],
			intervening: [$this->change(changed: ['status' => ['old' => 'in-behandeling', 'new' => 'granted']])],
		);

		self::assertSame([], array_keys($body['conflicts']));
	}

	/**
	 * 🔴 The refusal names who changed it and when.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-a-refused-write-names-the-values-that-conflict-req-cso-001
	 */
	public function testTheRefusalNamesTheActorAndTheMoment(): void {
		$body = $this->report()->build(
			stored: $this->stored(['status' => 'granted']),
			schema: new Schema(),
			sent: ['status' => 'refused'],
			intervening: [
				$this->change(
					changed: ['status' => ['old' => 'in-behandeling', 'new' => 'granted']],
					user: 'bram',
					at: '2026-09-18T12:34:56+00:00'
				),
			],
		);

		self::assertSame('Bram', $body['changedBy']);
		self::assertStringStartsWith('2026-09-18T12:34:56', (string)$body['changedAt']);
	}

	/**
	 * 🔴 The READ value is the oldest one, across several intervening writes.
	 *
	 * Two people wrote after the caller read. What the caller was looking at is
	 * the `old` of the EARLIEST of those, not of the most recent, and taking
	 * the wrong one shows them a value they never saw.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-a-refused-write-names-the-values-that-conflict-req-cso-001
	 */
	public function testTheReadValueIsTheOldestAcrossSeveralWrites(): void {
		$body = $this->report()->build(
			stored: $this->stored(['status' => 'granted']),
			schema: new Schema(),
			sent: ['status' => 'refused'],
			// NEWEST FIRST, which is how the mapper answers.
			intervening: [
				$this->change(changed: ['status' => ['old' => 'toetsing', 'new' => 'granted']], at: '2026-09-18T12:30:00+00:00'),
				$this->change(changed: ['status' => ['old' => 'in-behandeling', 'new' => 'toetsing']], at: '2026-09-18T12:10:00+00:00'),
			],
		);

		self::assertSame(
			'in-behandeling',
			$body['conflicts']['status']['read'],
			'what the caller saw, not the value the last writer replaced'
		);
		self::assertSame('granted', $body['conflicts']['status']['stored']);
	}

	/**
	 * 🔴 A property the caller may not read is NAMED, with no values.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-the-conflict-body-discloses-no-more-than-a-read-would-req-cso-002
	 */
	public function testARestrictedPropertyConflictsWithoutShowingItself(): void {
		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('canReadProperty')->willReturnCallback(
			static fn (Schema $schema, string $property, array $object): bool => ($property !== 'bsn')
		);

		$body = (new ConflictReport($rbac))->build(
			stored: $this->stored(['bsn' => '999993653', 'status' => 'granted']),
			schema: new Schema(),
			sent: ['bsn' => '111222333', 'status' => 'refused'],
			intervening: [
				$this->change(
					changed: [
						'bsn' => ['old' => '123456782', 'new' => '999993653'],
						'status' => ['old' => 'in-behandeling', 'new' => 'granted'],
					]
				),
			],
		);

		self::assertArrayHasKey('bsn', $body['conflicts'], 'they are told their write collided');
		self::assertSame(ConflictReport::WITHHELD, $body['conflicts']['bsn']['status']);
		self::assertArrayNotHasKey('sent', $body['conflicts']['bsn']);
		self::assertArrayNotHasKey('read', $body['conflicts']['bsn']);
		self::assertArrayNotHasKey('stored', $body['conflicts']['bsn']);

		// And nothing of the restricted value is anywhere in the body.
		$encoded = json_encode($body);
		self::assertStringNotContainsString('999993653', $encoded);
		self::assertStringNotContainsString('123456782', $encoded);

		// The control: the readable property still carries its three readings,
		// so the filter narrowed rather than emptied.
		self::assertSame('in-behandeling', $body['conflicts']['status']['read']);
	}

	/**
	 * A numeric round-trip is not a conflict.
	 *
	 * A client that sends `"3"` where the store holds `3` has changed nothing,
	 * and reporting it is how the dialog becomes noise people click through.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-a-refused-write-names-the-values-that-conflict-req-cso-001
	 */
	public function testAScalarRoundTripIsNotAConflict(): void {
		$body = $this->report()->build(
			stored: $this->stored(['count' => 3]),
			schema: new Schema(),
			sent: ['count' => '3'],
			intervening: [$this->change(changed: ['count' => ['old' => 1, 'new' => 3]])],
		);

		self::assertSame([], array_keys($body['conflicts']));
	}

	/**
	 * With nothing conflicting the body still refuses, and says why plainly.
	 *
	 * The version moved, so the write is still refused; there is simply nothing
	 * to choose between. A body that claimed a conflict it could not name would
	 * send somebody looking for a field that is not there.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
	 */
	public function testNoOverlapStillRefusesAndSaysSoPlainly(): void {
		$body = $this->report()->build(
			stored: $this->stored(['status' => 'granted']),
			schema: new Schema(),
			sent: ['summary' => 'mine'],
			intervening: [$this->change(changed: ['status' => ['old' => 'x', 'new' => 'granted']])],
		);

		self::assertSame([], $body['conflicts']);
		self::assertStringContainsString('changed since you read it', $body['message']);
		self::assertStringNotContainsString('somebody else wrote the same', $body['message']);
	}
}//end class
