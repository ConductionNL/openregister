<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A same-version re-import that differs only in `orphanCleanup` is a change.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\ImportedSchemaLookup;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\ImportedSchemaComparison;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use GuzzleHttp\Client;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\ImportHandler
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Configuration\ImportReferenceMap
 * @uses   \OCA\OpenRegister\Service\Configuration\SchemaImportOptions
 * @uses \OCA\OpenRegister\Service\Configuration\SeedRelatedItems
 * @uses \OCA\OpenRegister\Service\Configuration\ImportDuplicates
 * @uses \OCA\OpenRegister\Service\Configuration\ShippedSchemaBaseline
 * @uses \OCA\OpenRegister\Service\Configuration\AppConfigurationRecord
 * @uses \OCA\OpenRegister\Service\Configuration\ImportedSchemaComparison
 * @uses \OCA\OpenRegister\Service\Configuration\ImportedSchemaShape
 * @uses \OCA\OpenRegister\Service\Configuration\RejectedSchemaReport
 * @uses \OCA\OpenRegister\Service\Configuration\ForeignSeedSchema
 * @uses \OCA\OpenRegister\Service\Configuration\ArrayStructure
 */
class ImportHandlerOrphanCleanupCompareTest extends TestCase {

	/**
	 * What an import of this data keeps, for a caller.
	 *
	 * @param array       $data The incoming schema.
	 * @param string|null $uid  The signed-in user, null for no session.
	 *
	 * @return array
	 */
	private function imported(array $data, ?string $uid): array {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $who): bool => $who === 'root');
		$handler = (new ReflectionClass(ImportHandler::class))->newInstanceWithoutConstructor();
		$handler->setUserSession($session);
		$handler->setGroupManager($groups);

		return (new ReflectionMethod(ImportHandler::class, 'withoutOrphanCleanupForNonAdmin'))->invoke($handler, $data);
	}//end imported()

	public function testANonAdministratorsImportDropsTheFlagAndKeepsTheRest(): void {
		$this->assertSame(
			['configuration' => ['allowFiles' => true]],
			$this->imported(data: ['configuration' => ['orphanCleanup' => true, 'allowFiles' => true]], uid: 'manager')
		);
	}

	public function testANonAdministratorsImportDropsTheFlagFromJsonText(): void {
		$this->assertSame(
			['configuration' => ['allowFiles' => true]],
			$this->imported(data: ['configuration' => '{"orphanCleanup":true,"allowFiles":true}'], uid: 'manager')
		);
	}

	public function testAnAdministratorsImportKeepsTheFlag(): void {
		$data = ['configuration' => ['orphanCleanup' => true]];

		$this->assertSame($data, $this->imported(data: $data, uid: 'root'));
	}

	public function testAnImportWithoutASessionKeepsTheFlag(): void {
		$data = ['configuration' => ['orphanCleanup' => true]];

		$this->assertSame($data, $this->imported(data: $data, uid: null));
	}

	public function testAnImportWithoutTheFlagIsLeftAlone(): void {
		$data = ['configuration' => '{"allowFiles":true}'];

		$this->assertSame($data, $this->imported(data: $data, uid: 'manager'));
	}

	public function testARegisterImportByANonAdministratorStoresNoFlag(): void {
		$stored = [];
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('createFromArray')->willReturnCallback(
			static function (array $object) use (&$stored): Schema {
				$stored = $object['configuration'];
				return new Schema();
			}
		);
		$schemas->method('update')->willReturnArgument(0);
		$handler = new ImportHandler(
			schemaMapper: $schemas,
			registerMapper: $this->createMock(RegisterMapper::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper: $this->createMock(MappingMapper::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			appDataPath: '/tmp',
			objectService: $this->createMock(ObjectService::class)
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$handler->setUserSession($session);
		$handler->setGroupManager($this->createMock(IGroupManager::class));

		$handler->importSchema(
			definition: new ImportedSchemaLookup(
				data: ['slug' => 'demo', 'title' => 'Demo', 'properties' => [], 'configuration' => ['orphanCleanup' => true, 'allowFiles' => true]],
				registerSchemaIds: []
			),
			slugsAndIdsMap: []
		);

		$this->assertSame(['allowFiles' => true], $stored);
	}

	public function testTheFlagIsAComparedConfigurationKey(): void {
		$this->assertTrue((new ImportedSchemaComparison())->differs(data: ['orphanCleanup' => ['allow' => true]], existing: new Schema()));
	}
}//end class
