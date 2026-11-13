<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A save runs its cascades inside a scope that carries its own RBAC mode.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\PermissionCheckOptions;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TmloService;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject\SaveLookupsAccess;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;

/**
 * The permission check of the nested create records the RBAC mode it is asked in and then
 * refuses, so the save stops at the first nested object and nothing is written.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveLookups
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveCallFrame
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveCallStack
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceStrictness
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceEvents
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceExistence
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceFilterGuard
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceValidator
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferencePropertyRun
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceText
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\RegisterReferenceLookup
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Object\ObjectVersionHandler
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugBatch
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugClientValue
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugLog
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugSource
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugStore
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Db\Schema\ObjectSourceDeclaration
 * @uses   \OCA\OpenRegister\Db\Schema\PropertyAuthorization
 * @uses   \OCA\OpenRegister\Db\Schema\SchemaPropertyDocument
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheckOptions
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\CascadeItem
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\CascadeRules
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\CascadeRun
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\EmptyValues
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\FolderBind
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\NestedObjectTarget
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\NestedSaveTransaction
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectPayload
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectWriteTarget
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectHandlers
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectMappers
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPersistence
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPlatform
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPropertyHandlers
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRecords
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRuntime
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectServices
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectTenancy
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectValueHandlers
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SelfSubmission
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\UpdatePreparation
 * @uses   \OCA\OpenRegister\Service\Object\TranslationScope
 * @uses   \OCA\OpenRegister\Service\ProtectedCarryForward
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
class SaveObjectCascadeScopeTest extends TestCase {

	/**
	 * The RBAC mode of every create check.
	 *
	 * @var list<bool>
	 */
	private array $checks = [];

	/**
	 * Save one incident with an inline note, and return the scopes left open after.
	 *
	 * @param bool        $rbac The RBAC mode of the save.
	 * @param string|null $uuid The stored incident to update, null to create one.
	 *
	 * @return array The cascade scopes still open once the save has ended.
	 */
	private function saveIncident(bool $rbac, ?string $uuid = null): array {
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('checkPermission')->willReturnCallback(
			function (Schema $schema, string $action, PermissionCheckOptions $options = new PermissionCheckOptions(), bool $_rbac = true): void {
				$this->checks[] = $_rbac;
				throw new NotAuthorizedException(message: "no {$action} in {$schema->getSlug()}");
			}
		);

		$translations = $this->createMock(TranslationHandler::class);
		$translations->method('normalizeTranslationsForSave')->willReturnArgument(0);

		$mapper = $this->createMock(MagicMapper::class);
		$stored = new ObjectEntity();
		$stored->setId(5);
		$stored->setUuid('11111111-1111-1111-1111-111111111111');
		$stored->setObject([]);
		$mapper->method('find')->willReturn($stored);

		$handler = new SaveObject(
			services: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectServices(
				persistence: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPersistence(
					mappers: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectMappers(
						catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $mapper),
						unifiedObjectMapper: $this->createMock(MagicMapper::class)
					),
					records: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRecords(
						auditTrailMapper: $this->createMock(AuditTrailMapper::class),
						settingsService: $this->createMock(SettingsService::class),
						tmloService: $this->createMock(TmloService::class)
					),
					runtime: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRuntime(
						cacheHandler: $this->createMock(CacheHandler::class)
					)
				),
				handlers: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectHandlers(
					properties: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPropertyHandlers(
						metaHydrationHandler: $this->createMock(MetadataHydrationHandler::class),
						filePropertyHandler: $this->createMock(FilePropertyHandler::class),
						linkedEntityHandler: $this->createMock(LinkedEntityPropertyHandler::class),
						computedFieldHandler: $this->createMock(ComputedFieldHandler::class)
					),
					values: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectValueHandlers(
						translationHandler: $translations,
						translationProjectionService: $this->createMock(TranslationProjectionService::class),
						translationStatusService: $this->createMock(TranslationStatusService::class)
					)
				),
				platform: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPlatform(
					userSession: $this->createMock(IUserSession::class),
					urlGenerator: $this->createMock(IURLGenerator::class),
					logger: new NullLogger()
				),
				tenancy: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectTenancy(
					organisationService: $this->createMock(OrganisationService::class)
				)
			),
			propertyRbacHandler: $this->createMock(PropertyRbacHandler::class),
			permissionHandler: $permissions,
			folderManagementHandler: $this->createMock(FolderManagementHandler::class)
		);

		$note = new Schema();
		$note->setId(2);
		$note->setSlug('note');
		$incident = new Schema();
		$incident->setId(1);
		$incident->setSlug('incident');
		$incident->setProperties(['note' => ['type' => 'object', '$ref' => 'note', 'objectConfiguration' => ['handling' => 'cascade']]]);
		$register = new Register();
		$register->setId(10);

		$reflection = new ReflectionClass(SaveObject::class);
		SaveLookupsAccess::set($handler, 'schemaReferenceCache', ['note' => '2']);
		SaveLookupsAccess::set($handler, 'schemaCache', ['2' => $note, '1' => $incident]);

		try {
			$handler->saveObject(data: ['note' => ['text' => 'nested']], options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $incident), uuid: $uuid), _rbac: $rbac);
			$this->fail('The refused nested create was saved.');
		} catch (NotAuthorizedException $e) {
			$this->assertSame(expected: 'no create in note', actual: $e->getMessage());
		}

		return $reflection->getProperty('cascadeScopes')->getValue($handler);
	}//end saveIncident()

	public function testASaveWithRbacOnChecksItsNestedCreates(): void {
		$open = $this->saveIncident(rbac: true);

		$this->assertSame(expected: [true], actual: $this->checks);
		$this->assertSame(expected: [], actual: $open);
	}

	public function testAnUpdateWithRbacOnChecksItsNestedCreates(): void {
		$open = $this->saveIncident(rbac: true, uuid: '11111111-1111-1111-1111-111111111111');

		$this->assertSame(expected: [true], actual: $this->checks);
		$this->assertSame(expected: [], actual: $open);
	}

	public function testAnUpdateWithRbacOffAsksWithRbacOff(): void {
		$open = $this->saveIncident(rbac: false, uuid: '11111111-1111-1111-1111-111111111111');

		$this->assertSame(expected: [false], actual: $this->checks);
		$this->assertSame(expected: [], actual: $open);
	}

	public function testASaveWithRbacOffAsksWithRbacOff(): void {
		$open = $this->saveIncident(rbac: false);

		$this->assertSame(expected: [false], actual: $this->checks);
		$this->assertSame(expected: [], actual: $open);
	}
}//end class
