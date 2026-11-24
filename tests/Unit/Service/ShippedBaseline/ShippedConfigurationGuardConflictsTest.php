<?php

/**
 * The guard records the conflicts an import kept local, and an administrator's acceptance of one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts;
use OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator;
use OCA\OpenRegister\Service\ShippedBaseline\GuardedDescriptorMerge;
use OCA\OpenRegister\Service\ShippedBaseline\PartReset;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedDefinition;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\GuardedDescriptorMerge
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\AuthorizationBlocks
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\PartReset
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\ShippedDefinition
 * @uses \OCA\OpenRegister\Db\AuditTrail
 */
final class ShippedConfigurationGuardConflictsTest extends TestCase {

	/**
	 * Conflict lists the store was asked to record.
	 *
	 * @var array<int, ShippedDefinition>
	 */
	private array $recorded = [];

	/**
	 * Audit rows written.
	 *
	 * @var array<int, AuditTrail>
	 */
	private array $rows = [];

	/**
	 * A guard over a store that holds a conflicts record or none.
	 *
	 * @param bool $hasRecord Whether conflicts were recorded before.
	 *
	 * @return ShippedConfigurationGuard
	 */
	private function guard(bool $hasRecord): ShippedConfigurationGuard {
		$store = $this->createMock(ShippedBaselineStore::class);
		$store->method('readConflicts')->willReturn(($hasRecord === true ? ['definition' => ['conflicts' => []], 'app' => 'a', 'appVersion' => '1', 'recordedAt' => ''] : null));
		$store->method('recordConflicts')->willReturnCallback(
			function (string $slug, ShippedDefinition $conflicts): bool {
				$this->recorded[] = $conflicts;
				return true;
			}
		);

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('insertAuditTrails')->willReturnCallback(
			function (array $entries): array {
				array_push($this->rows, ...$entries);
				return $entries;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$user->method('getDisplayName')->willReturn('Beheerder');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$parts = new DescriptorParts();
		$comparator = new DivergenceComparator(parts: $parts);

		return new ShippedConfigurationGuard(
			baselines: $store,
			merge: new GuardedDescriptorMerge(parts: $parts, comparator: $comparator),
			comparator: $comparator,
			audit: $audit,
			session: $session,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end guard()

	/**
	 * Conflicts are recorded; an empty list clears an earlier record; a schema that never had any gets no row.
	 *
	 * @return void
	 */
	public function testConflictsAreRecordedAndAnEmptyListOnlyClearsAnEarlierRecord(): void {
		$some = new ShippedDefinition(definition: ['conflicts' => [['path' => 'required', 'shipped' => [], 'live' => ['a']]]], app: 'procest', appVersion: '2.0.0');
		$none = new ShippedDefinition(definition: ['conflicts' => []], app: 'procest', appVersion: '2.0.0');

		$this->assertTrue($this->guard(hasRecord: false)->recordShippedConflicts(slug: 'zaak', conflicts: $some));
		$this->assertFalse($this->guard(hasRecord: false)->recordShippedConflicts(slug: 'zaak', conflicts: $none));
		$this->assertTrue($this->guard(hasRecord: true)->recordShippedConflicts(slug: 'zaak', conflicts: $none));

		$this->assertSame([$some, $none], $this->recorded);
	}//end testConflictsAreRecordedAndAnEmptyListOnlyClearsAnEarlierRecord()

	/**
	 * An acceptance is a decision on the trail with the actor, the part and both definitions.
	 *
	 * @return void
	 */
	public function testAnAcceptanceIsADecisionNamingTheActorAndBothDefinitions(): void {
		$this->guard(hasRecord: false)->recordAcceptedConflict(slug: 'zaak', accepted: new PartReset(path: 'required', from: ['a'], to: []));

		$this->assertCount(1, $this->rows);
		$this->assertSame(ShippedConfigurationGuard::ACTION_DECIDED, $this->rows[0]->getAction());
		$this->assertSame('beheerder', $this->rows[0]->getUser());
		$this->assertSame(
			['schema' => 'zaak', 'path' => 'required', 'decision' => 'accepted-shipped', 'from' => ['a'], 'to' => []],
			$this->rows[0]->getChanged()
		);
	}//end testAnAcceptanceIsADecisionNamingTheActorAndBothDefinitions()
}//end class
