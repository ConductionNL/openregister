<?php

/**
 * Inside an organisation context the shared master data write guard runs
 * even when the caller passes `_multitenancy: false`, as the organisation
 * filter does.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\SharedMasterDataWriteException;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;

/**
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
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Exception\SharedMasterDataWriteException
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugBatch
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugClientValue
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugLog
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugSource
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugStore
 * @uses   \OCA\OpenRegister\Service\TenantLogRedactor
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Object\ObjectVersionHandler
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceAdminBypass
 * @uses \OCA\OpenRegister\Service\Rbac\AdminGate
 * @uses   \OCA\OpenRegister\Db\AuditTrail\AuditedObject
 * @uses   \OCA\OpenRegister\Db\AuditTrail\NamedActor
 * @uses   \OCA\OpenRegister\Db\AuditTrail\SharedMasterDataRefusal
 * @uses   \OCA\OpenRegister\Db\AuditTrail\SharedMasterDataRefusalOptions
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Db\Schema\ObjectSourceDeclaration
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\DeclaredRow
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\NestedSaveTransaction
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
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SharedWriteAttempt
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheckOptions
 * @uses   \OCA\OpenRegister\Db\LockTerms
 * @uses   \OCA\OpenRegister\Db\Schema\PropertyAuthorization
 * @uses   \OCA\OpenRegister\Db\Schema\SchemaPropertyDocument
 * @uses   \OCA\OpenRegister\Db\SoftDeletion
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\CascadeRun
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\DefaultValueContext
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\EmptyValues
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\FolderBind
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\InverseWriteBack
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectPayload
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\PropertyDefaults
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\RelationScanLevel
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\RelationScanner
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SelfSubmission
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SlugRequest
 * @uses   \OCA\OpenRegister\Service\Object\TranslationScope
 * @uses \OCA\OpenRegister\Db\AuditTrail\AuditedObject
 * @uses \OCA\OpenRegister\Db\AuditTrail\NamedActor
 * @uses \OCA\OpenRegister\Db\AuditTrail\SharedMasterDataRefusal
 * @uses \OCA\OpenRegister\Db\AuditTrail\SharedMasterDataRefusalOptions
 * @uses \OCA\OpenRegister\Db\SchemaCatalog
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses \OCA\OpenRegister\Service\DeclaredRow
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectWriteTarget
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectHandlers
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectMappers
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPersistence
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPlatform
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPropertyHandlers
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRecords
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRuntime
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectServices
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectTenancy
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectValueHandlers
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SharedWriteAttempt
 * @uses \OCA\OpenRegister\Db\Schema\ObjectSourceDeclaration
 * @uses \OCA\OpenRegister\Db\Schema\PropertyAuthorization
 * @uses \OCA\OpenRegister\Db\Schema\SchemaPropertyDocument
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\CascadeRun
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\DefaultValueContext
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\EmptyValues
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\FolderBind
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\InverseWriteBack
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectPayload
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\PropertyDefaults
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\RelationScanLevel
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\RelationScanner
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SelfSubmission
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SlugRequest
 * @uses \OCA\OpenRegister\Service\Object\TranslationScope
 */
class SaveObjectSharedMasterDataContextTest extends TestCase {

	/**
	 * How often the guard asked whether the resource is writable.
	 *
	 * @var integer
	 */
	private int $asked = 0;

	public function testASaveInsideAContextIsGuardedWithMultitenancyOff(): void {
		$handler = $this->handler();

		$this->expectException(SharedMasterDataWriteException::class);
		OrganisationContext::run(
			'org-consumer',
			fn () => $handler->saveObject(data: ['name' => 'x'], options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $this->register(), schema: $this->schema())), _multitenancy: false)
		);
	}//end testASaveInsideAContextIsGuardedWithMultitenancyOff()

	public function testASaveOutsideAContextWithMultitenancyOffIsNotGuarded(): void {
		try {
			$this->handler()->saveObject(data: ['name' => 'x'], options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $this->register(), schema: $this->schema())), _multitenancy: false);
		} catch (SharedMasterDataWriteException $e) {
			self::fail('the guard must not run when the caller turned multitenancy off outside a context');
		} catch (Throwable $e) {
			// The doubles stop the save further on; only the guard matters here.
		}

		self::assertSame(0, $this->asked);
	}//end testASaveOutsideAContextWithMultitenancyOffIsNotGuarded()

	/**
	 * A SaveObject whose shared master data guard refuses every write.
	 *
	 * @return SaveObject
	 */
	private function handler(): SaveObject {
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getUserActiveOrganisations')->willReturn(['org-consumer']);

		$shares = $this->createMock(SharedMasterDataService::class);
		$shares->method('assertWritable')->willReturnCallback(
			function (): void {
				$this->asked++;
				throw new SharedMasterDataWriteException(holderUuid: 'org-holder');
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === SharedMasterDataService::class) ? $shares : throw new \RuntimeException('no '.$id)
		);

		return new SaveObject(
			services: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectServices(
				persistence: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPersistence(
					mappers: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectMappers(
						catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(MagicMapper::class)),
						unifiedObjectMapper: $this->createMock(MagicMapper::class)
					),
					records: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRecords(
						auditTrailMapper: $this->createMock(AuditTrailMapper::class),
						settingsService: $this->createMock(SettingsService::class),
						tmloService: $this->createMock(\OCA\OpenRegister\Service\TmloService::class)
					),
					runtime: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRuntime(
						cacheHandler: $this->createMock(CacheHandler::class),
						container: $container
					)
				),
				handlers: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectHandlers(
					properties: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPropertyHandlers(
						metaHydrationHandler: $this->createMock(MetadataHydrationHandler::class),
						filePropertyHandler: $this->createMock(FilePropertyHandler::class),
						linkedEntityHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler::class),
						computedFieldHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class)
					),
					values: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectValueHandlers(
						translationHandler: $this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class),
						translationProjectionService: $this->createMock(\OCA\OpenRegister\Service\TranslationProjectionService::class),
						translationStatusService: $this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class)
					)
				),
				platform: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPlatform(
					userSession: $this->createMock(IUserSession::class),
					urlGenerator: $this->createMock(IURLGenerator::class),
					logger: $this->createMock(LoggerInterface::class)
				),
				tenancy: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectTenancy(
					organisationService: $organisations
				)
			),
			propertyRbacHandler: $this->createMock(PropertyRbacHandler::class),
			permissionHandler: $this->createMock(\OCA\OpenRegister\Service\Object\PermissionHandler::class),
			folderManagementHandler: $this->createMock(\OCA\OpenRegister\Service\File\FolderManagementHandler::class)
		);
	}//end handler()

	/**
	 * The schema written to.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(41);
		$schema->setSlug('product');

		return $schema;
	}//end schema()

	/**
	 * The register written to.
	 *
	 * @return Register
	 */
	private function register(): Register {
		$register = new Register();
		$register->setId(4);
		$register->setSlug('catalogue');

		return $register;
	}//end register()
}//end class
