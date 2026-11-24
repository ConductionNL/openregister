<?php

/**
 * The audit trail keeps the act and loses the values, and the chain verifies afterwards.
 *
 * The trail is held in memory and driven through the real AuditDiffStore and
 * the real AuditHashService, so "the chain verifies" here is the service's own
 * verifyChain() walking the rows the act left behind, not a stand-in for it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use OCA\OpenRegister\Db\AuditTrail\AuditDiffStore;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\Archival\AnonymisationAuthority;
use OCA\OpenRegister\Service\Archival\AnonymisationPlan;
use OCA\OpenRegister\Service\Archival\AnonymisationRefusedException;
use OCA\OpenRegister\Service\Archival\AnonymisationRun;
use OCA\OpenRegister\Service\Archival\AnonymisedPayload;
use OCA\OpenRegister\Service\Archival\AuditDiffRedactionTarget;
use OCA\OpenRegister\Service\Archival\AuditTrailAnonymisationTarget;
use OCA\OpenRegister\Service\Archival\TargetWriteRun;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The trail target over an in-memory audit table.
 */
#[CoversClass(AuditTrailAnonymisationTarget::class)]
#[CoversClass(AnonymisationAuthority::class)]
#[CoversClass(AuditDiffStore::class)]
#[UsesClass(AuditHashService::class)]
#[UsesClass(AuditTrail::class)]
#[UsesClass(AuditDiffRedactionTarget::class)]
#[UsesClass(AnonymisationRun::class)]
#[UsesClass(AnonymisationPlan::class)]
#[UsesClass(AnonymisedPayload::class)]
#[UsesClass(AnonymisationRefusedException::class)]
#[UsesClass(TargetWriteRun::class)]
final class AuditTrailAnonymisationTargetTest extends TestCase {
	use InMemoryAuditTableTrait;

	private const UUID = 'b6a1c2d3-0000-4000-8000-000000000001';

	private const OTHER = 'other-record';

	/** @var bool Whether the seal lock is held by somebody else. */
	private bool $lockTaken = false;

	protected function setUp(): void {
		$this->auditRows = [];
		$this->lockTaken = false;
		$rows = [
			[1, self::OTHER, 'create', ['name' => ['old' => null, 'new' => 'Jan Jansen']]],
			[2, self::UUID, 'create', ['name' => ['old' => null, 'new' => 'Jan Jansen'], 'status' => ['old' => null, 'new' => 'open']]],
			[3, self::OTHER, 'update', ['status' => ['old' => 'open', 'new' => 'gesloten']]],
			[4, self::UUID, 'update', ['name' => ['old' => 'Jan Jansen', 'new' => 'J. Jansen']]],
			[5, self::UUID, 'update', ['status' => ['old' => 'open', 'new' => 'afgehandeld']]],
		];
		foreach ($rows as [$id, $uuid, $action, $changed]) {
			$this->seedAuditRow(
				[
					'id' => $id, 'uuid' => 'row-'.$id, 'object_uuid' => $uuid, 'action' => $action, 'changed' => $changed,
					'user' => 'admin', 'user_name' => 'Admin', 'created' => '2026-01-0'.$id.' 10:00:00',
					'hash' => null, 'previous_hash' => null, 'purged_at' => null,
				]
			);
		}

		$this->hashes()->rechainAll();
		$this->assertTrue($this->hashes()->verifyChain()['valid'], 'the seeded chain verifies before the act');
	}//end setUp()

	public function testTheChainVerifiesAfterTheResealAndTheActIsOnIt(): void {
		$marker = (new AnonymisationRun())->apply(plan: $this->plan(), targets: [$this->target()]);

		$this->assertSame('complete', $marker['state']);
		$report = $this->hashes()->verifyChain();
		$this->assertTrue($report['valid'], 'the chain verifies after the re-seal');
		$this->assertSame(6, $report['entriesVerified'], 'every row, the act included, is sealed and verified');
		$this->assertSame(0, $report['skippedNullHashes']);

		$act = $this->auditRows[6];
		$this->assertSame(AuditTrailAnonymisationTarget::ACTION, $act['action']);
		$this->assertSame(self::UUID, $act['object_uuid']);
		$this->assertSame(42, $act['object']);
		$this->assertSame('retention-pass', $act['user']);
		$this->assertSame(
			[
				'profile' => 'client',
				'properties' => ['name'],
				'kept' => ['status'],
				'saltFingerprint' => 'abc123abc123',
				'decision' => 'destruction list L-7, entry 3: anonymise',
				'redactedEntries' => [2, 4],
				'chainBeforeRedaction' => ['valid' => true, 'brokenAt' => null],
			],
			json_decode($act['changed'], true)['anonymisation'],
			'the entry names the act, the decision, the properties and the chain verdict taken before the rewrite'
		);
	}//end testTheChainVerifiesAfterTheResealAndTheActIsOnIt()

	public function testTheValuesAreGoneFromTheRecordsTrailAndTheShapeStays(): void {
		(new AnonymisationRun())->apply(plan: $this->plan(), targets: [$this->target()]);

		$this->assertSame(
			['name' => ['old' => '[anonymised]', 'new' => '[anonymised]'], 'status' => ['old' => null, 'new' => 'open']],
			json_decode($this->auditRows[2]['changed'], true)
		);
		$this->assertSame(['name' => ['old' => '[anonymised]', 'new' => '[anonymised]']], json_decode($this->auditRows[4]['changed'], true));
		foreach ([2, 4, 5, 6] as $id) {
			$this->assertStringNotContainsString('Jansen', (string)$this->auditRows[$id]['changed'], 'row '.$id.' holds no former name');
		}
	}//end testTheValuesAreGoneFromTheRecordsTrailAndTheShapeStays()

	public function testUnrelatedEntriesAreUnchanged(): void {
		$before = $this->auditRows;
		(new AnonymisationRun())->apply(plan: $this->plan(), targets: [$this->target()]);

		foreach ([1, 3, 5] as $id) {
			$this->assertSame($before[$id]['changed'], $this->auditRows[$id]['changed'], 'row '.$id.' keeps its diff');
			$this->assertSame($before[$id]['uuid'], $this->auditRows[$id]['uuid']);
		}

		$this->assertSame($before[1]['hash'], $this->auditRows[1]['hash'], 'a row before the first rewrite keeps its seal');
		$this->assertStringContainsString('Jan Jansen', (string)$this->auditRows[1]['changed'], 'another record keeps its own history');
	}//end testUnrelatedEntriesAreUnchanged()

	public function testWithoutTheResealTheRewriteWouldBreakTheChain(): void {
		$store = new AuditDiffStore($this->auditConnection());
		$store->rewriteDiff(id: 4, changed: ['name' => ['old' => '[anonymised]', 'new' => '[anonymised]']]);

		$this->assertSame(4, $this->hashes()->verifyChain()['brokenAt'], 'a rewritten diff no longer matches its seal');

		$this->hashes()->resealFrom(fromId: 4);
		$this->assertTrue($this->hashes()->verifyChain()['valid']);
		$this->assertSame('row-4', $this->auditRows[4]['uuid']);
	}//end testWithoutTheResealTheRewriteWouldBreakTheChain()

	public function testABrokenChainIsRecordedNotHiddenByTheReseal(): void {
		$this->auditRows[3]['changed'] = '{"status":{"old":"open","new":"tampered"}}';

		(new AnonymisationRun())->apply(plan: $this->plan(), targets: [$this->target()]);

		$entry = json_decode($this->auditRows[6]['changed'], true)['anonymisation'];
		$this->assertSame(['valid' => false, 'brokenAt' => 3], $entry['chainBeforeRedaction'], 'the verdict is the one taken before the rewrite');
		$this->assertSame(
			$this->hashOf(id: 1),
			$this->auditRows[2]['previous_hash'],
			'the re-seal starts at the first rewritten row, chained onto the row before it'
		);
		$this->assertTrue(
			$this->hashes()->verifyChain()['valid'],
			'a re-seal makes a broken tail verify too, which is why the verdict taken before it is written into the act'
		);
	}//end testABrokenChainIsRecordedNotHiddenByTheReseal()

	public function testARecordWithNoTrailStillGetsItsActEntrySealed(): void {
		$plan = $this->plan(objectUuid: 'record-without-trail');

		(new AnonymisationRun())->apply(plan: $plan, targets: [$this->target()]);

		$entry = json_decode($this->auditRows[6]['changed'], true)['anonymisation'];
		$this->assertSame([], $entry['redactedEntries']);
		$this->assertNull($entry['chainBeforeRedaction'], 'no row was rewritten, so no verdict was needed');
		$this->assertTrue($this->hashes()->verifyChain()['valid']);
		$this->assertNotNull($this->auditRows[6]['hash'], 'the act entry is sealed onto the chain');
	}//end testARecordWithNoTrailStillGetsItsActEntrySealed()

	public function testARemovedValueSurvivingUnderAnotherPropertyRefusesBeforeAnythingIsWritten(): void {
		$this->auditRows[5]['changed'] = '{"status":{"old":"open","new":"afgehandeld"},"note":{"old":null,"new":"called Jan Jansen"}}';
		$before = $this->auditRows;

		try {
			(new AnonymisationRun())->apply(plan: $this->plan(), targets: [$this->target()]);
			$this->fail('A redaction that leaves the name under another property must not run.');
		} catch (AnonymisationRefusedException $refusal) {
			$this->assertStringContainsString('the audit trail could not be reached', $refusal->getMessage());
			$this->assertStringContainsString('audit row 5 would still hold 1 removed value(s)', $refusal->getMessage());
			$this->assertStringNotContainsString('Jan Jansen', $refusal->getMessage(), 'the refusal never quotes the value');
		}

		$this->assertSame($before, $this->auditRows, 'nothing was written');
	}//end testARemovedValueSurvivingUnderAnotherPropertyRefusesBeforeAnythingIsWritten()

	public function testAValueTheRecordItselfKeepsIsNotARefusal(): void {
		$this->auditRows[5]['changed'] = '{"status":{"old":"open","new":"afgehandeld"},"city":{"old":null,"new":"Utrecht"}}';
		$plan = new AnonymisationPlan(
			objectUuid: self::UUID,
			profileName: 'client',
			payload: new AnonymisedPayload(
				before: ['name' => 'Utrecht', 'city' => 'Utrecht'],
				after: ['name' => null, 'city' => 'Utrecht'],
				changedProperties: ['name'],
				keptProperties: ['city']
			),
			saltFingerprint: 'abc123abc123'
		);

		$marker = (new AnonymisationRun())->apply(plan: $plan, targets: [$this->target()]);

		$this->assertSame('complete', $marker['state']);
		$this->assertStringContainsString('Utrecht', (string)$this->auditRows[5]['changed'], 'the kept city stays in its diff');
	}//end testAValueTheRecordItselfKeepsIsNotARefusal()

	public function testAnUnavailableSealLockLeavesTheActUnfinishedAndSaysSo(): void {
		$this->lockTaken = true;

		try {
			(new AnonymisationRun())->apply(plan: $this->plan(), targets: [$this->target()]);
			$this->fail('An act whose chain cannot be re-sealed must not report complete.');
		} catch (AnonymisationRefusedException $refusal) {
			$this->assertStringContainsString('was interrupted after no store was written', $refusal->getMessage());
			$this->assertStringContainsString('the audit trail failed: The audit seal lock is unavailable', $refusal->getMessage());
		}
	}//end testAnUnavailableSealLockLeavesTheActUnfinishedAndSaysSo()

	public function testANumberThatHappensToAppearElsewhereIsNotARefusal(): void {
		$this->auditRows[4]['changed'] = '{"name":{"old":"Jan Jansen","new":"J. Jansen"},"houseNumber":{"old":null,"new":12}}';
		$this->auditRows[5]['changed'] = '{"status":{"old":"open","new":"afgehandeld"},"due":{"old":null,"new":"2026-12-12"}}';
		$plan = new AnonymisationPlan(
			objectUuid: self::UUID,
			profileName: 'client',
			payload: new AnonymisedPayload(
				before: ['name' => 'J. Jansen', 'houseNumber' => 12, 'due' => '2026-12-12'],
				after: ['name' => null, 'houseNumber' => null, 'due' => '2026-12-12'],
				changedProperties: ['name', 'houseNumber'],
				keptProperties: ['due']
			),
			saltFingerprint: 'abc123abc123'
		);

		(new AnonymisationRun())->apply(plan: $plan, targets: [$this->target()]);

		$this->assertSame('[anonymised]', json_decode($this->auditRows[4]['changed'], true)['houseNumber']['new'], 'the number is still redacted');
	}//end testANumberThatHappensToAppearElsewhereIsNotARefusal()

	public function testResealRefusesAnIdThatIsNotARow(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->hashes()->resealFrom(fromId: 0);
	}//end testResealRefusesAnIdThatIsNotARow()

	public function testTheTargetNamesItself(): void {
		$this->assertSame('the audit trail', $this->target()->name());
	}//end testTheTargetNamesItself()

	private function hashOf(int $id): string {
		return (string)$this->auditRows[$id]['hash'];
	}//end hashOf()

	/**
	 * The plan: the name removed, the status kept.
	 *
	 * @param string $objectUuid The record.
	 *
	 * @return AnonymisationPlan The plan.
	 */
	private function plan(string $objectUuid = self::UUID): AnonymisationPlan {
		return new AnonymisationPlan(
			objectUuid: $objectUuid,
			profileName: 'client',
			payload: new AnonymisedPayload(
				before: ['name' => 'J. Jansen', 'status' => 'afgehandeld'],
				after: ['name' => null, 'status' => 'afgehandeld'],
				changedProperties: ['name'],
				keptProperties: ['status']
			),
			saltFingerprint: 'abc123abc123'
		);
	}//end plan()

	/**
	 * The trail target over the in-memory table.
	 *
	 * @return AuditTrailAnonymisationTarget The target.
	 */
	private function target(): AuditTrailAnonymisationTarget {
		return new AuditTrailAnonymisationTarget(
			store: new AuditDiffStore($this->auditConnection()),
			chain: $this->hashes(),
			authority: new AnonymisationAuthority(
				actorId: 'retention-pass',
				actorName: 'Retention pass',
				decision: 'destruction list L-7, entry 3: anonymise'
			),
			objectId: 42
		);
	}//end target()

	/**
	 * The real hash service over the in-memory table, with a lock that is free unless taken.
	 *
	 * @return AuditHashService The service.
	 */
	private function hashes(): AuditHashService {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willReturnCallback(function (): void {
			if ($this->lockTaken === true) {
				throw new LockedException('openregister_audit_seal');
			}
		});

		return new AuditHashService($this->auditConnection(), $locks, new NullLogger(), $this->createMock(IAppConfig::class));
	}//end hashes()
}//end class
