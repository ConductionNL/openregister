<?php

/**
 * The export's `_multi` / `multi` request switch only widens for administrators.
 *
 * `GET /api/objects/{register}/{schema}/export` hands the request parameters to
 * ExportService. A non-admin who added `?_multi=false` used to switch the
 * organisation filter off and export other organisations' rows (obj-f4).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class ExportServiceMultiTenancyTest extends TestCase {

	/**
	 * The `_multitenancy` argument the export's search receives.
	 *
	 * @param array<string, mixed> $filters The request filters.
	 * @param bool                 $isAdmin Whether the signed-in user is an administrator.
	 *
	 * @return array{0: bool, 1: mixed} The multitenancy flag and the explicit marker.
	 */
	private function multitenancyFor(array $filters, bool $isAdmin): array {
		$captured = [null, null];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjects')->willReturnCallback(
			static function (array $query, bool $_rbac = true, bool $_multitenancy = true) use (&$captured): array {
				$captured = [$_multitenancy, $query['_multitenancy_explicit'] ?? null];
				return [];
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('inGroup')->willReturn($isAdmin);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn($adminGroup);

		$service = new ExportService(
			$this->createMock(RegisterMapper::class),
			$this->createMock(IUserManager::class),
			$groups,
			$objectService,
			$this->createMock(CacheHandler::class),
			$this->createMock(PropertyRbacHandler::class),
			$this->createMock(TranslationHandler::class),
			null,
			$session
		);

		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(9);
		$service->countExportRows(register: $register, schema: $schema, filters: $filters);

		return $captured;
	}//end multitenancyFor()

	public function testANonAdminCannotSwitchTheOrganisationFilterOffWithUnderscoreMulti(): void {
		$this->assertSame([true, false], $this->multitenancyFor(filters: ['_multi' => 'false'], isAdmin: false));
	}

	public function testANonAdminCannotSwitchTheOrganisationFilterOffWithMulti(): void {
		$this->assertSame([true, false], $this->multitenancyFor(filters: ['multi' => 'false'], isAdmin: false));
	}

	public function testAnAdministratorMayStillWiden(): void {
		$this->assertSame([false, true], $this->multitenancyFor(filters: ['_multi' => 'false'], isAdmin: true));
	}
}
