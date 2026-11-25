<?php

/**
 * Unit tests for AutomationOutcome, the consuming-app shape of an automation
 * run record, and for the outcome route's filter parameters.
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
 *
 * @spec openspec/changes/a-consuming-app-reads-what-a-notification-and-a-lifecycle-action-did/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.MissingReturn -- PHPUnit tests; the signature IS the contract.

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\AutomationRecord;
use OCA\OpenRegister\Db\AutomationRecordFilter;
use OCA\OpenRegister\Service\AutomationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * A notification answers sent with its recipient count, a lifecycle action
 * answers done or failed with its reason, and neither carries a body, an
 * address or the channel breakdown.
 *
 * @covers \OCA\OpenRegister\Service\AutomationOutcome
 * @covers \OCA\OpenRegister\Db\AutomationRecordFilter
 * @uses   \OCA\OpenRegister\Db\AutomationRecord
 */
final class AutomationOutcomeTest extends TestCase {

	private function record(string $kind, string $outcome): AutomationRecord {
		$record = new AutomationRecord();
		$record->setKind($kind);
		$record->setAutomationKey('aut-7f3c-reminder');
		$record->setName('reminder');
		$record->setRegisterId('7');
		$record->setSchemaId('12');
		$record->setObjectUuid('Z-2026-0042');
		$record->setOutcome($outcome);
		$record->setRecordedAt(new DateTime('2026-11-24 09:30:00', new DateTimeZone('UTC')));

		return $record;
	}

	public function testASentReminderAnswersItsTwoRecipients(): void {
		$record = $this->record(kind: AutomationRecord::KIND_NOTIFICATION, outcome: 'sent');
		$record->setChannels('{"email":{"sent":2,"failed":0},"inApp":{"sent":2,"failed":0}}');

		$this->assertSame(
			[
				'kind'       => 'notification',
				'key'        => 'aut-7f3c-reminder',
				'at'         => '2026-11-24T09:30:00+00:00',
				'subject'    => ['register' => '7', 'schema' => '12', 'id' => 'Z-2026-0042'],
				'outcome'    => 'sent',
				'reason'     => null,
				'recipients' => 2,
			],
			AutomationOutcome::fromRecord(record: $record)
		);
	}

	public function testRecipientsCountFailedAttemptsAndNotHeldOnes(): void {
		$record = $this->record(kind: AutomationRecord::KIND_NOTIFICATION, outcome: 'partial');
		$record->setChannels('{"email":{"sent":1,"failed":2},"inApp":{"sent":2,"failed":0},"held":{"quiet-hours":9}}');

		$this->assertSame(3, AutomationOutcome::fromRecord(record: $record)['recipients']);
	}

	public function testANotificationWithoutCountsReachedNobody(): void {
		$record = $this->record(kind: AutomationRecord::KIND_NOTIFICATION, outcome: 'skipped');
		$record->setReason('no-recipients');

		$outcome = AutomationOutcome::fromRecord(record: $record);

		$this->assertSame(0, $outcome['recipients']);
		$this->assertSame('skipped', $outcome['outcome']);
		$this->assertSame('no-recipients', $outcome['reason']);
	}

	public function testASucceededLifecycleActionIsDoneAndCarriesNoRecipients(): void {
		$record = $this->record(kind: AutomationRecord::KIND_LIFECYCLE_ACTION, outcome: 'succeeded');

		$outcome = AutomationOutcome::fromRecord(record: $record);

		$this->assertSame('lifecycle', $outcome['kind']);
		$this->assertSame('done', $outcome['outcome']);
		$this->assertArrayNotHasKey('recipients', $outcome);
	}

	public function testAFailedLifecycleActionSaysWhy(): void {
		$record = $this->record(kind: AutomationRecord::KIND_LIFECYCLE_ACTION, outcome: 'failed');
		$record->setMessage('  The case is locked by another user.  ');

		$outcome = AutomationOutcome::fromRecord(record: $record);

		$this->assertSame('failed', $outcome['outcome']);
		$this->assertSame('The case is locked by another user.', $outcome['reason']);
	}

	public function testAStoredReasonWinsOverTheMessageAndALongOneIsCut(): void {
		$record = $this->record(kind: AutomationRecord::KIND_LIFECYCLE_ACTION, outcome: 'skipped');
		$record->setReason(str_repeat('x', 500));
		$record->setMessage('not this');

		$this->assertSame(str_repeat('x', AutomationOutcome::REASON_MAX_LENGTH), AutomationOutcome::fromRecord(record: $record)['reason']);
	}

	public function testOnlySucceededLifecycleWordsAreRenamed(): void {
		$record = $this->record(kind: AutomationRecord::KIND_NOTIFICATION, outcome: 'succeeded');

		$this->assertSame('succeeded', AutomationOutcome::fromRecord(record: $record)['outcome']);
	}

	public function testNoBodyAddressOrChannelBreakdownTravels(): void {
		$record = $this->record(kind: AutomationRecord::KIND_NOTIFICATION, outcome: 'failed');
		$record->setChannels('{"email":{"sent":0,"failed":1}}');
		$record->setMessage('SMTP refused');
		$record->setEventId('evt-1');

		$outcome = AutomationOutcome::fromRecord(record: $record);

		$this->assertSame(['kind', 'key', 'at', 'subject', 'outcome', 'reason', 'recipients'], array_keys($outcome));
		$this->assertSame('SMTP refused', $outcome['reason']);
	}

	public function testKeyPrefixAndTheOutcomeKindsBecomeTheFilter(): void {
		$filter = AutomationRecordFilter::fromOutcomeParams(params: ['keyPrefix' => ' aut-7f3c ', 'key' => 'ignored', 'kind' => ' lifecycle ', 'since' => '2026-11-24T00:00:00Z', 'limit' => '10', 'offset' => '20']);

		$this->assertSame('aut-7f3c', $filter->keyPrefix);
		$this->assertSame(AutomationRecord::KIND_LIFECYCLE_ACTION, $filter->kind);
		$this->assertSame('2026-11-24T00:00:00+00:00', $filter->since?->format('c'));
		$this->assertSame(10, $filter->limit);
		$this->assertSame(20, $filter->offset);

		$this->assertSame(AutomationRecord::KIND_NOTIFICATION, AutomationRecordFilter::fromOutcomeParams(params: ['kind' => 'notification'])->kind);
		$this->assertSame(AutomationRecord::KIND_LIFECYCLE_ACTION, AutomationRecordFilter::fromOutcomeParams(params: ['kind' => 'lifecycle-action'])->kind);
	}

	public function testWithoutKeyPrefixTheKeyIsReadAndAnUnknownKindIsDropped(): void {
		$filter = AutomationRecordFilter::fromOutcomeParams(params: ['key' => 'aut-9a1d', 'kind' => 'everything']);

		$this->assertSame('aut-9a1d', $filter->keyPrefix);
		$this->assertSame('', $filter->kind);
		$this->assertSame('', AutomationRecordFilter::fromOutcomeParams(params: ['kind' => ['lifecycle']])->kind);
	}
}
