<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\RegistersController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\AuthorizationBlockException;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\Registers\RegisterCacheHandler;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCA\OpenRegister\Files\Service\UploadService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\RegistersControllerFactory;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DBException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\AuthorizationChange;

/**
 * Golden test for RegistersController::create(), update() and patch().
 *
 * Every case records each collaborator call in order and the response, and
 * compares the recording with fixtures/registers-writes-golden.json. Set
 * UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\RegistersController
 * @uses \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses \OCA\OpenRegister\Controller\Support\RegisterDownloads
 * @uses \OCA\OpenRegister\Controller\Support\RegisterGitHubPublisher
 * @uses \OCA\OpenRegister\Controller\Support\RegisterAccessAudit
 * @uses \OCA\OpenRegister\Controller\Support\RegisterUpdate
 * @uses \OCA\OpenRegister\Controller\Support\RegisterStatistics
 * @uses \OCA\OpenRegister\Controller\Support\RegistersControllerServices
 * @uses \OCA\OpenRegister\Controller\Support\RegisterMappers
 * @uses \OCA\OpenRegister\Controller\Support\RegisterServices
 * @uses \OCA\OpenRegister\Controller\Support\RegisterIntegrations
 * @uses \OCA\OpenRegister\Controller\Support\ObjectTransfer
 * @uses \OCA\OpenRegister\Service\Serializer\RegisterSerializer
 * @uses \OCA\OpenRegister\Service\Rbac\DefinitionWriteRights
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Exception\AuthorizationBlockException
 * @uses \OCA\OpenRegister\Exception\DatabaseConstraintException
 */
class RegistersControllerWritesGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/registers-writes-golden.json';

	private const AUTH_OLD = ['manage' => ['editors']];

	private const AUTH_NEW = ['manage' => ['editors', 'owners']];

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	private ?Register $found = null;

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$root = ['user' => 'root', 'admin' => true];
		$fields = ['title' => 'New', '_route' => 'r', '_limit' => 1, 'description' => 'd'];
		$immutable = ['id' => 9, 'organisation' => 'o', 'owner' => 'x', 'created' => 'c', 'title' => 'New'];
		$authorization = ['authorization' => self::AUTH_NEW];
		$roles = ['configuration' => ['roles' => ['r2']]];

		$cases = [
			'create anonymous' => ['action' => 'create', 'user' => null],
			'create by somebody else' => ['action' => 'create', 'user' => 'other'],
			'create drops internal fields' => ['action' => 'create', 'params' => $fields] + $root,
			'create drops the id' => ['action' => 'create', 'params' => ['id' => 9, 'title' => 'New']] + $root,
			'create keeps a null id' => ['action' => 'create', 'params' => ['id' => null, 'title' => 'New']] + $root,
			'create with no fields' => ['action' => 'create'] + $root,
			'create refused rules' => ['action' => 'create', 'throw' => 'block'] + $root,
			'create database error' => ['action' => 'create', 'throw' => 'db'] + $root,
			'create constraint' => ['action' => 'create', 'throw' => 'constraint'] + $root,
			'create other failure' => ['action' => 'create', 'throw' => 'other'] + $root,
			'create cache failure' => ['action' => 'create', 'cacheFails' => true] + $root,

			'update unknown register' => ['missing' => true] + $root,
			'update anonymous' => ['user' => null],
			'update by somebody else' => ['user' => 'other'],
			'update without manage rules' => ['user' => 'other', 'auth' => null],
			'update by a manager' => ['user' => 'member', 'groups' => ['editors'], 'params' => ['title' => 'New']],
			'update when the rights fail' => ['rightsFail' => true] + $root,
			'update drops internal fields' => ['params' => $fields] + $root,
			'update drops immutable fields' => ['params' => $immutable] + $root,
			'update with no fields' => $root,
			'update authorization' => ['params' => $authorization] + $root,
			'update roles' => ['params' => $roles] + $root,
			'update both' => ['params' => $authorization + $roles] + $root,
			'update both without a title' => ['params' => $authorization + $roles, 'title' => null] + $root,
			'update authorization to empty' => ['params' => ['authorization' => []]] + $root,
			'update null authorization' => ['params' => ['authorization' => null]] + $root,
			'update other configuration' => ['params' => ['configuration' => ['x' => 1]]] + $root,
			'update drops the roles' => ['params' => $roles, 'after' => ['configuration' => []]] + $root,
			'update without earlier roles' => ['params' => $roles, 'before' => ['configuration' => []]] + $root,
			'update audit unavailable' => ['params' => $authorization, 'auditMissing' => true] + $root,
			'update audit fails' => ['params' => $authorization + $roles, 'auditFails' => 'logRegisterAuthorizationChange'] + $root,
			'update roles audit fails' => ['params' => $authorization + $roles, 'auditFails' => 'logRoleDefinitionChange'] + $root,
			'update refused rules' => ['throw' => 'block'] + $root,
			'update database error' => ['throw' => 'db'] + $root,
			'update constraint' => ['throw' => 'constraint'] + $root,
			'update other failure' => ['throw' => 'other'] + $root,
			'update cache failure' => ['cacheFails' => true] + $root,
			'patch' => ['action' => 'patch', 'params' => $authorization] + $root,
			'patch by somebody else' => ['action' => 'patch', 'user' => 'other'],
		];

		$named = [];
		foreach ($cases as $name => $case) {
			$named[$name] = [$case];
		}

		return $named;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	#[DataProvider('cases')]
	public function testWriteMatchesGolden(array $case): void {
		$this->calls = [];
		$recording = $this->answer(controller: $this->buildController(case: $case), action: ($case['action'] ?? 'update'));
		$recording = ['calls' => $this->calls] + $recording;

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = $this->dataName();
		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $recording;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertNotSame([], $recording['data'] ?? $recording['thrown']);
		$this->assertSame($golden[$name] ?? null, json_decode((string)json_encode($recording), true));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function answer(RegistersController $controller, string $action): array {
		try {
			$response = $action === 'create' ? $controller->create() : $controller->$action(7);
		} catch (Exception $e) {
			return ['thrown' => get_class($e), 'message' => $e->getMessage()];
		}

		$data = $response->getData();
		if ($data instanceof Register) {
			$data = ['register' => $data->getId(), 'title' => $data->getTitle()];
		}

		return ['status' => $response->getStatus(), 'data' => $data];
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function buildController(array $case): RegistersController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(function () use ($case): array {
			$this->calls[] = ['getParams'];
			return ($case['params'] ?? []);
		});

		$uid = array_key_exists('user', $case) === true ? $case['user'] : 'other';
		$user = $this->user(uid: $uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->groupManager(case: $case);
		$audit = $this->auditService(case: $case);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($case, $groups, $audit) {
			$this->calls[] = ['container', $id];
			if ($id === IGroupManager::class) {
				if (($case['rightsFail'] ?? false) === true) {
					throw new RuntimeException('no group manager');
				}

				return $groups;
			}

			if (($case['auditMissing'] ?? false) === true) {
				throw new RuntimeException('no audit service');
			}

			return $audit;
		});

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(function ($id) use ($case): Register {
			$this->calls[] = ['find', $id];
			if (($case['missing'] ?? false) === true) {
				throw new DoesNotExistException('gone');
			}

			$this->found = $this->register(
				authorization: array_key_exists('auth', $case) === true ? $case['auth'] : self::AUTH_OLD,
				configuration: ($case['before'] ?? ['configuration' => ['roles' => ['r1']]])['configuration'],
				title: 'Old'
			);
			return $this->found;
		});

		$cache = $this->createMock(RegisterCacheHandler::class);
		$cache->method('invalidate')->willReturnCallback(function (int $registerId) use ($case): void {
			$this->calls[] = ['invalidate', $registerId];
			if (($case['cacheFails'] ?? false) === true) {
				throw new RuntimeException('cache failed');
			}
		});

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		return RegistersControllerFactory::create(
			'openregister',
			$request,
			$this->registerService(case: $case),
			$this->createMock(MagicMapper::class),
			$this->createMock(UploadService::class),
			$logger,
			$session,
			$this->createMock(ConfigurationService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$schemaMapper,
			$registerMapper,
			$this->createMock(GitHubHandler::class),
			$this->createMock(IAppManager::class),
			$this->createMock(OasService::class),
			$container,
			$groups,
			$cache,
			new RegisterSerializer($schemaMapper, $logger),
			$this->createMock(MigrationPackService::class)
		);
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function registerService(array $case): RegisterService {
		$service = $this->createMock(RegisterService::class);
		$saved = function () use ($case): Register {
			$this->failIfAsked(case: $case);
			return $this->register(
				authorization: self::AUTH_NEW,
				configuration: ($case['after'] ?? ['configuration' => ['roles' => ['r2']]])['configuration'],
				title: array_key_exists('title', $case) === true ? $case['title'] : 'Saved'
			);
		};
		$service->method('createFromArray')->willReturnCallback(function (array $data) use ($saved): Register {
			$this->calls[] = ['createFromArray', $data];
			return $saved();
		});
		$service->method('updateFromArray')->willReturnCallback(function (int $id, array $data) use ($saved): Register {
			$this->calls[] = ['updateFromArray', $id, $data];
			$this->found?->setAuthorization(['manage' => ['overwritten']]);
			$this->found?->setConfiguration(['roles' => ['overwritten']]);
			return $saved();
		});

		return $service;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function failIfAsked(array $case): void {
		switch ($case['throw'] ?? null) {
			case 'block':
				throw new AuthorizationBlockException('rules disagree');
			case 'db':
				throw new DBException('Duplicate entry for key unique');
			case 'constraint':
				throw new DatabaseConstraintException('taken', 0, 418);
			case 'other':
				throw new RuntimeException('save failed');
		}
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function groupManager(array $case): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(function (string $uid) use ($case): bool {
			$this->calls[] = ['isAdmin', $uid];
			return ($case['admin'] ?? false);
		});
		$groups->method('getUserGroupIds')->willReturnCallback(function (IUser $user) use ($case): array {
			$this->calls[] = ['getUserGroupIds', $user->getUID()];
			return ($case['groups'] ?? []);
		});

		return $groups;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function auditService(array $case): AuthorizationAuditService {
		$audit = $this->createMock(AuthorizationAuditService::class);
		foreach (['recordRegisterChange' => 'logRegisterAuthorizationChange', 'recordRoleChange' => 'logRoleDefinitionChange'] as $recorded => $method) {
			$audit->method($recorded)->willReturnCallback(function (AuthorizationChange $change) use ($case, $method): void {
				$this->calls[] = [$method, $change->entityId, $change->entityTitle, $change->before, $change->after];
				if (($case['auditFails'] ?? null) === $method) {
					throw new \Error('audit failed');
				}
			});
		}

		return $audit;
	}

	/**
	 * @param array<string, mixed>|null $authorization
	 * @param array<string, mixed>      $configuration
	 */
	private function register(?array $authorization, array $configuration, ?string $title): Register {
		$register = new Register();
		$register->setId(7);
		$register->setTitle($title);
		$register->setAuthorization($authorization);
		$register->setConfiguration($configuration);

		return $register;
	}

	private function user(?string $uid): ?IUser {
		if ($uid === null) {
			return null;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}
}
