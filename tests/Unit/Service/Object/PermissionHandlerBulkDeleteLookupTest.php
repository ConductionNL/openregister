<?php

/**
 * Unit coverage for the object lookup behind PermissionHandler::filterUuidsForPermissions().
 *
 * The filter is the only permission gate on `POST /api/bulk/{register}/{schema}/delete`:
 * ObjectService::deleteObjects() deletes exactly what this method hands back. It used to
 * resolve its candidates with `MagicMapper::findAll(ids: $uuids, includeDeleted: true)`,
 * which returns `[]` — after a warning and nothing else — whenever it is called without a
 * Register and a Schema entity, and this handler has neither. So the filter returned an
 * empty list for every input, the bulk delete loop never ran a single iteration, and the
 * endpoint answered `requested_count: 1, deleted_count: 0, skipped_count: 0` while leaving
 * the row in place.
 *
 * A bulk delete is cross-table by nature — its UUIDs may live in any magic table — so the
 * lookup that belongs here is the cross-table one, which is also what
 * ObjectService::batchResolveDeleteScopes() already uses on the very same UUIDs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
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

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Locks the lookup the bulk-delete permission filter uses to resolve its candidates.
 */
class PermissionHandlerBulkDeleteLookupTest extends TestCase {

	/**
	 * Build a PermissionHandler over a supplied object mapper.
	 *
	 * Every other collaborator is a bare double: with RBAC and multitenancy off,
	 * `filterUuidsForPermissions()` touches none of them.
	 *
	 * @param MagicMapper $objectMapper The mapper the handler should resolve through.
	 *
	 * @return PermissionHandler The handler under test.
	 */
	private function handlerOver(MagicMapper $objectMapper): PermissionHandler {
		// No OrganisationService on this container: the handler's own catch then
		// resolves "no active organisation", which is the shape a bulk delete on
		// a single-tenant instance already has.
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no organisation service'));

		return new PermissionHandler(
			userSession: $this->createMock(IUserSession::class),
			userManager: $this->createMock(IUserManager::class),
			groupManager: $this->createMock(IGroupManager::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			objectEntityMapper: $objectMapper,
			conditionMatcher: $this->createMock(ConditionMatcher::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			container: $container,
		);
	}//end handlerOver()

	/**
	 * Build an ObjectEntity carrying just a UUID.
	 *
	 * @param string $uuid The UUID to carry.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);

		return $object;
	}//end entity()

	/**
	 * A UUID the cross-table lookup resolves survives the filter.
	 *
	 * This is the property the endpoint depends on. The double answers the
	 * register/schema-scoped `findAll()` with `[]` exactly as the real
	 * MagicMapper does when called without that scope, so a handler that still
	 * asks the scoped lookup resolves nothing and fails here.
	 *
	 * @return void
	 */
	public function testResolvedUuidSurvivesTheFilter(): void {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findAll')->willReturn([]);
		$mapper->method('findMultipleAcrossAllMagicTables')
			->willReturn([$this->entity('uuid-alpha')]);

		$filtered = $this->handlerOver($mapper)->filterUuidsForPermissions(
			uuids: ['uuid-alpha'],
			_rbac: false,
			_multitenancy: false
		);

		$this->assertSame(['uuid-alpha'], $filtered);
	}//end testResolvedUuidSurvivesTheFilter()

	/**
	 * The lookup must include soft-deleted rows.
	 *
	 * A bulk delete of an already-trashed object is a hard delete, so a filter
	 * that only sees live rows would silently refuse every second delete.
	 *
	 * @return void
	 */
	public function testLookupIncludesSoftDeletedRows(): void {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findAll')->willReturn([]);
		$mapper->expects($this->once())
			->method('findMultipleAcrossAllMagicTables')
			->with(['uuid-alpha'], true)
			->willReturn([$this->entity('uuid-alpha')]);

		$this->handlerOver($mapper)->filterUuidsForPermissions(
			uuids: ['uuid-alpha'],
			_rbac: false,
			_multitenancy: false
		);
	}//end testLookupIncludesSoftDeletedRows()

	/**
	 * NEGATIVE CONTROL: an unresolvable UUID does not survive the filter.
	 *
	 * Without this, `return $uuids;` would satisfy the two tests above — the
	 * filter would stop being a filter and every caller's RBAC gate with it.
	 *
	 * @return void
	 */
	public function testUnresolvableUuidIsDropped(): void {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findAll')->willReturn([]);
		$mapper->method('findMultipleAcrossAllMagicTables')
			->willReturn([$this->entity('uuid-alpha')]);

		$filtered = $this->handlerOver($mapper)->filterUuidsForPermissions(
			uuids: ['uuid-alpha', 'uuid-ghost'],
			_rbac: false,
			_multitenancy: false
		);

		$this->assertSame(['uuid-alpha'], $filtered);
	}//end testUnresolvableUuidIsDropped()
}//end class
