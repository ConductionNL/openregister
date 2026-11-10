<?php

/**
 * Golden outcomes of the effective-scope discovery endpoint.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ScopesController;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use OCA\OpenRegister\Controller\Support\ScopesControllerServices;

/**
 * Pins what each caller (anonymous, member, admin) is told it may do, and every fallback.
 */
class ScopesControllerA24GoldenTest extends TestCase {
	/**
	 * The userSession of the case.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;
	/**
	 * The groupManager of the case.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;
	/**
	 * The registers of the case.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface&MockObject $registers;
	/**
	 * The schemas of the case.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface&MockObject $schemas;
	/**
	 * The permissions of the case.
	 *
	 * @var PermissionHandlerInterface&MockObject
	 */
	private PermissionHandlerInterface&MockObject $permissions;
	/**
	 * The controller of the case.
	 *
	 * @var ScopesController
	 */
	private ScopesController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
		$this->controller = new ScopesController(
			$this->createMock(IRequest::class),
			new ControllerSession($this->userSession, $this->groupManager, new NullLogger()),
			new ScopesControllerServices($this->registers, $this->schemas),
			$this->permissions
		);
	}

	private function signIn(array $groups): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);
	}

	private function register(string $slug, array $schemaIds): Register {
		$register = new Register();
		$register->setSlug($slug);
		$register->setSchemas($schemaIds);
		return $register;
	}

	private function schema(int $id, string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		return $schema;
	}

	private function catalogue(): void {
		$this->registers->method('findAll')->willReturn([$this->register('zaken', [1, 2]), $this->register('hr', [3])]);
		$this->schemas->method('findAll')->willReturn([$this->schema(1, 'zaak'), $this->schema(2, 'besluit'), $this->schema(3, 'medewerker')]);
	}

	public function testAnAdminHoldsEveryActionByBypassOnEveryPair(): void {
		$this->signIn(['admin', 'users']);
		$this->catalogue();
		$this->permissions->expects($this->never())->method('hasPermission');
		$this->permissions->expects($this->never())->method('provenanceFor');

		$data = $this->controller->index()->getData();

		$bypass = ['granted' => true, 'source' => 'admin', 'rule' => null, 'principal' => 'admin', 'role' => null];
		$this->assertSame('alice', $data['user']);
		$this->assertTrue($data['isAdmin']);
		$this->assertSame(['admin', 'users'], $data['groups']);
		$this->assertSame(
			[['zaken', 'zaak'], ['zaken', 'besluit'], ['hr', 'medewerker']],
			array_map(static fn (array $s): array => [$s['register'], $s['schema']], $data['scopes'])
		);
		$this->assertSame(ScopesController::ACTIONS, $data['scopes'][0]['actions']);
		$this->assertSame(array_fill_keys(ScopesController::ACTIONS, $bypass), $data['scopes'][2]['provenance']);
	}

	public function testAMemberIsToldOnlyWhatThePermissionChainGrants(): void {
		$this->signIn(['users']);
		$this->catalogue();
		$this->permissions->method('hasPermission')->willReturnCallback(
			static function (Schema $schema, string $action, ?string $userId, ?string $owner, bool $rbac): bool {
				if ($userId !== 'alice' || $owner !== null || $rbac !== true) {
					return false;
				}
				if ($schema->getSlug() === 'besluit' && $action === 'delete') {
					throw new RuntimeException('broken rule');
				}
				return $schema->getSlug() !== 'medewerker' && in_array($action, ['read', 'list', 'delete'], true);
			}
		);
		$this->permissions->method('provenanceFor')->willReturnCallback(
			static function (Schema $schema): array {
				if ($schema->getSlug() === 'besluit') {
					throw new RuntimeException('no provenance');
				}
				return ['read' => ['granted' => true, 'source' => 'role']];
			}
		);

		$data = $this->controller->index()->getData();

		$this->assertFalse($data['isAdmin']);
		$this->assertSame(
			[
				[
					'register' => 'zaken',
					'schema' => 'zaak',
					'actions' => ['read', 'delete', 'list'],
					'provenance' => ['read' => ['granted' => true, 'source' => 'role']],
				],
				['register' => 'zaken', 'schema' => 'besluit', 'actions' => ['read', 'list'], 'provenance' => []],
			],
			$data['scopes']
		);
	}

	public function testAnAnonymousCallerIsProbedWithoutAUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->groupManager->expects($this->never())->method('getUserGroupIds');
		$this->catalogue();
		$this->permissions->method('hasPermission')->willReturnCallback(
			static fn (Schema $schema, string $action, ?string $userId): bool => $userId === null && $action === 'read' && $schema->getId() === 3
		);
		$this->permissions->method('provenanceFor')->willReturn([]);

		$data = $this->controller->index()->getData();

		$this->assertSame(
			[
				'user' => null,
				'isAdmin' => false,
				'groups' => [],
				'scopes' => [['register' => 'hr', 'schema' => 'medewerker', 'actions' => ['read'], 'provenance' => []]],
			],
			$data
		);
	}

	public function testFiltersResolveOneRegisterAndSchemaWithRbacOff(): void {
		$this->signIn(['admin']);
		$this->registers->expects($this->once())->method('find')->with('hr', false)->willReturn($this->register('hr', [3]));
		$this->schemas->expects($this->once())->method('find')->with('medewerker', [], false)->willReturn($this->schema(3, 'medewerker'));
		$this->registers->expects($this->never())->method('findAll');

		$data = $this->controller->index('hr', 'medewerker')->getData();

		$this->assertCount(1, $data['scopes']);
	}

	public function testEmptyFiltersListEverythingWithRbacOff(): void {
		$this->signIn(['admin']);
		$this->registers->expects($this->once())->method('findAll')->with(null, null, [], [], [], false)->willReturn([]);
		$this->schemas->expects($this->once())->method('findAll')->willReturn([]);

		$this->assertSame([], $this->controller->index('', '')->getData()['scopes']);
	}

	public function testAnUnresolvableFilterOrFailingListAnswersNoScopes(): void {
		$this->signIn(['admin']);
		$this->registers->method('find')->willThrowException(new RuntimeException('nope'));
		$this->schemas->method('find')->willThrowException(new RuntimeException('nope'));
		$this->registers->method('findAll')->willThrowException(new RuntimeException('down'));
		$this->schemas->method('findAll')->willThrowException(new RuntimeException('down'));

		$this->assertSame([], $this->controller->index('x', null)->getData()['scopes']);
		$this->assertSame([], $this->controller->index(null, 'y')->getData()['scopes']);
		$this->assertSame(200, $this->controller->index()->getStatus());
	}
}
