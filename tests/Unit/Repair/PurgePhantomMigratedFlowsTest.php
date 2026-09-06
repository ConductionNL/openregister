<?php

/**
 * PurgePhantomMigratedFlowsTest.
 *
 * This step DELETES rows, and a delete is not undone by a fix in the next
 * release. So the assertions here are symmetrical on purpose: one that the
 * artefact is removed, and one for every conjunct of the predicate showing that
 * dropping that conjunct alone is enough to keep a row. A test suite that only
 * proves the deletion happens cannot tell a correct predicate from an eager one.
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

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Repair\PurgePhantomMigratedFlows;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the purge predicate in both directions.
 */
class PurgePhantomMigratedFlowsTest extends TestCase {
	private FlowMapper&MockObject $flowMapper;

	private FlowRunMapper&MockObject $flowRunMapper;

	private MagicMapper&MockObject $objectMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private IOutput&MockObject $output;

	private PurgePhantomMigratedFlows $step;

	/** @var array<int, string> */
	private array $said = [];

	/** @var array<int, string> */
	private array $deleted = [];

	protected function setUp(): void {
		$this->flowMapper    = $this->createMock(FlowMapper::class);
		$this->flowRunMapper = $this->createMock(FlowRunMapper::class);
		$this->objectMapper  = $this->createMock(MagicMapper::class);
		$this->schemaMapper  = $this->createMock(SchemaMapper::class);
		$this->output        = $this->createMock(IOutput::class);

		$this->said    = [];
		$this->deleted = [];

		$this->output->method('info')->willReturnCallback(function (string $m): void {
			$this->said[] = $m;
		});
		$this->flowMapper->method('delete')->willReturnCallback(
			function (Flow $f): Flow {
				$this->deleted[] = (string)$f->getUuid();
				return $f;
			}
		);

		// Default posture: nothing has ever run.
		$this->flowRunMapper->method('countRunsForFlow')->willReturn(0);

		$this->step = new PurgePhantomMigratedFlows(
			$this->flowMapper,
			$this->flowRunMapper,
			$this->objectMapper,
			$this->schemaMapper,
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * The row the defect actually wrote: a copy of a `brokeredcredential`
	 * example object, with the whole payload the migration could find.
	 *
	 * @param array<string, mixed> $overrides Fields to change for one case.
	 *
	 * @return Flow The candidate row.
	 */
	private function phantom(string $uuid = 'brokered-1', array $overrides = []): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setApp('openregister');
		$flow->setName('Gemeente Example — GitHub publisher');
		$flow->setEnabled(false);
		$flow->setOwner('__system__');
		$flow->setNodes([]);
		$flow->setEdges([]);

		foreach ($overrides as $field => $value) {
			$flow->{'set' . ucfirst($field)}($value);
		}

		return $flow;
	}

	/**
	 * A flow anyone would recognise as one.
	 *
	 * @param array<string, mixed> $overrides Fields to change for one case.
	 *
	 * @return Flow The row that must survive.
	 */
	private function realFlow(string $uuid = 'flow-1', array $overrides = []): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setApp('openregister');
		$flow->setName('Nightly sweep');
		$flow->setEnabled(false);
		$flow->setOwner('admin');
		$flow->setTrigger('schedule');
		$flow->setCron('0 3 * * *');
		$flow->setNodes([['id' => 'a']]);
		$flow->setEdges([['from' => 'a', 'to' => 'b']]);

		foreach ($overrides as $field => $value) {
			$flow->{'set' . ucfirst($field)}($value);
		}

		return $flow;
	}

	/**
	 * @param array<int, Flow> $flows The table contents this run sees.
	 */
	private function tableHolds(array $flows): void {
		$served = false;
		$this->flowMapper->method('findAllFlows')->willReturnCallback(
			function () use ($flows, &$served): array {
				if ($served === true) {
					return [];
				}

				$served = true;
				return $flows;
			}
		);
	}

	/**
	 * Say what register object a uuid resolves back to, and under which schema.
	 *
	 * @param array<string, string|null> $map uuid => schema slug, or null for "nothing resolves".
	 */
	private function sourcesAre(array $map): void {
		$this->objectMapper->method('findMultipleAcrossAllMagicTables')->willReturnCallback(
			function (array $uuids) use ($map): array {
				$uuid = (string)($uuids[0] ?? '');
				if (array_key_exists($uuid, $map) === false || $map[$uuid] === null) {
					return [];
				}

				$object = new ObjectEntity();
				$object->setUuid($uuid);
				$object->setSchema('schema-of-' . $uuid);
				return [$object];
			}
		);

		$this->schemaMapper->method('find')->willReturnCallback(
			function (string|int $id) use ($map): Schema {
				$uuid = str_replace('schema-of-', '', (string)$id);
				$schema = new Schema();
				$schema->setSlug($map[$uuid] ?? null);
				return $schema;
			}
		);
	}

	private function summary(): string {
		return implode(' ', $this->said);
	}

	/**
	 * 🔴 THE ARTEFACT GOES. Both `credential_broker_register.json` examples were
	 * copied into the flow table; each is a disabled, graph-less, triggerless,
	 * never-dispatched copy of a `brokeredcredential` object.
	 */
	public function testTheCredentialBrokerArtefactIsRemoved(): void {
		$this->tableHolds([$this->phantom('brokered-1'), $this->phantom('brokered-2')]);
		$this->sourcesAre(['brokered-1' => 'brokeredcredential', 'brokered-2' => 'brokeredcredential']);

		$this->step->run($this->output);

		$this->assertSame(['brokered-1', 'brokered-2'], $this->deleted);
		$this->assertStringContainsString('2 removed', $this->summary());
		$this->assertStringContainsString('0 reported', $this->summary());
	}

	/**
	 * 🔴 THE NEGATIVE CONTROL THE DELETION TEST CANNOT PROVIDE. A real flow sits
	 * in the same table, is owned by the same app and is equally disabled.
	 */
	public function testARealFlowIsSparedInTheSameRun(): void {
		$this->tableHolds([$this->phantom('brokered-1'), $this->realFlow('flow-1')]);
		$this->sourcesAre(['brokered-1' => 'brokeredcredential', 'flow-1' => 'flow']);

		$this->step->run($this->output);

		$this->assertSame(['brokered-1'], $this->deleted);
		$this->assertNotContains('flow-1', $this->deleted);
		$this->assertStringContainsString('1 removed', $this->summary());
	}

	/**
	 * One conjunct at a time. Each case is the artefact with exactly ONE field
	 * changed, and each must survive — that is what makes the predicate a
	 * conjunction rather than a hopeful list.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function sparingFieldProvider(): array {
		return [
			'a single node' => [['nodes' => [['id' => 'a']]]],
			'a single edge' => [['edges' => [['from' => 'a', 'to' => 'b']]]],
			'a trigger' => [['trigger' => 'object.created']],
			'a trigger register' => [['triggerRegister' => 'publicaties']],
			'a trigger schema' => [['triggerSchema' => 'publicatie']],
			'a cron expression' => [['cron' => '0 3 * * *']],
			'being enabled' => [['enabled' => true]],
			'a last-run uuid' => [['lastRunUuid' => 'run-9']],
			'a last-run status' => [['lastRunStatus' => 'completed']],
		];
	}

	/**
	 * @dataProvider sparingFieldProvider
	 *
	 * @param array<string, mixed> $overrides The one field that saves the row.
	 */
	public function testAnyOneOfTheseFieldsSparesTheRow(array $overrides): void {
		$this->tableHolds([$this->phantom('brokered-1', $overrides)]);
		$this->sourcesAre(['brokered-1' => 'brokeredcredential']);

		$this->step->run($this->output);

		$this->assertSame([], $this->deleted, 'this field alone must be enough to keep the row');
	}

	/**
	 * A last-run timestamp spares the row too. Kept out of the provider because
	 * the value is an object rather than a scalar.
	 */
	public function testALastRunTimestampSparesTheRow(): void {
		$this->tableHolds([$this->phantom('brokered-1', ['lastRunAt' => new DateTime()])]);
		$this->sourcesAre(['brokered-1' => 'brokeredcredential']);

		$this->step->run($this->output);

		$this->assertSame([], $this->deleted);
	}

	/**
	 * 🔴 RUN HISTORY OUTRANKS SHAPE. A row can look exactly like the artefact and
	 * still have been part of something that happened. The `lastRun*` columns
	 * were added by a later migration, so an older run row is the only trace —
	 * which is why the run table is asked as well as the columns.
	 */
	public function testARowWithRunHistoryIsReportedNotRemoved(): void {
		$run = $this->createMock(FlowRunMapper::class);
		$run->method('countRunsForFlow')->willReturn(3);
		$step = new PurgePhantomMigratedFlows(
			$this->flowMapper,
			$run,
			$this->objectMapper,
			$this->schemaMapper,
			$this->createMock(LoggerInterface::class)
		);

		$this->tableHolds([$this->phantom('brokered-1')]);
		$this->sourcesAre(['brokered-1' => 'brokeredcredential']);

		$step->run($this->output);

		$this->assertSame([], $this->deleted);
		$this->assertStringContainsString('0 removed', $this->summary());
		$this->assertStringContainsString('1 reported', $this->summary());
		$this->assertStringContainsString('run history', $this->summary());
	}

	/**
	 * 🔴 THE AMBIGUOUS CASE. An unrunnable shell whose uuid resolves to nothing
	 * might be this defect's artefact whose source object was deleted since — or
	 * an empty draft authored straight into the table. Nothing here can tell
	 * those apart, so it is named in the output and left alone.
	 */
	public function testAnUnidentifiableShellIsReportedNotRemoved(): void {
		$this->tableHolds([$this->phantom('orphan-1')]);
		$this->sourcesAre(['orphan-1' => null]);

		$this->step->run($this->output);

		$this->assertSame([], $this->deleted);
		$this->assertStringContainsString('1 reported', $this->summary());
		$this->assertStringContainsString('orphan-1', $this->summary());
	}

	/**
	 * An empty draft that IS a flow object stays. It is the shape a user gets by
	 * creating a flow and not filling it in yet, and it is theirs.
	 */
	public function testAnEmptyDraftBackedByARealFlowObjectIsSpared(): void {
		$this->tableHolds([$this->phantom('draft-1')]);
		$this->sourcesAre(['draft-1' => 'flow']);

		$this->step->run($this->output);

		$this->assertSame([], $this->deleted);
		$this->assertStringContainsString('an empty draft', $this->summary());
	}

	/**
	 * 🔴 IDEMPOTENT. This runs on every upgrade. Once the rows are gone the step
	 * must find nothing and say so, rather than reaching for the next-closest
	 * thing in the table.
	 */
	public function testASecondRunOverACleanTableRemovesNothing(): void {
		$this->tableHolds([$this->realFlow('flow-1')]);
		$this->sourcesAre(['flow-1' => 'flow']);

		$this->step->run($this->output);

		$this->assertSame([], $this->deleted);
		$this->assertStringContainsString('0 removed, 0 reported', $this->summary());
	}

	/**
	 * A read that blows up must not take the upgrade with it, and must not be
	 * reported as a clean sweep either.
	 */
	public function testAFailedReadIsSurvivedAndAnnounced(): void {
		$this->flowMapper->method('findAllFlows')->willThrowException(new RuntimeException('table gone'));
		$warned = [];
		$this->output->method('warning')->willReturnCallback(function (string $m) use (&$warned): void {
			$warned[] = $m;
		});

		$this->step->run($this->output);

		$this->assertSame([], $this->deleted);
		$this->assertNotEmpty($warned);
	}
}
