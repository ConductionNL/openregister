<?php

/**
 * `occ openregister:schemas:prune-retired` and legally retained records.
 *
 * The prune CLI is the ONE caller allowed to cascade-delete an archival schema,
 * on the same terms `occ openregister:objects:purge --force` already sets: shell
 * access is an authorization boundary an HTTP request cannot cross, so the
 * operator may override — but only by naming the archival records out loud.
 * `--force` means "this schema still owns objects", which an operator pruning a
 * retired test schema passes without having said anything about retention; that
 * is why the archival override is its own flag.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\PruneRetiredSchemasCommand;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\SchemaDeletionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The archival override on the prune command.
 */
class PruneRetiredSchemasArchivalTest extends TestCase {

	private const SCHEMA_ID = 42;

	private const REGISTER_ID = 7;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper $registerMapper;

	/**
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper $magicMapper;

	/**
	 * @var SchemaDeletionService&MockObject
	 */
	private SchemaDeletionService $deletionService;

	private PruneRetiredSchemasCommand $command;

	/**
	 * Wire the command up with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->deletionService = $this->createMock(SchemaDeletionService::class);

		$this->command = new PruneRetiredSchemasCommand(
			$this->schemaMapper,
			$this->registerMapper,
			$this->deletionService
		);

	}//end setUp()

	/**
	 * Inject an id into an entity (Entity::$id is protected).
	 *
	 * @param object $entity The entity.
	 * @param int    $id     The id to inject.
	 *
	 * @return mixed The same entity.
	 */
	private function makeEntity(object $entity, int $id): mixed {
		$property = (new ReflectionClass($entity))->getProperty('id');
		$property->setAccessible(true);
		$property->setValue($entity, $id);

		return $entity;
	}//end makeEntity()

	/**
	 * One register holding one archival schema with two objects.
	 *
	 * The configuration is the real annotation, so the command's decision comes from
	 * `Schema::hasArchivalAnnotation()` reading real data rather than from a test copy
	 * of the condition.
	 *
	 * @param bool $archival Whether the schema declares `x-openregister-archival`.
	 *
	 * @return Schema The schema the command will resolve.
	 */
	private function stubOneRetiredSchema(bool $archival): Schema {
		$schema = $this->makeEntity(new Schema(), self::SCHEMA_ID);
		$schema->setSlug('retained-case');

		if ($archival === true) {
			$schema->setConfiguration(
				['x-openregister-archival' => ['retention' => ['default' => 'P10Y']]]
			);
		}

		$register = $this->makeEntity(new Register(), self::REGISTER_ID);
		$register->setSlug('archival-rig');
		$register->setSchemas([self::SCHEMA_ID]);

		// EVERY row under the pair, not the first: the command reads them all
		// now, because the import unions schema ids and a slug can carry two.
		$this->schemaMapper->method('findAllByApplicationAndSlug')->willReturn([$schema]);
		$this->registerMapper->method('findAll')->willReturn([$register]);
		// The guard asks the DELETION service what it would remove, rather than
		// running a second, narrower count of its own.
		$this->deletionService->method('countObjectsCascadeWouldDelete')->willReturn(2);

		return $schema;
	}//end stubOneRetiredSchema()

	/**
	 * TWO rows under one (application, slug), which is what the import leaves.
	 *
	 * @return array<int, Schema> Both schemas, in id order.
	 */
	private function stubTwoRetiredSchemas(): array {
		$first = $this->makeEntity(new Schema(), self::SCHEMA_ID);
		$first->setSlug('document');
		$second = $this->makeEntity(new Schema(), (self::SCHEMA_ID + 1));
		$second->setSlug('document');

		$register = $this->makeEntity(new Register(), self::REGISTER_ID);
		$register->setSlug('archival-rig');
		$register->setSchemas([self::SCHEMA_ID, (self::SCHEMA_ID + 1)]);

		$this->schemaMapper->method('findAllByApplicationAndSlug')->willReturn([$first, $second]);
		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->deletionService->method('countObjectsCascadeWouldDelete')->willReturn(0);

		return [$first, $second];
	}//end stubTwoRetiredSchemas()

	/**
	 * Both rows are pruned, not the first one only.
	 *
	 * The import unions schema ids and never removes one, so a descriptor edit
	 * leaves the old row behind and a later import can add a second under the
	 * same pair — which is the situation this command exists for. It read one
	 * row per run, so it removed one of two and printed `Pruned=1, skipped=0`.
	 * Measured on a live instance: opencatalogi owned `document` at ids 39 and
	 * 40, the command took 40 and left 39 answering every lookup.
	 *
	 * @return void
	 */
	public function testBothRowsUnderOneSlugArePruned(): void {
		$this->stubTwoRetiredSchemas();

		$this->deletionService->expects(self::exactly(2))
			->method('cascadeDeleteSchema')
			->willReturn(['deletedCount' => 0, 'tableDropped' => true]);

		$tester = $this->runPrune(['--apply' => true]);

		self::assertStringContainsString('Pruned=2', $tester->getDisplay());
		self::assertStringContainsString('owns 2 rows under this slug', $tester->getDisplay());

	}//end testBothRowsUnderOneSlugArePruned()

	/**
	 * When the guard's count and the delete's count disagree, say so.
	 *
	 * The guard read "0 objects, safe to delete" and the delete removed 2, on a
	 * live instance. Whatever the cause, an operator who approved a deletion on
	 * the strength of a dry run needs to be told the number was wrong rather
	 * than left to infer it from a log line they will not read.
	 *
	 * @return void
	 */
	public function testACountMismatchIsReported(): void {
		$this->stubOneRetiredSchema(archival: false);

		$this->deletionService->method('cascadeDeleteSchema')
			->willReturn(['deletedCount' => 7, 'tableDropped' => true]);

		$tester = $this->runPrune(['--apply' => true, '--force' => true]);

		self::assertStringContainsString('COUNT MISMATCH', $tester->getDisplay());
		self::assertStringContainsString('the guard saw 2 object(s), the delete removed 7', $tester->getDisplay());

	}//end testACountMismatchIsReported()

	/**
	 * Run the command with the given options.
	 *
	 * @param array<string, bool> $options Extra options beyond --app/--slug/--apply.
	 *
	 * @return CommandTester The finished tester.
	 */
	private function runPrune(array $options): CommandTester {
		$tester = new CommandTester($this->command);
		$tester->execute(
			array_merge(
				[
					'--app' => 'dossiq',
					'--slug' => ['retained-case'],
					'--apply' => true,
				],
				$options
			)
		);

		return $tester;
	}//end runPrune()

	/**
	 * `--force` alone does NOT destroy archival records.
	 *
	 * The operator asked to drop a retired schema that still owns objects. They did
	 * not ask to destroy legally retained ones, and this is the difference the
	 * separate flag exists to preserve.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function testForceAloneDoesNotPruneAnArchivalSchema(): void {
		$this->stubOneRetiredSchema(archival: true);

		$this->deletionService->expects($this->never())->method('cascadeDeleteSchema');

		$tester = $this->runPrune(['--force' => true]);
		$display = $tester->getDisplay();

		$this->assertStringContainsString('x-openregister-archival', $display);
		$this->assertStringContainsString('--force-archival', $display);
		$this->assertStringContainsString('skipped=1', $display);

	}//end testForceAloneDoesNotPruneAnArchivalSchema()

	/**
	 * `--force-archival` authorises the destruction, and the service is told so.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function testForceArchivalPrunesAndNamesTheRecords(): void {
		$schema = $this->stubOneRetiredSchema(archival: true);

		$this->deletionService
			->expects($this->once())
			->method('cascadeDeleteSchema')
			->with($this->equalTo($schema), $this->isTrue())
			->willReturn(['deletedCount' => 2, 'deletedUuids' => ['a', 'b'], 'tableDropped' => true]);

		$tester = $this->runPrune(['--force' => true, '--force-archival' => true]);
		$display = $tester->getDisplay();

		$this->assertStringContainsString('ARCHIVAL RECORDS', $display);
		$this->assertStringContainsString('Pruned=1', $display);

	}//end testForceArchivalPrunesAndNamesTheRecords()

	/**
	 * An ordinary retired schema is unaffected: the override is never passed.
	 *
	 * @return void
	 */
	public function testAnOrdinarySchemaIsPrunedWithoutTheOverride(): void {
		$schema = $this->stubOneRetiredSchema(archival: false);

		$this->deletionService
			->expects($this->once())
			->method('cascadeDeleteSchema')
			->with($this->equalTo($schema), $this->isFalse())
			->willReturn(['deletedCount' => 2, 'deletedUuids' => ['a', 'b'], 'tableDropped' => true]);

		$tester = $this->runPrune(['--force' => true]);
		$display = $tester->getDisplay();

		$this->assertStringNotContainsString('ARCHIVAL RECORDS', $display);
		$this->assertStringContainsString('Pruned=1', $display);

	}//end testAnOrdinarySchemaIsPrunedWithoutTheOverride()

	/**
	 * A dry run says what it WOULD destroy, and destroys nothing.
	 *
	 * @return void
	 */
	public function testDryRunNamesTheArchivalRecordsWithoutDeleting(): void {
		$this->stubOneRetiredSchema(archival: true);

		$this->deletionService->expects($this->never())->method('cascadeDeleteSchema');

		$tester = new CommandTester($this->command);
		$tester->execute(
			[
				'--app' => 'dossiq',
				'--slug' => ['retained-case'],
				'--force' => true,
				'--force-archival' => true,
			]
		);

		$display = $tester->getDisplay();

		$this->assertStringContainsString('WOULD DELETE', $display);
		$this->assertStringContainsString('ARCHIVAL RECORDS', $display);

	}//end testDryRunNamesTheArchivalRecordsWithoutDeleting()
}//end class
