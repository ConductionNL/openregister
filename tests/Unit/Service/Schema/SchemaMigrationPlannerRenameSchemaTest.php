<?php

/**
 * Unit tests for the `renameSchema` migration operation: plan, run and rollback.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Exception\SchemaRunConcurrencyException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Schema\MigrationRequest;
use OCA\OpenRegister\Service\Schema\SchemaMigrationPlanner;
use OCA\OpenRegister\Service\Schema\SchemaMigrationService;
use OCA\OpenRegister\Service\Schema\SchemaPopulationRuns;
use OCA\OpenRegister\Service\Schema\SchemaSlugRename;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaSlugRename::class)]
#[CoversClass(SchemaMigrationService::class)]
#[UsesClass(SchemaMigrationPlanner::class)]
#[UsesClass(SchemaPopulationRuns::class)]
#[UsesClass(MigrationRequest::class)]
#[UsesClass(Schema::class)]
#[UsesClass(SchemaRun::class)]
class SchemaMigrationPlannerRenameSchemaTest extends TestCase {

	private SchemaMigrationService $service;
	private SchemaRunMapper&MockObject $runMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private ObjectService&MockObject $objectService;

	/** @var array<int, Schema> */
	private array $schemas = [];

	protected function setUp(): void {
		parent::setUp();
		$this->runMapper     = $this->createMock(SchemaRunMapper::class);
		$this->schemaMapper  = $this->createMock(SchemaMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$this->schemas = [
			1 => $this->schema(id: 1, slug: 'melding', properties: ['omschrijving' => ['type' => 'string']]),
			2 => $this->schema(
				id: 2,
				slug: 'document',
				properties: [
					'melding' => ['type' => 'object', '$ref' => 'melding'],
					'meldingen' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/melding']],
					'byId' => ['type' => 'object', '$ref' => '1'],
					'similar' => ['type' => 'object', '$ref' => '#/components/schemas/meldingtype'],
					'other' => ['type' => 'object', '$ref' => 'persoon'],
				]
			),
			3 => $this->schema(id: 3, slug: 'persoon', properties: ['naam' => ['type' => 'string']]),
		];

		$this->schemaMapper->method('find')->willReturnCallback(fn (string|int $id): Schema => $this->schemas[(int)$id]);
		$this->schemaMapper->method('findAll')->willReturnCallback(fn (): array => array_values($this->schemas));
		$this->schemaMapper->method('update')->willReturnArgument(0);
		$this->runMapper->method('createFromArray')->willReturnCallback(
			static function (array $data): SchemaRun {
				$run = new SchemaRun();
				$run->setId(11);
				$run->setSchemaId($data['schemaId']);
				$run->setType($data['type']);
				$run->setState($data['state']);
				$run->setPlan($data['plan']);
				$run->setReport($data['report']);
				return $run;
			}
		);

		$this->service = new SchemaMigrationService(
			new SchemaMigrationPlanner(),
			new SchemaPopulationRuns($this->runMapper, $this->createMock(SchemaRunEntryMapper::class), $this->schemaMapper, $this->objectService)
		);
	}

	private function schema(int $id, string $slug, array $properties): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setProperties($properties);
		return $schema;
	}

	private function renamePlan(string $from = 'melding', string $to = 'signaal'): array {
		return [['op' => 'renameSchema', 'from' => $from, 'to' => $to]];
	}

	public function testAValidRenamePlanHasNoProblems(): void {
		$this->assertSame([], $this->service->validatePlan($this->renamePlan()));
	}

	public function testARenameMixedWithObjectTransformsIsRefused(): void {
		$problems = $this->service->validatePlan([['op' => 'renameSchema', 'from' => 'a', 'to' => 'b'], ['op' => 'drop', 'field' => 'x']]);

		$this->assertSame(['A renameSchema transform must be the only transform in its plan.'], $problems);
	}

	public function testARenameWithoutTargetOrToItselfIsRefused(): void {
		$this->assertSame(['renameSchema transform #0 requires "from" and "to".'], $this->service->validatePlan([['op' => 'renameSchema', 'from' => 'melding']]));
		$this->assertSame(['renameSchema transform #0 renames a slug to itself.'], $this->service->validatePlan($this->renamePlan(to: 'melding')));
	}

	public function testThePreviewListsTheReferencesAndObjectsAndChangesNothing(): void {
		$this->objectService->method('count')->willReturn(1200);
		$this->schemaMapper->expects($this->never())->method('update');
		$this->runMapper->expects($this->never())->method('createFromArray');

		$preview = $this->service->previewSample(new MigrationRequest(1, 5, $this->renamePlan()));

		$this->assertSame(
			[[
				'op' => 'renameSchema',
				'from' => 'melding',
				'to' => 'signaal',
				'objects' => 1200,
				'schemas' => [['id' => 2, 'slug' => 'document', 'paths' => ['melding.$ref', 'meldingen.items.$ref']]],
			]],
			$preview
		);
		$this->assertSame('melding', $this->schemas[1]->getSlug());
	}

	public function testRunRenamesTheSlugRewritesTheRefsAndRecordsACompletedRun(): void {
		$this->runMapper->method('findActiveForSchema')->willReturn(null);

		$run = $this->service->startMigration(new MigrationRequest(1, 5, $this->renamePlan()), 'admin');

		$this->assertSame('signaal', $this->schemas[1]->getSlug());
		$this->assertSame(['melding'], $this->schemas[1]->getFormerSlugs());
		$properties = $this->schemas[2]->getProperties();
		$this->assertSame('signaal', $properties['melding']['$ref']);
		$this->assertSame('#/components/schemas/signaal', $properties['meldingen']['items']['$ref']);
		$this->assertSame('persoon', $properties['other']['$ref']);
		$this->assertSame('1', $properties['byId']['$ref']);
		$this->assertSame('#/components/schemas/meldingtype', $properties['similar']['$ref']);
		$this->assertSame(SchemaRun::STATE_COMPLETED, $run->getState());
		$this->assertSame(
			[
				'from' => 'melding',
				'to' => 'signaal',
				'formerSlugs' => null,
				'references' => [[
					'schemaId' => 2,
					'paths' => [
						['path' => ['melding', '$ref'], 'was' => 'melding', 'now' => 'signaal'],
						['path' => ['meldingen', 'items', '$ref'], 'was' => '#/components/schemas/melding', 'now' => '#/components/schemas/signaal'],
					],
				]],
			],
			$run->getReport()['renameSchema']
		);
	}

	public function testRunRefusesAFromThatIsNotTheCurrentSlug(): void {
		$this->runMapper->method('findActiveForSchema')->willReturn(null);
		$this->schemaMapper->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('renameSchema: "persoon" is not the current slug of this schema.');
		$this->service->startMigration(new MigrationRequest(1, 5, $this->renamePlan(from: 'persoon')));
	}

	public function testRunRefusesATakenSlug(): void {
		$this->schemaMapper->method('countBySlug')->with('document')->willReturn(1);
		$this->schemaMapper->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('renameSchema: the slug "document" is already taken.');
		$this->service->startMigration(new MigrationRequest(1, 5, $this->renamePlan(to: 'document')));
	}

	public function testRunRefusesWhileAnotherRunIsActive(): void {
		$this->runMapper->method('findActiveForSchema')->willReturn(new SchemaRun());
		$this->schemaMapper->expects($this->never())->method('update');

		$this->expectException(SchemaRunConcurrencyException::class);
		$this->service->startMigration(new MigrationRequest(1, 5, $this->renamePlan()));
	}

	public function testRollbackRestoresTheSlugTheFormerSlugsAndTheRefs(): void {
		$this->runMapper->method('findActiveForSchema')->willReturn(null);
		$run = $this->service->startMigration(new MigrationRequest(1, 5, $this->renamePlan()));
		$this->runMapper->method('find')->willReturn($run);
		$this->runMapper->expects($this->once())->method('save')->with($run);

		$result = $this->service->rollback(11);

		$this->assertSame('melding', $this->schemas[1]->getSlug());
		$this->assertNull($this->schemas[1]->getFormerSlugs());
		$properties = $this->schemas[2]->getProperties();
		$this->assertSame('melding', $properties['melding']['$ref']);
		$this->assertSame('#/components/schemas/melding', $properties['meldingen']['items']['$ref']);
		$this->assertSame(SchemaRun::STATE_ROLLED_BACK, $result->getState());
		$this->assertSame(['restored' => 3, 'conflicts' => 0], $result->getReport()['rollback']);
	}

	public function testRollbackLeavesAReferenceEditedSinceTheRenameAndCountsIt(): void {
		$this->runMapper->method('findActiveForSchema')->willReturn(null);
		$run = $this->service->startMigration(new MigrationRequest(1, 5, $this->renamePlan()));
		$this->runMapper->method('find')->willReturn($run);
		$properties = $this->schemas[2]->getProperties();
		$properties['melding']['$ref'] = 'persoon';
		$this->schemas[2]->setProperties($properties);

		$result = $this->service->rollback(11);

		$after = $this->schemas[2]->getProperties();
		$this->assertSame('persoon', $after['melding']['$ref']);
		$this->assertSame('#/components/schemas/melding', $after['meldingen']['items']['$ref']);
		$this->assertSame(['restored' => 2, 'conflicts' => 1], $result->getReport()['rollback']);
	}

	public function testRenamingBackToAFormerSlugDropsItFromTheList(): void {
		$this->assertSame(['a', 'signaal'], SchemaSlugRename::formerSlugsAfter(former: ['a', 'melding'], from: 'signaal', to: 'melding'));
	}
}
