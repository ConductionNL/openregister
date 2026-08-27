<?php

/**
 * MigrateRegisterFlowsToTableTest.
 *
 * A migration that runs unattended at upgrade gets one chance to be right, so
 * the assertions here are about what it MUST NOT do: duplicate a flow, enable
 * one, or drop the ownership that makes it visible.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Repair\MigrateRegisterFlowsToTable;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the migration's decision table.
 */
class MigrateRegisterFlowsToTableTest extends TestCase {
	private FlowMapper&MockObject $flowMapper;

	private ObjectService&MockObject $objectService;

	private IOutput&MockObject $output;

	private MigrateRegisterFlowsToTable $step;

	/** @var array<int, string> */
	private array $said = [];

	protected function setUp(): void {
		$this->flowMapper    = $this->createMock(FlowMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->output        = $this->createMock(IOutput::class);

		$this->said = [];
		$this->output->method('info')->willReturnCallback(function (string $m): void {
			$this->said[] = $m;
		});

		$this->step = new MigrateRegisterFlowsToTable(
			$this->flowMapper,
			$this->objectService,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function registerFlow(string $uuid, array $extra = []): array {
		return array_merge(
			[
				'@self' => ['id' => $uuid, 'owner' => 'admin', 'organisation' => 'org-1'],
				'name' => 'Nightly sweep',
				'trigger' => 'schedule',
				'cron' => '* * * * *',
				'nodes' => [['id' => 'a']],
				'edges' => [],
			],
			$extra
		);
	}

	private function serve(array $objects): void {
		$this->objectService->method('findAll')->willReturn(['results' => $objects]);
	}

	private function summary(): string {
		return implode(' ', $this->said);
	}

	public function testARegisterAuthoredFlowIsCopiedIntoTheTable(): void {
		$this->serve([$this->registerFlow('uuid-1')]);
		$this->flowMapper->method('findByUuid')->willThrowException(new DoesNotExistException('nope'));

		$captured = null;
		$this->flowMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (Flow $f) use (&$captured): Flow {
				$captured = $f;
				return $f;
			});

		$this->step->run($this->output);

		$this->assertNotNull($captured);
		// 🔴 THE UUID IS PRESERVED. A fresh id would orphan every sub-flow
		// reference and run row that already points at this flow.
		$this->assertSame('uuid-1', $captured->getUuid());
		$this->assertSame('Nightly sweep', $captured->getName());
		$this->assertStringContainsString('1 migrated', $this->summary());
	}

	/**
	 * 🔴 IDEMPOTENT. This runs on every upgrade; a second pass must not create a
	 * second copy of the same flow, which would then fire twice.
	 */
	public function testAFlowAlreadyInTheTableIsSkipped(): void {
		$this->serve([$this->registerFlow('uuid-1')]);
		$this->flowMapper->method('findByUuid')->willReturn(new Flow());
		$this->flowMapper->expects($this->never())->method('insert');

		$this->step->run($this->output);

		$this->assertStringContainsString('0 migrated', $this->summary());
		$this->assertStringContainsString('1 already', $this->summary());
	}

	/**
	 * 🔴 DISABLED ON ARRIVAL. A schedule that starts firing during an upgrade,
	 * against data nobody re-checked, is worse than one an administrator turns
	 * on. `canDispatch()` needs `enabled === true` AND an owner, so this is
	 * enforced by the entity rather than only intended.
	 */
	public function testAMigratedFlowArrivesDisabledAndCannotDispatch(): void {
		$this->serve([$this->registerFlow('uuid-1', ['enabled' => true])]);
		$this->flowMapper->method('findByUuid')->willThrowException(new DoesNotExistException('nope'));

		$captured = null;
		$this->flowMapper->method('insert')->willReturnCallback(
			function (Flow $f) use (&$captured): Flow {
				$captured = $f;
				return $f;
			}
		);

		$this->step->run($this->output);

		$this->assertFalse((bool)$captured->getEnabled(), 'a migrated flow must not arrive enabled');
		$this->assertFalse($captured->canDispatch(), 'and must therefore not dispatch');
	}

	/**
	 * Ownership carries over. A flow with no organisation is invisible to every
	 * scoped read (#2915), so a migration that dropped it would move the flow
	 * into the right store and straight out of sight.
	 */
	public function testOwnershipIsCarriedOverFromTheRegisterObject(): void {
		$this->serve([$this->registerFlow('uuid-1')]);
		$this->flowMapper->method('findByUuid')->willThrowException(new DoesNotExistException('nope'));

		$captured = null;
		$this->flowMapper->method('insert')->willReturnCallback(
			function (Flow $f) use (&$captured): Flow {
				$captured = $f;
				return $f;
			}
		);

		$this->step->run($this->output);

		$this->assertSame('admin', $captured->getOwner());
		$this->assertSame('org-1', $captured->getOrganisation());
	}

	/**
	 * One flow that cannot be written must not stop the others. A migration that
	 * aborts halfway leaves the instance in a state nobody described.
	 */
	public function testOneFailureDoesNotStopTheRest(): void {
		$this->serve([$this->registerFlow('bad'), $this->registerFlow('good')]);
		$this->flowMapper->method('findByUuid')->willThrowException(new DoesNotExistException('nope'));
		$this->flowMapper->method('insert')->willReturnCallback(
			function (Flow $f): Flow {
				if ($f->getUuid() === 'bad') {
					throw new RuntimeException('column too long');
				}
				return $f;
			}
		);

		$this->step->run($this->output);

		$this->assertStringContainsString('1 migrated', $this->summary());
		$this->assertStringContainsString('1 failed', $this->summary());
	}

	/**
	 * 🔴 THE COUNTS ARE ALWAYS STATED. "Migrated successfully" with no numbers
	 * cannot be told apart from a step that read an empty list — which is
	 * precisely how the two-store split stayed invisible.
	 */
	public function testAnInstanceWithNoRegisterFlowsStillReportsWhatItDid(): void {
		$this->serve([]);
		$this->flowMapper->expects($this->never())->method('insert');

		$this->step->run($this->output);

		$this->assertStringContainsString('0 migrated', $this->summary());
	}

	/**
	 * 🔴 THE ONE THE ARRAY FIXTURES COULD NOT CATCH.
	 *
	 * `ObjectService::findAll()` returns `ObjectEntity` OBJECTS. Every other
	 * test here hands the step arrays, because that is the shape the step's
	 * author assumed — so they exercised a code path production never takes.
	 * They stayed green while enabling the app died outright:
	 *
	 *   Error: Cannot use object of type OCA\OpenRegister\Db\ObjectEntity as
	 *   array in lib/Repair/MigrateRegisterFlowsToTable.php:173
	 *
	 * A fatal during `occ app:enable` means the app does not install at all.
	 * Only CI, which actually enables it, disagreed with the unit tests — the
	 * fixture had validated the query its author wrote rather than the one the
	 * service answers.
	 *
	 * This test therefore builds a REAL entity. Revert the `normalise()` call
	 * in the step and it fails with that same fatal, which is the property the
	 * array fixtures lack.
	 */
	public function testItReadsRealObjectEntitiesNotArrays(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-entity');
		$entity->setOwner('admin');
		$entity->setOrganisation('org-1');
		$entity->setObject(
			[
				'name' => 'Nightly sweep',
				'trigger' => 'schedule',
				'cron' => '* * * * *',
				'nodes' => [['id' => 'a']],
				'edges' => [],
			]
		);

		$this->objectService->method('findAll')->willReturn([$entity]);
		$this->flowMapper->method('findByUuid')->willThrowException(new DoesNotExistException('nope'));

		$captured = null;
		$this->flowMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (Flow $f) use (&$captured): Flow {
				$captured = $f;
				return $f;
			});

		$this->step->run($this->output);

		$this->assertNotNull($captured, 'an ObjectEntity row must be migrated, not skipped');
		$this->assertSame('uuid-entity', $captured->getUuid());
		// The payload lives behind getObject(), not behind array access.
		$this->assertSame('Nightly sweep', $captured->getName());
		$this->assertSame('schedule', $captured->getTrigger());
		// Ownership comes off the ENTITY's own accessors, not an `@self` key
		// that an ObjectEntity does not have.
		$this->assertSame('admin', $captured->getOwner());
		$this->assertSame('org-1', $captured->getOrganisation());
		$this->assertStringContainsString('1 migrated', $this->summary());
	}

	public function testNoFlowsRegisterAtAllIsNotAFailure(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('no such register'));
		$this->flowMapper->expects($this->never())->method('insert');

		$this->step->run($this->output);

		$this->assertStringContainsString('nothing to migrate', $this->summary());
	}
}
