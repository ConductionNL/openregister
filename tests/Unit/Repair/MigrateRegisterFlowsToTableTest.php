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

	/**
	 * The config the step actually handed to `ObjectService::findAll()`.
	 *
	 * @var array<string, mixed>
	 */
	private array $askedFor = [];

	protected function setUp(): void {
		$this->flowMapper    = $this->createMock(FlowMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->output        = $this->createMock(IOutput::class);

		$this->said = [];
		$this->askedFor = [];
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
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config = []) use ($objects): array {
				$this->askedFor = $config;
				return ['results' => $objects];
			}
		);
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

	/**
	 * 🔴 THE READ MUST BE SCOPED, AND THE SCOPE MUST BE WHERE THE SERVICE LOOKS.
	 *
	 * The step asked for `['register' => 'flows', 'schema' => 'flow']` at the TOP
	 * LEVEL of the config. `ObjectService::prepareFindAllConfig()` only reads
	 * `$config['filters']['register']` and `$config['filters']['schema']`, so
	 * those two keys were inert: `setRegister()` / `setSchema()` were never
	 * called and `findAll()` ran against whatever `$currentRegister` /
	 * `$currentSchema` the SHARED service instance happened to be carrying.
	 *
	 * That is not hypothetical. `saveObject()` sets the context and — unlike
	 * `find()`, which restores it in a `finally` — never puts it back, and
	 * `ImportCredentialBrokerRegister` runs four repair steps before this one and
	 * saves two example objects through it. The context this step inherited was
	 * therefore `credential-broker` / `brokeredcredential`, and it read those two
	 * examples as if they were flows.
	 *
	 * Every other `findAll()` caller in `lib/` nests the pair under `filters`.
	 * This step was the outlier, and no test looked at the argument, because
	 * every fixture mocked `findAll()` to return rows regardless of what was
	 * asked for. A mock that answers any question cannot report a wrong one.
	 */
	public function testTheReadIsScopedWhereTheServiceActuallyLooks(): void {
		$this->serve([]);

		$this->step->run($this->output);

		$this->assertSame(
			'flows',
			($this->askedFor['filters']['register'] ?? null),
			'the register must be requested under `filters`, which is the only place ObjectService reads it'
		);
		$this->assertSame(
			'flow',
			($this->askedFor['filters']['schema'] ?? null),
			'and so must the schema — a top-level key here is silently ignored'
		);
	}

	/**
	 * 🔴 THE ROW THAT SHIPPED. Both of `credential_broker_register.json`'s
	 * example objects landed in `openregister_flows` on a real instance: empty
	 * nodes, empty edges, no trigger, no trigger schema, `_owner` = `__system__`
	 * because the import that wrote them ran sessionless.
	 *
	 * They are not flows and can never become flows. They sit in the table
	 * forever, appear wherever flows are listed, and — measured — misled a
	 * diagnosis into reading `owner=__system__` as OpenRegister's own convention
	 * for a shipped flow, which is an artefact, not a precedent.
	 *
	 * The scoping fix above stops the wrong POPULATION being read. This asserts
	 * the second, independent guard: even handed such a row, the step must
	 * refuse to write it. A row with no nodes, no edges and no trigger has
	 * nothing to walk and nothing to start it.
	 */
	public function testACredentialBrokerExampleIsNeverWrittenAsAFlow(): void {
		$example = new ObjectEntity();
		$example->setUuid('brokered-1');
		$example->setOwner('__system__');
		$example->setObject(
			[
				'name' => 'Gemeente Example — GitHub publisher',
				'provider' => 'github',
				'owner' => '00000000-0000-0000-0000-000000000000',
				'allowedApps' => ['hermiq'],
				'createdAt' => '2026-01-01T00:00:00+00:00',
			]
		);

		$this->objectService->method('findAll')->willReturn([$example]);
		$this->flowMapper->method('findByUuid')->willThrowException(new DoesNotExistException('nope'));
		$this->flowMapper->expects($this->never())->method('insert');

		$this->step->run($this->output);

		$this->assertStringContainsString('0 migrated', $this->summary());
		$this->assertStringContainsString('1 not a flow', $this->summary());
	}

	/**
	 * The negative control for the guard above: a genuine flow shares the
	 * credential-broker row's whole silhouette apart from having a graph, and it
	 * must still cross. A guard that also stopped this one would be trading one
	 * silent defect for another.
	 */
	public function testAGenuineFlowStillCrossesAlongsideTheArtefact(): void {
		$real = new ObjectEntity();
		$real->setUuid('flow-1');
		$real->setOwner('admin');
		$real->setOrganisation('org-1');
		$real->setObject(
			[
				'name' => 'Nightly sweep',
				'trigger' => 'schedule',
				'cron' => '* * * * *',
				'nodes' => [['id' => 'a']],
				'edges' => [['from' => 'a', 'to' => 'b']],
			]
		);

		$artefact = new ObjectEntity();
		$artefact->setUuid('brokered-1');
		$artefact->setOwner('__system__');
		$artefact->setObject(['name' => 'Reisbureau Example — GitLab discovery', 'provider' => 'gitlab']);

		$this->objectService->method('findAll')->willReturn([$real, $artefact]);
		$this->flowMapper->method('findByUuid')->willThrowException(new DoesNotExistException('nope'));

		$written = [];
		$this->flowMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (Flow $f) use (&$written): Flow {
				$written[] = $f->getUuid();
				return $f;
			});

		$this->step->run($this->output);

		$this->assertSame(['flow-1'], $written);
		$this->assertStringContainsString('1 migrated', $this->summary());
		$this->assertStringContainsString('1 not a flow', $this->summary());
	}

	/**
	 * A flow whose graph is still empty but which already names a trigger IS a
	 * flow definition — a draft someone is part-way through authoring. The guard
	 * is "nothing to walk AND nothing to start it", not "no nodes".
	 */
	public function testAnEmptyGraphWithATriggerIsStillAFlow(): void {
		$draft = new ObjectEntity();
		$draft->setUuid('draft-1');
		$draft->setOwner('admin');
		$draft->setObject(['name' => 'Half-written', 'trigger' => 'object.created', 'nodes' => [], 'edges' => []]);

		$this->objectService->method('findAll')->willReturn([$draft]);
		$this->flowMapper->method('findByUuid')->willThrowException(new DoesNotExistException('nope'));
		$this->flowMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$this->step->run($this->output);

		$this->assertStringContainsString('1 migrated', $this->summary());
	}

	public function testNoFlowsRegisterAtAllIsNotAFailure(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('no such register'));
		$this->flowMapper->expects($this->never())->method('insert');

		$this->step->run($this->output);

		$this->assertStringContainsString('nothing to migrate', $this->summary());
	}
}
