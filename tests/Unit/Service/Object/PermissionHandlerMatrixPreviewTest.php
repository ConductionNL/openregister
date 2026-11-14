<?php

/**
 * PermissionHandler::previewDepartmentMatrix(): the scopes a schema's matrix
 * compiles to for a chosen user.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixCompiler;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The preview compiles for the user asked about, not the administrator asking,
 * and compiles exactly what the authorization path compiles.
 */
class PermissionHandlerMatrixPreviewTest extends TestCase {

	/**
	 * The groups of each user.
	 */
	private const GROUPS = [
		'admin-ann' => ['admin', 'dept:Belastingen'],
		'bob' => ['handlers', 'dept:VTH', 'dept:BOR', 'other'],
	];

	/**
	 * A user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * The handler, with `admin-ann` signed in.
	 *
	 * @return PermissionHandler
	 */
	private function handler(): PermissionHandler {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user(uid: 'admin-ann'));
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => isset(self::GROUPS[$uid]) === true ? $this->user(uid: $uid) : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturnCallback(static fn (IUser $user): array => self::GROUPS[$user->getUID()]);

		return PermissionHandlerFactory::create(
			userSession: $session,
			userManager: $users,
			groupManager: $groups,
			schemaMapper: $this->createMock(SchemaMapperInterface::class),
			objectEntityMapper: $this->createMock(MagicMapperInterface::class),
			conditionMatcher: $this->createMock(ConditionMatcher::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			container: $this->createMock(ContainerInterface::class)
		);
	}//end handler()

	/**
	 * A case schema whose authorization carries this matrix.
	 *
	 * @param array<string, mixed>|null $matrix The matrix, or null for none.
	 *
	 * @return Schema
	 */
	private function schema(?array $matrix): Schema {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setSlug('case');
		$schema->setProperties(['department' => ['type' => 'string']]);
		$authorization = ['read' => ['admin']];
		if ($matrix !== null) {
			$authorization[DepartmentMatrixCompiler::KEY] = $matrix;
		}

		$schema->setAuthorization($authorization);
		return $schema;
	}//end schema()

	/**
	 * The matrix of the scenario: `$self` for handlers, and a fixed row.
	 *
	 * @return array<string, mixed>
	 */
	private static function matrix(): array {
		return [
			'field' => 'department',
			'userSource' => ['groupPrefix' => 'dept:'],
			'rows' => [
				['value' => '$self', 'group' => 'handlers', 'actions' => ['read', 'update']],
				['value' => 'Archief', 'group' => 'archivists', 'actions' => ['read']],
			],
		];
	}//end matrix()

	/**
	 * The preview is the asked user's own values and the scopes the compiler gives them.
	 *
	 * @return void
	 */
	public function testThePreviewCompilesForTheChosenUser(): void {
		$schema = $this->schema(matrix: self::matrix());

		$preview = $this->handler()->previewDepartmentMatrix($schema, $this->user(uid: 'bob'));

		$this->assertSame(['VTH', 'BOR'], $preview['values']);
		$expected = (new DepartmentMatrixCompiler(declaredActions: $schema->declaredActionNames()))->compile(matrix: self::matrix(), ownValues: ['VTH', 'BOR']);
		$this->assertNotSame([], $expected);
		$this->assertSame($expected, $preview['scopes']);
	}//end testThePreviewCompilesForTheChosenUser()

	/**
	 * Another user gets other values: the administrator's own groups never leak in.
	 *
	 * @return void
	 */
	public function testThePreviewDoesNotUseTheAdministratorsGroups(): void {
		$preview = $this->handler()->previewDepartmentMatrix($this->schema(matrix: self::matrix()), $this->user(uid: 'admin-ann'));

		$this->assertSame(['Belastingen'], $preview['values']);
	}//end testThePreviewDoesNotUseTheAdministratorsGroups()

	/**
	 * A schema without a matrix previews to nothing.
	 *
	 * @return void
	 */
	public function testASchemaWithoutAMatrixPreviewsNothing(): void {
		$this->assertSame(
			['values' => [], 'scopes' => []],
			$this->handler()->previewDepartmentMatrix($this->schema(matrix: null), $this->user(uid: 'bob'))
		);
	}//end testASchemaWithoutAMatrixPreviewsNothing()
}//end class
