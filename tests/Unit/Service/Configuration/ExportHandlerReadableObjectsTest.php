<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ExportHandler;
use OCA\OpenRegister\Service\Configuration\ReadableExportObjects;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A register export with its objects hands a caller only what that caller may read.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
class ExportHandlerReadableObjectsTest extends TestCase {

	/**
	 * The exported objects of a register holding one person, for the given caller.
	 *
	 * @param string|null $uid     The signed-in user, null for none.
	 * @param bool        $isAdmin Whether that user is an administrator.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function exportedObjects(?string $uid, bool $isAdmin): array {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('hr');

		$schema = new Schema();
		$schema->setId(12);
		$schema->setSlug('person');
		$schema->setProperties(
			[
				'name' => ['type' => 'string'],
				'salary' => ['type' => 'number', 'authorization' => ['read' => [['group' => 'hr']]]],
				'apiToken' => ['type' => 'string', 'writeOnly' => true],
				'bsn' => ['type' => 'string', 'authorization' => ['reveal' => 'explicit']],
			]
		);

		$object = new ObjectEntity();
		$object->setUuid('11111111-1111-4111-8111-111111111111');
		$object->setRegister('1');
		$object->setSchema('12');
		$object->setObject(['name' => 'Ada', 'salary' => 5000, 'apiToken' => 'secret-token', 'bsn' => '123456782']);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('getSchemasByRegisterId')->willReturn([$schema]);
		$registerMapper->method('getIdToSlugMap')->willReturn(['1' => 'hr']);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('getIdToSlugMap')->willReturn(['12' => 'person']);
		$objects = $this->createMock(MagicMapper::class);
		$objects->method('findAllInRegisterSchemaTable')->willReturn([$object]);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);
		$properties = $this->createMock(PropertyRbacHandler::class);
		$properties->method('filterReadableProperties')->willReturnCallback(
			static function (Schema $schema, array $object): array {
				unset($object['salary'], $object['apiToken']);
				return $object;
			}
		);

		$handler = new ExportHandler(
			schemaMapper: $schemaMapper,
			registerMapper: $registerMapper,
			objectEntityMapper: $objects,
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper: $this->createMock(MappingMapper::class),
			logger: new NullLogger(),
			readableObjects: new ReadableExportObjects(properties: $properties, userSession: $session, groupManager: $groups)
		);

		return $handler->exportConfig(input: $register, includeObjects: true)['components']['objects'];
	}//end exportedObjects()

	public function testASignedInUserGetsTheObjectsAsTheyMayReadThem(): void {
		$exported = $this->exportedObjects(uid: 'medewerker', isAdmin: false);

		$this->assertCount(1, $exported);
		$this->assertSame('Ada', $exported[0]['name']);
		$this->assertArrayNotHasKey('salary', $exported[0]);
		$this->assertArrayNotHasKey('apiToken', $exported[0]);
		$this->assertNotSame('123456782', $exported[0]['bsn']);
		$this->assertSame('person', $exported[0]['@self']['schema']);
	}//end testASignedInUserGetsTheObjectsAsTheyMayReadThem()

	public function testAnAdministratorExportsTheStoredObjects(): void {
		$exported = $this->exportedObjects(uid: 'admin', isAdmin: true);

		$this->assertSame(5000, $exported[0]['salary']);
		$this->assertSame('secret-token', $exported[0]['apiToken']);
		$this->assertSame('123456782', $exported[0]['bsn']);
	}//end testAnAdministratorExportsTheStoredObjects()

	public function testARunWithoutASignedInUserExportsTheStoredObjects(): void {
		$exported = $this->exportedObjects(uid: null, isAdmin: false);

		$this->assertSame(5000, $exported[0]['salary']);
	}//end testARunWithoutASignedInUserExportsTheStoredObjects()
}//end class
