<?php

/**
 * PurgePhantomMigratedFlowsProvenanceTest.
 *
 * Pins every way the provenance proof can fail to resolve, and the exact
 * log lines each one writes, so a restructuring of the purge step keeps the
 * same verdicts, the same queries and the same diagnostics.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Repair\MigratedFlowOrigins;
use OCA\OpenRegister\Repair\PurgePhantomMigratedFlows;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the unresolvable-provenance branches and the log lines of the purge step.
 */
class PurgePhantomMigratedFlowsProvenanceTest extends TestCase {
	private FlowMapper&MockObject $flowMapper;

	private MagicMapper&MockObject $objectMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private IOutput&MockObject $output;

	private PurgePhantomMigratedFlows $step;

	/** @var array<int, string> */
	private array $said = [];

	/** @var array<int, string> */
	private array $logged = [];

	/** @var array<int, string> */
	private array $deleted = [];

	protected function setUp(): void {
		$this->flowMapper   = $this->createMock(FlowMapper::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->output       = $this->createMock(IOutput::class);

		$this->said    = [];
		$this->logged  = [];
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

		$runs = $this->createMock(FlowRunMapper::class);
		$runs->method('countRunsForFlow')->willReturn(0);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $m): void {
			$this->logged[] = $m;
		});

		$this->step = new PurgePhantomMigratedFlows(
			$this->flowMapper,
			$runs,
			new MigratedFlowOrigins($this->objectMapper, $this->schemaMapper),
			$logger
		);
	}

	/**
	 * A disabled, graph-less, triggerless candidate row.
	 *
	 * @param string $uuid The row's uuid.
	 *
	 * @return Flow The candidate row.
	 */
	private function shell(string $uuid): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setApp('openregister');
		$flow->setEnabled(false);
		$flow->setNodes([]);
		$flow->setEdges([]);

		return $flow;
	}

	/**
	 * @param array<int, Flow> $flows The table contents this run sees.
	 */
	private function tableHolds(array $flows): void {
		$this->flowMapper->method('findAllFlows')->willReturnOnConsecutiveCalls($flows, []);
	}

	/**
	 * Resolve every uuid to one object carrying the given schema reference.
	 *
	 * @param string|null $schemaRef The object's schema reference.
	 */
	private function objectCarries(?string $schemaRef): void {
		$this->objectMapper->method('findMultipleAcrossAllMagicTables')->willReturnCallback(
			function (array $uuids) use ($schemaRef): array {
				$object = new ObjectEntity();
				$object->setUuid((string)$uuids[0]);
				$object->setSchema($schemaRef);
				return [$object];
			}
		);
	}

	/**
	 * Make the schema lookup answer with this slug.
	 *
	 * @param string|null $slug The slug.
	 */
	private function schemaSlugIs(?string $slug): void {
		$this->schemaMapper->method('find')->willReturnCallback(
			function () use ($slug): Schema {
				$schema = new Schema();
				$schema->setSlug($slug);
				return $schema;
			}
		);
	}

	public function testAWhitespaceOnlyStarterDoesNotSpareTheRowAndTheRemovalIsLogged(): void {
		$flow = $this->shell('ws-1');
		$flow->setTrigger('   ');
		$flow->setCron(' ');
		$this->tableHolds([$flow]);
		$this->objectCarries('schema-7');
		$this->schemaSlugIs('brokeredcredential');

		$this->step->run($this->output);

		$this->assertSame(['ws-1'], $this->deleted);
		$this->assertSame(
			['[PurgePhantomMigratedFlows] removed ws-1: an unrunnable, never-dispatched copy of a `brokeredcredential` object'],
			$this->logged
		);
		$this->assertSame(['Phantom flow rows: 1 removed, 0 reported for review.'], $this->said);
	}

	public function testABlankUuidIsReportedWithoutAnyLookup(): void {
		$this->tableHolds([$this->shell('  ')]);
		$this->objectMapper->expects($this->never())->method('findMultipleAcrossAllMagicTables');
		$this->schemaMapper->expects($this->never())->method('find');

		$this->step->run($this->output);

		$this->assertSame([], $this->deleted);
		$this->assertSame(
			[
				'Phantom flow rows: 0 removed, 1 reported for review.',
				'  needs review:    (unrunnable, but no source object to identify it by)',
			],
			$this->said
		);
	}

	public function testAFailingObjectLookupIsLoggedAndReported(): void {
		$this->tableHolds([$this->shell('obj-err')]);
		$this->objectMapper->method('findMultipleAcrossAllMagicTables')->willThrowException(new RuntimeException('magic down'));
		$this->schemaMapper->expects($this->never())->method('find');

		$this->step->run($this->output);

		$this->assertSame([], $this->deleted);
		$this->assertSame(['[PurgePhantomMigratedFlows] could not resolve obj-err: magic down'], $this->logged);
		$this->assertSame('  needs review: obj-err (unrunnable, but no source object to identify it by)', $this->said[1]);
	}

	/**
	 * @return array<string, array{0: string|null}>
	 */
	public static function missingSchemaRefProvider(): array {
		return [
			'no schema reference' => [null],
			'a blank schema reference' => ['  '],
		];
	}

	/**
	 * @dataProvider missingSchemaRefProvider
	 *
	 * @param string|null $schemaRef The object's schema reference.
	 */
	public function testAnObjectWithoutASchemaReferenceIsReported(?string $schemaRef): void {
		$this->tableHolds([$this->shell('no-ref')]);
		$this->objectCarries($schemaRef);
		$this->schemaMapper->expects($this->never())->method('find');

		$this->step->run($this->output);

		$this->assertSame([], $this->deleted);
		$this->assertSame([], $this->logged);
		$this->assertSame('  needs review: no-ref (unrunnable, but no source object to identify it by)', $this->said[1]);
	}

	public function testAFailingSchemaLookupIsLoggedAndReported(): void {
		$this->tableHolds([$this->shell('schema-err')]);
		$this->objectCarries('schema-9');
		$this->schemaMapper->expects($this->once())->method('find')
			->with('schema-9')
			->willThrowException(new RuntimeException('gone'));

		$this->step->run($this->output);

		$this->assertSame([], $this->deleted);
		$this->assertSame(['[PurgePhantomMigratedFlows] could not resolve schema for schema-err: gone'], $this->logged);
		$this->assertSame('  needs review: schema-err (unrunnable, but no source object to identify it by)', $this->said[1]);
	}

	/**
	 * @return array<string, array{0: string|null}>
	 */
	public static function missingSlugProvider(): array {
		return [
			'no slug' => [null],
			'a blank slug' => [' '],
		];
	}

	/**
	 * @dataProvider missingSlugProvider
	 *
	 * @param string|null $slug The schema's slug.
	 */
	public function testASchemaWithoutASlugIsReported(?string $slug): void {
		$this->tableHolds([$this->shell('no-slug')]);
		$this->objectCarries('schema-3');
		$this->schemaSlugIs($slug);

		$this->step->run($this->output);

		$this->assertSame([], $this->deleted);
		$this->assertSame('  needs review: no-slug (unrunnable, but no source object to identify it by)', $this->said[1]);
	}

	public function testAnAbortedWalkIsLoggedAndStillSummarised(): void {
		$this->flowMapper->method('findAllFlows')->willThrowException(new RuntimeException('table gone'));
		$warned = [];
		$this->output->method('warning')->willReturnCallback(function (string $m) use (&$warned): void {
			$warned[] = $m;
		});

		$this->step->run($this->output);

		$this->assertSame(['[PurgePhantomMigratedFlows] aborted: table gone'], $this->logged);
		$this->assertSame(['Phantom-flow purge stopped early: table gone'], $warned);
		$this->assertSame(['Phantom flow rows: 0 removed, 0 reported for review.'], $this->said);
	}
}
