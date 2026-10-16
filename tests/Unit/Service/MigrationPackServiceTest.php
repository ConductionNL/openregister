<?php

/**
 * MigrationPackServiceTest
 *
 * Unit tests for `MigrationPackService`: validation delegation, slug
 * uniqueness on create/update, and the import-a-pack-document upsert
 * (create when the slug is absent, update when it already exists) — the
 * round-trip a shared pack JSON file goes through when re-imported.
 * Also: the slug of a built-in pack cannot change, not even after the pack
 * is deleted and created again, and previewRows() maps sample rows through an
 * unsaved definition.
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

use OCA\OpenRegister\Tests\Unit\Service\MigrationPack\PassThroughL10nTrait;
use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Db\MigrationPack;
use OCA\OpenRegister\Db\MigrationPackMapper;
use OCA\OpenRegister\Exception\BuiltinPackSlugLockedException;
use OCA\OpenRegister\Exception\InvalidPackDefinitionException;
use OCA\OpenRegister\Exception\PackChangedException;
use OCA\OpenRegister\Exception\PackSlugExistsException;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator;
use OCA\OpenRegister\Service\MigrationPackService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MigrationPackServiceTest extends TestCase {
	use PassThroughL10nTrait;

	private MigrationPackService $service;
	private MigrationPackMapper&MockObject $mapper;
	private IAppConfig&MockObject $appConfig;

	/**
	 * The array values the app config mock holds, by key.
	 *
	 * @var array<string, array<mixed>>
	 */
	private array $configArrays = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(MigrationPackMapper::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueArray')->willReturnCallback(
			fn (string $app, string $key, array $default = []): array => $this->configArrays[$app . '/' . $key] ?? $default
		);
		$this->appConfig->method('setValueArray')->willReturnCallback(
			function (string $app, string $key, array $value): bool {
				$this->configArrays[$app . '/' . $key] = $value;
				return true;
			}
		);
		$this->service = new MigrationPackService(
			$this->mapper,
			new PackDefinitionValidator($this->passThroughL10n()),
			new MappingEngine($this->passThroughL10n()),
			$this->appConfig
		);
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

		try {
			$this->service->create($this->validDefinition());
			$this->fail('A duplicate slug must be refused');
		} catch (InvalidPackDefinitionException $e) {
			$this->assertInstanceOf(PackSlugExistsException::class, $e);
			$this->assertSame(['A migration pack with id "test-pack" already exists'], $e->getErrors());
		}

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

	public function testUpdateRefusesAPackSavedAfterTheCallerReadIt(): void {
		$existing = new MigrationPack();
		$existing->setId(1);
		$existing->setPackSlug('test-pack');
		$existing->setUpdatedAt(new DateTime('2026-10-12T10:00:05+00:00'));
		$this->mapper->method('find')->willReturn($existing);
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(PackChangedException::class);
		$this->service->update(
			1,
			$this->validDefinition(),
			(new DateTimeImmutable('2026-10-12T10:00:00+00:00'))->getTimestamp()
		);
	}

	public function testUpdateSavesWhenThePackIsStillAsTheCallerReadIt(): void {
		$existing = new MigrationPack();
		$existing->setId(1);
		$existing->setPackSlug('test-pack');
		$existing->setUpdatedAt(new DateTime('2026-10-12T12:00:00+02:00'));
		$this->mapper->method('find')->willReturn($existing);
		$this->mapper->method('findByPackSlug')->willThrowException(new DoesNotExistException('not found'));
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		// The same instant, written with another offset.
		$pack = $this->service->update(
			1,
			$this->validDefinition(['name' => 'Renamed Pack']),
			(new DateTimeImmutable('2026-10-12T10:00:00+00:00'))->getTimestamp()
		);

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

		$this->expectException(PackSlugExistsException::class);
		$this->service->update(1, $this->validDefinition(['id' => 'other-pack']));
	}

	public function testDeleteRemovesTheRowAndReturnsIt(): void {
		$existing = new MigrationPack();
		$existing->setId(1);
		$existing->setPackSlug('test-pack');
		$this->mapper->method('find')->willReturn($existing);
		$this->mapper->expects($this->once())->method('delete')->with($existing);

		$this->assertSame($existing, $this->service->delete(1));
		$this->assertSame([], $this->configArrays);
	}

	public function testDeletingABuiltinPackKeepsTheRowWhenItsSlugCannotBeRemembered(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueArray')->willReturn([]);
		$appConfig->method('setValueArray')->willThrowException(new RuntimeException('app config unavailable'));
		$service = new MigrationPackService($this->mapper, new PackDefinitionValidator($this->passThroughL10n()), new MappingEngine($this->passThroughL10n()), $appConfig);
		$this->mapper->method('find')->willReturn($this->builtinPack());
		$this->mapper->expects($this->never())->method('delete');

		$this->expectException(RuntimeException::class);
		$service->delete(3);
	}

	public function testSeedingABuiltinPackTakesItsSlugOffTheDeletedList(): void {
		$this->configArrays['openregister/' . MigrationPackService::DELETED_BUILTIN_SLUGS_KEY] = ['other-pack', 'test-pack'];
		$this->mapper->method('findByPackSlug')->willThrowException(new DoesNotExistException('not found'));
		$this->mapper->method('insert')->willReturnCallback(static fn (MigrationPack $pack) => $pack);

		$this->assertTrue($this->service->create($this->validDefinition(), null, true)->getBuiltin());
		$this->assertSame(
			['other-pack'],
			$this->configArrays['openregister/' . MigrationPackService::DELETED_BUILTIN_SLUGS_KEY]
		);
	}

	public function testAFailedSeedLeavesTheSlugLocked(): void {
		$this->configArrays['openregister/' . MigrationPackService::DELETED_BUILTIN_SLUGS_KEY] = ['test-pack'];
		$this->mapper->method('findByPackSlug')->willThrowException(new DoesNotExistException('not found'));
		$this->mapper->method('insert')->willThrowException(new RuntimeException('insert failed'));

		try {
			$this->service->create($this->validDefinition(), null, true);
			$this->fail('The insert failure must surface');
		} catch (RuntimeException $e) {
			$this->assertSame('insert failed', $e->getMessage());
		}

		$this->assertSame(
			['test-pack'],
			$this->configArrays['openregister/' . MigrationPackService::DELETED_BUILTIN_SLUGS_KEY]
		);
	}

	public function testAdminCreateUnderADeletedBuiltinSlugKeepsItOnTheList(): void {
		$this->configArrays['openregister/' . MigrationPackService::DELETED_BUILTIN_SLUGS_KEY] = ['test-pack'];
		$this->mapper->method('findByPackSlug')->willThrowException(new DoesNotExistException('not found'));
		$this->mapper->method('insert')->willReturnCallback(static fn (MigrationPack $pack) => $pack);

		$this->assertTrue($this->service->create($this->validDefinition(), 'admin-uid')->getBuiltin());
		$this->assertSame(
			['test-pack'],
			$this->configArrays['openregister/' . MigrationPackService::DELETED_BUILTIN_SLUGS_KEY]
		);
	}

	public function testDeletingABuiltinPackRemembersItsSlugOnce(): void {
		$this->mapper->method('find')->willReturn($this->builtinPack());

		$this->service->delete(3);
		$this->service->delete(3);

		$this->assertSame(
			['test-pack'],
			$this->configArrays['openregister/' . MigrationPackService::DELETED_BUILTIN_SLUGS_KEY]
		);
	}

	public function testImportUnderTheSlugOfADeletedBuiltinPackKeepsTheLock(): void {
		// Delete the built-in pack, import it again as row 4, then try to rename it.
		$builtin = $this->builtinPack();
		$imported = null;
		$this->mapper->method('find')->willReturnCallback(
			static function (int $id) use ($builtin, &$imported): MigrationPack {
				if ($id === 3) {
					return $builtin;
				}

				return $imported;
			}
		);
		$this->mapper->method('findByPackSlug')->willThrowException(new DoesNotExistException('not found'));
		$this->mapper->method('insert')->willReturnCallback(
			static function (MigrationPack $pack): MigrationPack {
				$pack->setId(4);
				return $pack;
			}
		);
		$this->mapper->expects($this->never())->method('update');

		$this->service->delete(3);
		$imported = $this->service->importDefinition($this->validDefinition(), 'admin-uid')->pack;

		$this->assertTrue($imported->getBuiltin());
		$this->assertSame('admin-uid', $imported->getOwner());

		$this->expectException(BuiltinPackSlugLockedException::class);
		$this->service->update(4, $this->validDefinition(['id' => 'renamed-pack']));
	}

	public function testCreateUnderAnUnrelatedSlugStaysNonBuiltin(): void {
		$this->configArrays['openregister/' . MigrationPackService::DELETED_BUILTIN_SLUGS_KEY] = ['other-pack'];
		$this->mapper->method('findByPackSlug')->willThrowException(new DoesNotExistException('not found'));
		$this->mapper->method('insert')->willReturnCallback(static fn (MigrationPack $pack) => $pack);

		$this->assertFalse($this->service->create($this->validDefinition(), 'admin-uid')->getBuiltin());
	}

	public function testRenamingAPackToTheSlugOfADeletedBuiltinPackMakesItBuiltin(): void {
		$this->configArrays['openregister/' . MigrationPackService::DELETED_BUILTIN_SLUGS_KEY] = ['test-pack'];
		$existing = new MigrationPack();
		$existing->setId(1);
		$existing->setPackSlug('my-pack');
		$existing->setBuiltin(false);
		$this->mapper->method('find')->willReturn($existing);
		$this->mapper->method('findByPackSlug')->willThrowException(new DoesNotExistException('not found'));
		$this->mapper->method('update')->willReturnArgument(0);

		$pack = $this->service->update(1, $this->validDefinition());

		$this->assertSame('test-pack', $pack->getPackSlug());
		$this->assertTrue($pack->getBuiltin());
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

		$pack = $this->service->importDefinition($this->validDefinition(['name' => 'Reimported']))->pack;

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

	public function testPreviewRowsReadsJsonPointerSourcesFromANestedRow(): void {
		// The shape the editor's sample-row test sends for a JSON pack.
		$definition = $this->validDefinition(
			[
				'sourceFormat' => 'json',
				'fieldMappings' => [
					['source' => '/identificatie', 'target' => 'caseNumber', 'required' => true],
					['source' => '/zaak/a~1b', 'target' => 'nested'],
				],
			]
		);

		$rows = $this->service->previewRows(
			$definition,
			[['identificatie' => 'Z-1', 'zaak' => ['a/b' => 'x']]]
		);

		$this->assertSame(['caseNumber' => 'Z-1', 'nested' => 'x'], $rows[0]['data']);
		$this->assertSame([], $rows[0]['errors']);

		// A flat key named like the pointer is not found.
		$flat = $this->service->previewRows($definition, [['/identificatie' => 'Z-1']]);
		$this->assertSame([], $flat[0]['data']);
		$this->assertSame('caseNumber', $flat[0]['errors'][0]['target']);
	}

	public function testPreviewRowsRejectsAnInvalidDefinition(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->previewRows(['id' => 'x'], [['Name' => 'y']]);
	}

	public function testImportDefinitionCreatesWhenSlugAbsent(): void {
		$this->mapper->method('findByPackSlug')->willThrowException(new DoesNotExistException('not found'));
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(static fn (MigrationPack $p) => $p);
		$this->mapper->expects($this->never())->method('update');

		$result = $this->service->importDefinition($this->validDefinition(), 'admin-uid');
		$this->assertSame('test-pack', $result->pack->getPackSlug());
		$this->assertTrue($result->created);
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

		$result = $this->service->importDefinition($this->validDefinition(['name' => 'Updated Name']));
		$this->assertSame('Updated Name', $result->pack->getName());
		$this->assertFalse($result->created);
	}
}
