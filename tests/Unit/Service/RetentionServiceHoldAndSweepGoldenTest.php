<?php

/**
 * Golden test: legal holds by owner key, and the disposal-sweep refusals.
 *
 * Pins, on the code before the ADR-011 refactor of RetentionService, what a
 * hold placed or released by owner key leaves behind (allowed and refused:
 * a release with nothing held changes nothing) and which object a transfer
 * sweep accepts or refuses. No clock is pinned: the hold stamps are read for
 * who placed them, never for when.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\DisposalSweep;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\RetentionRowScanner;
use OCA\OpenRegister\Service\Archival\SelectielijstResolver;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Service\RetentionRules;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\RetentionStores;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden outcomes of legal holds by owner key and the sweep refusals.
 */
class RetentionServiceHoldAndSweepGoldenTest extends TestCase {

	private IUserSession&MockObject $userSession;
	private RetentionRowScanner&MockObject $rowScanner;
	private RetentionService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->rowScanner = $this->createMock(RetentionRowScanner::class);

		$this->service = new RetentionService(
			new RetentionStores(
				new SchemaCatalog(
					registers: $this->createMock(RegisterMapper::class),
					schemas: $this->createMock(SchemaMapper::class),
					tables: $this->createMock(MagicMapper::class)
				),
				$this->createMock(AuditTrailMapper::class)
			),
			new RetentionRules(
				$this->createMock(ObjectRetentionHandler::class),
				$this->rowScanner,
				$this->createMock(ArchiveActionDateCalculator::class),
				$this->createMock(SelectielijstResolver::class)
			),
			$this->userSession,
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Two matters hold one record; releasing one by its key keeps the other.
	 */
	public function testAHoldReleasedByOwnerKeyLeavesTheOtherMattersHold(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('archivaris');
		$this->userSession->method('getUser')->willReturn($user);

		$object = new ObjectEntity();
		$object->setRetention([]);
		$this->service->placeLegalHold($object, 'Bezwaar 12', 'matter-a');
		$this->service->placeLegalHold($object, 'WOO-verzoek 7', 'matter-b');
		$this->service->releaseLegalHold($object, 'Bezwaar afgehandeld', 'matter-a');

		$legalHold = $object->getRetention()['legalHold'];
		$this->assertTrue($legalHold['active']);
		$this->assertTrue($object->hasActiveLegalHold());
		$this->assertSame(['WOO-verzoek 7'], array_column($legalHold['holds'], 'reason'));
		$this->assertSame(['archivaris'], array_column($legalHold['holds'], 'placedBy'));
		$this->assertSame(['Bezwaar afgehandeld'], array_column($legalHold['history'], 'releaseReason'));
	}//end testAHoldReleasedByOwnerKeyLeavesTheOtherMattersHold()

	/**
	 * A release without an owner key releases every hold, and is stamped 'system' without a user.
	 */
	public function testAReleaseWithoutOwnerKeyReleasesEveryHold(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$object = new ObjectEntity();
		$object->setRetention([]);
		$this->service->placeLegalHold($object, 'Bezwaar 12', 'matter-a');
		$this->service->placeLegalHold($object, 'WOO-verzoek 7', 'matter-b');
		$this->service->releaseLegalHold($object, 'Alles afgehandeld');

		$legalHold = $object->getRetention()['legalHold'];
		$this->assertFalse($legalHold['active']);
		$this->assertFalse($object->hasActiveLegalHold());
		$this->assertCount(2, $legalHold['history']);
		$this->assertSame(['system', 'system'], array_column($legalHold['history'], 'placedBy'));
	}//end testAReleaseWithoutOwnerKeyReleasesEveryHold()

	/**
	 * A release on a record that holds nothing changes nothing.
	 */
	public function testAReleaseWithNothingHeldChangesNothing(): void {
		$this->userSession->expects($this->never())->method('getUser');

		$object = new ObjectEntity();
		$object->setRetention(['archiefnominatie' => 'vernietigen']);

		$this->assertSame($object, $this->service->releaseLegalHold($object, 'Niets', 'matter-a'));
		$this->assertSame(['archiefnominatie' => 'vernietigen'], $object->getRetention());
	}//end testAReleaseWithNothingHeldChangesNothing()

	/**
	 * Which objects the transfer and destruction sweeps accept, and which they refuse.
	 */
	public function testTheSweepsAcceptAndRefuseTheSameObjectsAsBefore(): void {
		$past = ['archiefactiedatum' => '2000-01-01'];
		$cases = [
			'transfer-ok'      => ['archiefnominatie' => 'blijvend_bewaren'] + $past,
			'transfer-listed'  => ['archiefnominatie' => 'blijvend_bewaren'] + $past,
			'transfer-held'    => ['archiefnominatie' => 'blijvend_bewaren', 'legalHold' => ['active' => true]] + $past,
			'transfer-moved'   => ['archiefnominatie' => 'blijvend_bewaren', 'archiefstatus' => 'overgebracht'] + $past,
			'destroy-ok'       => ['archiefnominatie' => 'vernietigen'] + $past,
			'destroy-listed'   => ['archiefnominatie' => 'vernietigen'] + $past,
			'destroy-held'     => ['archiefnominatie' => 'vernietigen', 'legalHold' => ['active' => true]] + $past,
			'destroy-not-due'  => ['archiefnominatie' => 'vernietigen', 'archiefactiedatum' => '2999-01-01'],
		];
		$objects = [];
		foreach ($cases as $uuid => $retention) {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$object->setRetention($retention);
			$objects[] = $object;
		}

		$this->rowScanner->method('scan')->willReturnCallback(
			static fn (callable $accept): array => ['objects' => array_values(array_filter($objects, $accept)), 'scanned' => count($objects), 'truncated' => false]
		);

		$uuids = static fn (array $found): array => array_map(static fn (ObjectEntity $object): string => $object->getUuid(), $found);
		$this->assertSame(['transfer-ok'], $uuids($this->service->findEligibleForTransfer(['transfer-listed'])));
		$this->assertSame(['destroy-ok'], $uuids($this->service->findEligibleForDestruction(['destroy-listed'])));
		$this->assertSame(
			[RetentionService::REFUSAL_ALREADY_LISTED, RetentionService::REFUSAL_LEGAL_HOLD, RetentionService::REFUSAL_NOT_DUE, null],
			[
				$this->service->destructionRefusalIn($objects[5], new DisposalSweep('2026-01-01', ['destroy-listed'])),
				$this->service->destructionRefusalIn($objects[6], new DisposalSweep('2026-01-01', [])),
				$this->service->destructionRefusalIn($objects[7], new DisposalSweep('2026-01-01', [])),
				$this->service->destructionRefusalIn($objects[4], new DisposalSweep('2026-01-01', ['destroy-listed'])),
			]
		);
	}//end testTheSweepsAcceptAndRefuseTheSameObjectsAsBefore()
}//end class
