<?php

/**
 * Unit tests for SeedTablesVirtualSchemas: Tables availability guard, admin scoping and failure reporting.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Repair\SeedTablesVirtualSchemas;
use OCA\OpenRegister\Service\ObjectSource\TablesCatalogueInterface;
use OCA\OpenRegister\Service\ObjectSource\TablesSchemaReconcilerInterface;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Repair\SeedTablesVirtualSchemas
 */
final class SeedTablesVirtualSchemasTest extends TestCase {

	/** @var TablesCatalogueInterface&MockObject */
	private TablesCatalogueInterface $reader;

	/** @var TablesSchemaReconcilerInterface&MockObject */
	private TablesSchemaReconcilerInterface $sync;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groups;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->reader = $this->createMock(TablesCatalogueInterface::class);
		$this->sync = $this->createMock(TablesSchemaReconcilerInterface::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function step(): SeedTablesVirtualSchemas {
		return new SeedTablesVirtualSchemas($this->reader, $this->sync, $this->groups, $this->logger);
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	public function testNameNamesTheStep(): void {
		$this->assertSame('Seed OpenRegister Tables virtual schemas (one per Nextcloud Tables table)', $this->step()->getName());
	}

	public function testWithoutTablesNothingIsReconciled(): void {
		$this->reader->method('isAvailable')->willReturn(false);
		$this->reader->expects($this->never())->method('collectTableDescriptors');
		$this->sync->expects($this->never())->method('reconcile');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Tables app not enabled — skipping Tables virtual schema seed');

		$this->step()->run($output);
	}

	public function testTablesOfTheAdminsAreReconciledAndTheStatsReported(): void {
		$this->reader->method('isAvailable')->willReturn(true);
		$admins = $this->createMock(IGroup::class);
		$admins->method('getUsers')->willReturn([$this->user('root'), $this->user('ops')]);
		$this->groups->method('get')->with('admin')->willReturn($admins);
		$this->reader->expects($this->once())->method('collectTableDescriptors')->with(['root', 'ops'])->willReturn(['t1']);
		$this->sync->expects($this->once())->method('reconcile')->with(['t1'])
			->willReturn(['seeded' => 2, 'retired' => 1, 'skipped' => 3]);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Tables virtual schemas reconciled: seeded=2, retired=1, skipped=3');

		$this->step()->run($output);
	}

	public function testWithoutAnAdminGroupNoUsersAreScanned(): void {
		$this->reader->method('isAvailable')->willReturn(true);
		$this->groups->method('get')->willReturn(null);
		$this->reader->expects($this->once())->method('collectTableDescriptors')->with([])->willReturn([]);
		$this->sync->method('reconcile')->willReturn(['seeded' => 0, 'retired' => 0, 'skipped' => 0]);

		$this->step()->run($this->createMock(IOutput::class));
	}

	public function testAFailureIsLoggedAndReportedAsSkipped(): void {
		$this->reader->method('isAvailable')->willThrowException(new RuntimeException('tables broken'));
		$this->logger->expects($this->once())->method('warning')->with('[SeedTablesVirtualSchemas] seed failed: tables broken');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('Tables virtual schema seed skipped: tables broken');

		$this->step()->run($output);
	}
}
