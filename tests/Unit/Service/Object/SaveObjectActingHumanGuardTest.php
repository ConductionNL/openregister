<?php

/**
 * SaveObject asks the acting-human guard before a persisting save (REQ-SAT-006).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ActingHumanRefusedException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Authorization\ActingHumanGuardInterface;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Through saveObject(): the guard is asked for a persisting save and its refusal stops the save.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 */
class SaveObjectActingHumanGuardTest extends TestCase {

	/**
	 * The schema slugs the guard was asked about.
	 *
	 * @var array<int, string|null>
	 */
	private array $asked = [];

	public function testAPersistingSaveIsRefusedByTheGuard(): void {
		try {
			$this->handler()->saveObject(
				data: ['name' => 'x'],
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $this->register(), schema: $this->schema()))
			);
			self::fail('the guard\'s refusal must stop the save');
		} catch (ActingHumanRefusedException $refusal) {
			self::assertSame('acting-human-required', $refusal->getError());
		}

		self::assertSame(['publicatie'], $this->asked);
	}//end testAPersistingSaveIsRefusedByTheGuard()

	public function testANonPersistingSaveDoesNotAsk(): void {
		try {
			$this->handler()->saveObject(
				data: ['name' => 'x'],
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $this->register(), schema: $this->schema())),
				persist: false
			);
		} catch (ActingHumanRefusedException $refusal) {
			self::fail('a save that persists nothing writes nothing a human must answer for');
		} catch (Throwable $e) {
			// The doubles stop the save further on; only the guard matters here.
		}

		self::assertSame([], $this->asked);
	}//end testANonPersistingSaveDoesNotAsk()

	private function handler(): SaveObject {
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getUserActiveOrganisations')->willReturn([]);

		$guard = $this->createMock(ActingHumanGuardInterface::class);
		$guard->method('assertMayWrite')->willReturnCallback(
			function (?Schema $schema): void {
				$this->asked[] = $schema?->getSlug();
				throw ActingHumanRefusedException::required();
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === ActingHumanGuardInterface::class) ? $guard : throw new \RuntimeException('no '.$id)
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
		$schema->setSlug('publicatie');

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
		$register->setSlug('woo');

		return $register;
	}//end register()
}//end class
