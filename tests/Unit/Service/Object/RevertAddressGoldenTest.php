<?php

/**
 * Golden: which register/schema addresses a revert accepts and which it answers 404.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\RevertPoint;
use OCA\OpenRegister\Service\Object\RevertStores;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use TypeError;

/**
 * Pins the address check revert() runs before anything else (#4161).
 *
 * The route may name register and schema by id, UUID or slug; anything else,
 * an empty name, or a name of an entity that is not the one the object is
 * stored under, answers not found. An allowed address reaches the permission
 * check, which this test refuses so nothing is written.
 */
class RevertAddressGoldenTest extends TestCase {

	/**
	 * Run revert() for one address against one lookup and say what happened.
	 *
	 * @param array{0: string, 1: string} $given   Register and schema as the route names them.
	 * @param array{0: ?Register, 1: ?Schema} $found The register and schema the lookup returns.
	 *
	 * @return string `allowed` when the address passed, `not-found` when refused.
	 */
	private function outcome(array $given, array $found): string {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('5');
		$object->setSchema('7');

		$magic = $this->createMock(MagicMapper::class);
		$magic->method('findAcrossAllSources')->willReturn(['object' => $object, 'register' => $found[0], 'schema' => $found[1]]);
		$magic->expects($this->never())->method('update');

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willThrowException(new RuntimeException('address passed'));

		$handler = new RevertHandler(
			stores: new RevertStores(
				auditTrailMapper: $this->createMock(AuditTrailMapper::class),
				objectEntityMapper: $magic,
				validateHandler: $this->createMock(ValidateObject::class),
				saveObject: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject::class),
			),
			container: $this->createMock(ContainerInterface::class),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			permissionHandler: $permissions,
		);

		try {
			$handler->revert(register: $given[0], schema: $given[1], id: 'obj-1', point: new RevertPoint(until: '1.0.0'));
		} catch (DoesNotExistException $e) {
			$this->assertSame('Object not found in specified register/schema', $e->getMessage());
			return 'not-found';
		} catch (RuntimeException $e) {
			$this->assertSame('address passed', $e->getMessage());
			return 'allowed';
		} catch (TypeError $e) {
			// A lookup without a schema passes the address and fails at the permission check.
			$this->assertStringContainsString('hasPermission', $e->getMessage());
			return 'allowed';
		}

		return 'reverted';
	}//end outcome()

	/**
	 * A register with id 5 (or another id), uuid and slug as given.
	 *
	 * @param int    $id   The id.
	 * @param string $uuid The uuid, '' for none.
	 * @param string $slug The slug, '' for none.
	 *
	 * @return Register The register.
	 */
	private function register(int $id, string $uuid, string $slug): Register {
		$register = new Register();
		$register->setId($id);
		if ($uuid !== '') {
			$register->setUuid($uuid);
		}

		if ($slug !== '') {
			$register->setSlug($slug);
		}

		return $register;
	}//end register()

	/**
	 * The schema the object is stored under: id 7, uuid and slug set.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setUuid('schema-uuid');
		$schema->setSlug('items');

		return $schema;
	}//end schema()

	/**
	 * Every allowed and refused address.
	 *
	 * @return void
	 */
	public function testAddressOutcomes(): void {
		$stored = $this->register(5, 'reg-uuid', 'cases');
		$cases = [
			'ids'                       => [['5', '7'], [$stored, $this->schema()], 'allowed'],
			'uuids'                     => [['reg-uuid', 'schema-uuid'], [$stored, $this->schema()], 'allowed'],
			'slugs'                     => [['cases', 'items'], [$stored, $this->schema()], 'allowed'],
			'another register slug'     => [['other', 'items'], [$stored, $this->schema()], 'not-found'],
			'another schema slug'       => [['cases', 'other'], [$stored, $this->schema()], 'not-found'],
			'empty name, no uuid'       => [['', '7'], [$this->register(5, '', 'cases'), $this->schema()], 'not-found'],
			'empty name, no slug'       => [['', '7'], [$this->register(5, 'reg-uuid', ''), $this->schema()], 'not-found'],
			'slug of a different id'    => [['cases', '7'], [$this->register(6, 'reg-uuid', 'cases'), $this->schema()], 'not-found'],
			'id when lookup has none'   => [['5', '7'], [null, $this->schema()], 'allowed'],
			'slug when lookup has none' => [['cases', '7'], [null, $this->schema()], 'not-found'],
			'schema id, no schema'      => [['5', '7'], [$stored, null], 'allowed'],
			'schema slug, no schema'    => [['5', 'items'], [$stored, null], 'not-found'],
		];

		foreach ($cases as $label => [$given, $found, $expected]) {
			$this->assertSame($expected, $this->outcome(given: $given, found: $found), $label);
		}
	}//end testAddressOutcomes()
}//end class
