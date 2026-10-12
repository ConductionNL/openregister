<?php

/**
 * MigrationPackServiceTest
 *
 * Unit tests for `MigrationPackService`: validation delegation, slug
 * uniqueness on create/update, and the import-a-pack-document upsert
 * (create when the slug is absent, update when it already exists) — the
 * round-trip a shared pack JSON file goes through when re-imported.
 * Also: the slug of a built-in pack cannot change, and previewRows() maps
 * sample rows through an unsaved definition.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MigrationPack;
use OCA\OpenRegister\Db\MigrationPackMapper;
use OCA\OpenRegister\Exception\BuiltinPackSlugLockedException;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator;
use OCA\OpenRegister\Service\MigrationPackService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MigrationPackServiceTest extends TestCase {
	private MigrationPackService $service;
	private MigrationPackMapper&MockObject $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(MigrationPackMapper::class);
		$this->service = new MigrationPackService($this->mapper, new PackDefinitionValidator(), new MappingEngine());
	}

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private function validDefinition(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'test-pack',
				'name' => 'Test Pack',
				'sourceFormat' => 'csv',
				'version' => '1.0.0',
				'idStrategy' => ['type' => 'generate'],
				'fieldMappings' => [['source' => 'Name', 'target' => 'title']],
			],
			$overrides
		);
	}

	public function testCreateRejectsInvalidDefinition(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->create(['id' => 'x']);
	}

	public function testCreatePersistsAValidDefinition(): void {
		$this->mapper->method('findByPackSlug')->willThrowException(new DoesNotExistException('not found'));
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static function (MigrationPack $pack) {
				return $pack;
			});

		$pack = $this->service->create($this->validDefinition(), 'admin-uid');

		$this->assertSame('test-pack', $pack->getPackSlug());
		$this->assertSame('Test Pack', $pack->getName());
		$this->assertSame('csv', $pack->getSourceFormat());
		$this->assertSame('admin-uid', $pack->getOwner());
		$this->assertFalse($pack->getBuiltin());
	}

	public function testCreateRejectsDuplicateSlug(): void {
		$existing = new MigrationPack();
		$existing->setPackSlug('test-pack');
		$this->mapper->method('findByPackSlug')->willReturn($existing);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/already exists/');
		$this->service->create($this->validDefinition());
	}

	public function testUpdateRejectsInvalidDefinition(): void {
		$existing = new MigrationPack();
		$existing->setId(1);
		$existing->setPackSlug('test-pack');
		$this->mapper->method('find')->willReturn($existing);

		$this->expectException(InvalidArgumentException::class);
		$this->service->update(1, ['id' => 'x']);
	}

	public function testUpdatePersistsChanges(): void {
		$existing = new MigrationPack();
		$existing->setId(1);
		$existing->setPackSlug('test-pack');
		$this->mapper->method('find')->willReturn($existing);
		$this->mapper->method('findByPackSlug')->willThrowException(new DoesNotExistException('not found'));
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$pack = $this->service->update(1, $this->validDefinition(['name' => 'Renamed Pack']));

		$this->assertSame('Renamed Pack', $pack->getName());
	}

	public function testUpdateRejectsSlugCollisionWithADifferentPack(): void {
		$existing = new MigrationPack();
		$existing->setId(1);
		$existing->setPackSlug('test-pack');
		$this->mapper->method('find')->willReturn($existing);

		$other = new MigrationPack();
		$other->setId(2);
		$other->setPackSlug('other-pack');
		$this->mapper->method('findByPackSlug')->willReturn($other);

		$this->expectException(InvalidArgumentException::class);
		$this->service->update(1, $this->validDefinition(['id' => 'other-pack']));
	}

	public function testDeleteRemovesTheRowAndReturnsIt(): void {
		$existing = new MigrationPack();
		$existing->setId(1);
		$existing->setPackSlug('test-pack');
		$this->mapper->method('find')->willReturn($existing);
		$this->mapper->expects($this->once())->method('delete')->with($existing);

		$this->assertSame($existing, $this->service->delete(1));
	}

	/**
	 * A stored built-in pack.
	 *
	 * @return MigrationPack
	 */
	private function builtinPack(): MigrationPack {
		$existing = new MigrationPack();
		$existing->setId(3);
		$existing->setPackSlug('test-pack');
		$existing->setBuiltin(true);

		return $existing;
	}

	public function testUpdateRefusesToRenameABuiltinPack(): void {
		$this->mapper->method('find')->willReturn($this->builtinPack());
		$this->mapper->method('findByPackSlug')->willThrowException(new DoesNotExistException('not found'));
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(BuiltinPackSlugLockedException::class);
		$this->service->update(3, $this->validDefinition(['id' => 'renamed-pack']));
	}

	public function testBuiltinSlugLockIsAnInvalidArgument(): void {
		// Existing callers that map InvalidArgumentException to 422 keep working.
		$this->assertInstanceOf(InvalidArgumentException::class, new BuiltinPackSlugLockedException('locked'));
	}

	public function testUpdateAllowsEditingABuiltinPackThatKeepsItsSlug(): void {
		$this->mapper->method('find')->willReturn($this->builtinPack());
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$pack = $this->service->update(3, $this->validDefinition(['name' => 'Edited', 'version' => '1.0.1']));

		$this->assertSame('test-pack', $pack->getPackSlug());
		$this->assertSame('Edited', $pack->getName());
		$this->assertSame('1.0.1', $pack->getVersion());
		$this->assertTrue($pack->getBuiltin());
	}

	public function testUpdateAllowsRenamingANonBuiltinPack(): void {
		$existing = new MigrationPack();
		$existing->setId(1);
		$existing->setPackSlug('test-pack');
		$existing->setBuiltin(false);
		$this->mapper->method('find')->willReturn($existing);
		$this->mapper->method('findByPackSlug')->willThrowException(new DoesNotExistException('not found'));
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$pack = $this->service->update(1, $this->validDefinition(['id' => 'renamed-pack']));

		$this->assertSame('renamed-pack', $pack->getPackSlug());
	}

	public function testImportDefinitionUpdatesABuiltinPackUnderItsOwnSlug(): void {
		$builtin = $this->builtinPack();
		$this->mapper->method('findByPackSlug')->willReturn($builtin);
		$this->mapper->method('find')->willReturn($builtin);
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$pack = $this->service->importDefinition($this->validDefinition(['name' => 'Reimported']));

		$this->assertSame('Reimported', $pack->getName());
	}

	public function testValidateDefinitionReturnsErrorsWithoutThrowing(): void {
		$this->assertSame([], $this->service->validateDefinition($this->validDefinition()));
		$this->assertNotEmpty($this->service->validateDefinition(['id' => 'x']));
	}

	public function testPreviewRowsNumbersRowsFromOneAndKeepsDuplicateSources(): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->never())->method('update');

		// One source column feeding two targets, and a later mapping that
		// overwrites an earlier one for the same target, as the TOPdesk packs do.
		// skipRows names source-file rows, so it does not drop sample row 1.
		$definition = $this->validDefinition(
			[
				'skipRows' => [1],
				'fieldMappings' => [
					['source' => 'Soort', 'target' => 'applicationType'],
					[
						'source' => 'Soort',
						'target' => 'cloudModel',
						'transform' => ['type' => 'lookup', 'map' => ['SaaS' => 'saas'], 'default' => null],
					],
					['source' => 'Nickname', 'target' => 'shortDescription'],
					['source' => 'Roepnaam', 'target' => 'shortDescription'],
				],
			]
		);

		$rows = $this->service->previewRows(
			$definition,
			[
				['Soort' => 'SaaS', 'Nickname' => 'nick', 'Roepnaam' => 'roep'],
				['Soort' => 'On-premise', 'Nickname' => 'nick'],
			]
		);

		$this->assertSame(1, $rows[0]['rowNumber']);
		$this->assertSame(
			['applicationType' => 'SaaS', 'cloudModel' => 'saas', 'shortDescription' => 'roep'],
			$rows[0]['data']
		);
		$this->assertSame([], $rows[0]['errors']);

		// `default: null` maps an unknown value to null quietly.
		$this->assertSame(2, $rows[1]['rowNumber']);
		$this->assertSame(
			['applicationType' => 'On-premise', 'cloudModel' => null, 'shortDescription' => 'nick'],
			$rows[1]['data']
		);
		$this->assertSame([], $rows[1]['errors']);
	}

	public function testPreviewRowsReportsRowErrors(): void {
		$rows = $this->service->previewRows(
			$this->validDefinition(
				['fieldMappings' => [['source' => 'Name', 'target' => 'title', 'required' => true]]]
			),
			[['Other' => 'x']]
		);

		$this->assertSame([], $rows[0]['data']);
		$this->assertCount(1, $rows[0]['errors']);
		$this->assertSame(1, $rows[0]['errors'][0]['row']);
		$this->assertSame('title', $rows[0]['errors'][0]['target']);
	}

	public function testPreviewRowsRejectsAnInvalidDefinition(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->previewRows(['id' => 'x'], [['Name' => 'y']]);
	}

	public function testImportDefinitionCreatesWhenSlugAbsent(): void {
		$this->mapper->method('findByPackSlug')->willThrowException(new DoesNotExistException('not found'));
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(static fn (MigrationPack $p) => $p);
		$this->mapper->expects($this->never())->method('update');

		$pack = $this->service->importDefinition($this->validDefinition(), 'admin-uid');
		$this->assertSame('test-pack', $pack->getPackSlug());
	}

	public function testImportDefinitionUpdatesWhenSlugAlreadyExists(): void {
		$existing = new MigrationPack();
		$existing->setId(9);
		$existing->setPackSlug('test-pack');

		// The pack slug is unchanged between the existing row and the
		// re-imported definition, so update() never re-checks slug
		// uniqueness — findByPackSlug() is only hit once, by
		// importDefinition() itself, to decide create-vs-update.
		$this->mapper->method('findByPackSlug')->willReturn($existing);
		$this->mapper->method('find')->willReturn($existing);
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);
		$this->mapper->expects($this->never())->method('insert');

		$pack = $this->service->importDefinition($this->validDefinition(['name' => 'Updated Name']));
		$this->assertSame('Updated Name', $pack->getName());
	}
}
