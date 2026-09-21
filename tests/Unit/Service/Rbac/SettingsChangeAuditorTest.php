<?php

/**
 * Who changed a setting, and what a settings row must never carry.
 *
 * Ledger row Q10.13. Object writes were on the chain and settings writes were
 * not, so an administrator could point a register at a different source, or
 * switch a guard off, and the only trace was the value itself. Every test here
 * is a way the record could be wrong while the audit page fills up:
 *
 *  - 🔴 the VALUE of a secret reaching the row. The trail is append-only and
 *    readable by auditors, so a credential written into it cannot be redacted
 *    afterwards: the feature would become a permanent disclosure of every
 *    secret it audits;
 *  - a secret being OMITTED instead of masked, which loses the one row worth
 *    having most — the credential somebody rotated;
 *  - a save that changed nothing writing rows, which fills the trail with the
 *    noise of every form submit and makes the real changes unfindable;
 *  - `"1"` over a stored `1` counting as a change, which is the same noise
 *    arriving through type juggling, because `IAppConfig` stores strings;
 *  - a failed write failing the save, when the setting has already been
 *    stored — the worst outcome, because the value moved and the trail denies
 *    it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Rbac\SettingsChangeAuditor;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the diff, the masking and the fail-soft write.
 */
class SettingsChangeAuditorTest extends TestCase {

	private AuditTrailMapper $mapper;

	private IUserSession $userSession;

	private LoggerInterface $logger;

	/** @var array<int, AuditTrail> Everything handed to the mapper. */
	private array $written = [];

	/**
	 * Set up the doubles.
	 *
	 * `onlyMethods` rather than `addMethods`: a double that can invent a method
	 * the real mapper lacks is green here and a 500 in production.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->written = [];
		$this->mapper = $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['insertAuditTrails'])
			->getMock();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the auditor, capturing what it writes.
	 *
	 * @return SettingsChangeAuditor The auditor.
	 */
	private function auditor(): SettingsChangeAuditor {
		$written = &$this->written;
		$this->mapper->method('insertAuditTrails')->willReturnCallback(
			static function (array $entries, int $chunkSize = 100) use (&$written): array {
				$written = array_merge($written, $entries);
				return $entries;
			}
		);

		return new SettingsChangeAuditor(
			mapper: $this->mapper,
			userSession: $this->userSession,
			logger: $this->logger
		);
	}//end auditor()

	/**
	 * A changed key becomes one row naming both values.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	public function testAChangedKeyBecomesOneRow(): void {
		$written = $this->auditor()->recordUpdate(
			app: 'dossiq',
			before: ['register' => 'old-register'],
			after: ['register' => 'new-register']
		);

		$this->assertSame(1, $written);

		$changed = $this->written[0]->getChanged();
		$this->assertSame(SettingsChangeAuditor::ACTION_UPDATED, $this->written[0]->getAction());
		$this->assertSame('dossiq', $changed['app']);
		$this->assertSame('register', $changed['key']);
		$this->assertSame('old-register', $changed['old']);
		$this->assertSame('new-register', $changed['new']);
		$this->assertNotEmpty($this->written[0]->getUuid());
	}//end testAChangedKeyBecomesOneRow()

	/**
	 * 🔴 A secret is recorded as changed with both values masked.
	 *
	 * The assertion this change turns on. The row must exist — the credential
	 * somebody rotated is the one worth auditing most — and it must not carry
	 * the credential.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	public function testASecretIsMaskedAndStillRecorded(): void {
		$this->auditor()->recordUpdate(
			app: 'dossiq',
			before: ['apiToken' => 'hunter2-the-old-one'],
			after: ['apiToken' => 'hunter3-the-new-one'],
			secretKeys: ['apiToken']
		);

		$this->assertCount(1, $this->written, 'the change is recorded');

		$changed = $this->written[0]->getChanged();
		$this->assertSame(SettingsChangeAuditor::MASK, $changed['old']);
		$this->assertSame(SettingsChangeAuditor::MASK, $changed['new']);
		$this->assertTrue($changed['secret']);

		$serialised = json_encode($this->written[0]->jsonSerialize());
		$this->assertStringNotContainsString('hunter2-the-old-one', $serialised);
		$this->assertStringNotContainsString('hunter3-the-new-one', $serialised);
	}//end testASecretIsMaskedAndStillRecorded()

	/**
	 * A secret being introduced reads differently from one being removed.
	 *
	 * Masking both absences to the same token would make "a credential was
	 * added" and "a credential was removed" identical rows.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	public function testAnIntroducedSecretReadsDifferentlyFromARemovedOne(): void {
		$auditor = $this->auditor();

		$introduced = $auditor->diff([], ['apiToken' => 'x'], ['apiToken']);
		$this->assertNull($introduced[0]['old']);
		$this->assertSame(SettingsChangeAuditor::MASK, $introduced[0]['new']);

		$removed = $auditor->diff(['apiToken' => 'x'], [], ['apiToken']);
		$this->assertSame(SettingsChangeAuditor::MASK, $removed[0]['old']);
		$this->assertNull($removed[0]['new']);
	}//end testAnIntroducedSecretReadsDifferentlyFromARemovedOne()

	/**
	 * 🔴 A save that changed nothing writes nothing.
	 *
	 * The control, and the one that keeps the trail readable: without it every
	 * submit of a settings form records every field it carried.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	public function testASaveThatChangedNothingWritesNothing(): void {
		$this->mapper->expects($this->never())->method('insertAuditTrails');

		$auditor = new SettingsChangeAuditor(
			mapper: $this->mapper,
			userSession: $this->userSession,
			logger: $this->logger
		);

		$this->assertSame(
			0,
			$auditor->recordUpdate(
				app: 'dossiq',
				before: ['register' => 'same', 'other' => 'unchanged'],
				after: ['register' => 'same', 'other' => 'unchanged']
			)
		);
	}//end testASaveThatChangedNothingWritesNothing()

	/**
	 * A string over an identically-valued int is not a change.
	 *
	 * `IAppConfig` stores everything as a string, so a form that posts `"1"`
	 * over a stored `1` has changed nothing and a strict comparison would
	 * record a change on every save.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	public function testTypeJugglingIsNotAChange(): void {
		$this->assertSame([], $this->auditor()->diff(['limit' => 1], ['limit' => '1']));
		$this->assertSame([], $this->auditor()->diff(['on' => true], ['on' => '1']));
	}//end testTypeJugglingIsNotAChange()

	/**
	 * Adding and removing a key both count as changes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	public function testAddingAndRemovingBothCount(): void {
		$added = $this->auditor()->diff([], ['register' => 'r']);
		$this->assertCount(1, $added);
		$this->assertNull($added[0]['old']);
		$this->assertSame('r', $added[0]['new']);

		$removed = $this->auditor()->diff(['register' => 'r'], []);
		$this->assertCount(1, $removed);
		$this->assertSame('r', $removed[0]['old']);
		$this->assertNull($removed[0]['new']);
	}//end testAddingAndRemovingBothCount()

	/**
	 * Several changed keys become several rows, one each.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	public function testEachChangedKeyGetsItsOwnRow(): void {
		$written = $this->auditor()->recordUpdate(
			app: 'dossiq',
			before: ['a' => '1', 'b' => '2', 'unchanged' => 'x'],
			after: ['a' => '9', 'b' => '8', 'unchanged' => 'x']
		);

		$this->assertSame(2, $written);
		$keys = array_map(
			static fn (AuditTrail $row): string => $row->getChanged()['key'],
			$this->written
		);
		sort($keys);
		$this->assertSame(['a', 'b'], $keys);
	}//end testEachChangedKeyGetsItsOwnRow()

	/**
	 * An import is ONE row naming what it overwrote, not one per key.
	 *
	 * An import can touch every key at once, and a row per key would describe
	 * one administrative act as forty.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	public function testAnImportIsOneRow(): void {
		$this->assertSame(
			1,
			$this->auditor()->recordImport(app: 'dossiq', forced: true, keysOverwritten: 40)
		);

		$changed = $this->written[0]->getChanged();
		$this->assertSame(SettingsChangeAuditor::ACTION_IMPORTED, $this->written[0]->getAction());
		$this->assertTrue($changed['forced']);
		$this->assertSame(40, $changed['keysOverwritten']);
	}//end testAnImportIsOneRow()

	/**
	 * The secret keys are read from the register configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	public function testSecretKeysComeFromTheConfiguration(): void {
		$secrets = $this->auditor()->secretKeysIn(
			[
				'apiToken' => ['x-openregister-secret' => true],
				'register' => ['x-openregister-secret' => false],
				'plain' => ['title' => 'Plain'],
				'notAnObject' => 'x',
			]
		);

		$this->assertSame(['apiToken'], $secrets);
	}//end testSecretKeysComeFromTheConfiguration()

	/**
	 * 🔴 A failed write does not fail the save.
	 *
	 * The setting has already been stored by the time this runs. Throwing here
	 * would report a failure for a change that in fact happened — the value
	 * moved and the trail denies it, which is worse than either alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	public function testAFailedWriteDoesNotFailTheSave(): void {
		$this->mapper->method('insertAuditTrails')->willThrowException(
			new \RuntimeException('the database went away')
		);
		$this->logger->expects($this->atLeastOnce())->method('error');

		$auditor = new SettingsChangeAuditor(
			mapper: $this->mapper,
			userSession: $this->userSession,
			logger: $this->logger
		);

		$this->assertSame(
			0,
			$auditor->recordUpdate(app: 'dossiq', before: ['a' => '1'], after: ['a' => '2'])
		);
	}//end testAFailedWriteDoesNotFailTheSave()

	/**
	 * With no session the actor is the system, not an empty string.
	 *
	 * A row whose actor is blank reads as a gap in the trail rather than as an
	 * automated change.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
	 */
	public function testWithNoSessionTheActorIsTheSystem(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->auditor()->recordUpdate(app: 'dossiq', before: ['a' => '1'], after: ['a' => '2']);

		$this->assertSame('system', $this->written[0]->getUser());
		$this->assertSame('System', $this->written[0]->getUserName());
	}//end testWithNoSessionTheActorIsTheSystem()
}//end class
