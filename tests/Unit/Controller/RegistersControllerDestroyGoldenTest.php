<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\RegistersController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Files\Service\UploadService;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\Registers\RegisterCacheHandler;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCA\OpenRegister\Tests\Unit\Controller\Support\RegistersControllerFactory;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for RegistersController::destroy().
 *
 * Every case records each collaborator call in order and the response, and
 * compares the recording with fixtures/registers-destroy-golden.json. Set
 * UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\RegistersController
 * @uses \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses \OCA\OpenRegister\Controller\Support\RegisterDownloads
 * @uses \OCA\OpenRegister\Controller\Support\RegisterGitHubPublisher
 * @uses \OCA\OpenRegister\Controller\Support\RegisterStatistics
 * @uses \OCA\OpenRegister\Controller\Support\RegistersControllerServices
 * @uses \OCA\OpenRegister\Controller\Support\RegisterMappers
 * @uses \OCA\OpenRegister\Controller\Support\RegisterServices
 * @uses \OCA\OpenRegister\Controller\Support\RegisterIntegrations
 * @uses \OCA\OpenRegister\Controller\Support\ObjectTransfer
 * @uses \OCA\OpenRegister\Service\Serializer\RegisterSerializer
 * @uses \OCA\OpenRegister\Service\Rbac\DefinitionWriteRights
 * @uses \OCA\OpenRegister\Db\Register
 */
class RegistersControllerDestroyGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/registers-destroy-golden.json';

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$root = ['user' => 'root', 'admin' => true];
		$cases = [
			'unknown register' => ['find' => 'missing'] + $root,
			'find fails' => ['find' => 'fails'] + $root,
			'anonymous' => ['user' => null],
			'somebody else' => ['user' => 'other'],
			'somebody else with force' => ['user' => 'other', 'force' => 'true', 'objects' => 3],
			'rights fail' => ['rightsFail' => true] + $root,
			'manager, empty register' => ['user' => 'member', 'groups' => ['editors']],
			'admin, empty register' => $root,
			'objects without force' => ['objects' => 3] + $root,
			'objects with a manager' => ['objects' => 3, 'user' => 'member', 'groups' => ['editors']],
			'force as text' => ['objects' => 3, 'force' => 'true'] + $root,
			'force as a boolean' => ['objects' => 3, 'force' => true] + $root,
			'force as one' => ['objects' => 3, 'force' => '1'] + $root,
			'force as the number one' => ['objects' => 3, 'force' => 1] + $root,
			'force false as text' => ['objects' => 3, 'force' => 'false'] + $root,
			'force yes is no force' => ['objects' => 3, 'force' => 'yes'] + $root,
			'force on an empty register' => ['force' => 'true'] + $root,
			'count fails' => ['countFails' => true] + $root,
			'count as text' => ['objects' => '4'] + $root,
			'delete still has objects' => ['delete' => 'validation'] + $root,
			'delete fails' => ['delete' => 'fails'] + $root,
			'cache fails' => ['cacheFails' => true] + $root,
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
	public function testDestroyMatchesGolden(array $case): void {
		$this->calls = [];
		$response = $this->buildController(case: $case)->destroy(7);
		$recording = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()];

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

		$this->assertNotSame([], $recording['calls']);
		$this->assertSame($golden[$name] ?? null, $recording);
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function buildController(array $case): RegistersController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(function (string $key, $default = null) use ($case) {
			$this->calls[] = ['getParam', $key, $default];
			return ($case['force'] ?? $default);
		});

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user(uid: array_key_exists('user', $case) === true ? $case['user'] : 'other'));

		$groups = $this->groupManager(case: $case);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($case, $groups) {
			$this->calls[] = ['container', $id];
			if (($case['rightsFail'] ?? false) === true) {
				throw new RuntimeException('no group manager');
			}

			return $groups;
		});

		$objects = $this->createMock(MagicMapper::class);
		$objects->method('getStatistics')->willReturnCallback(function (...$args) use ($case): array {
			$this->calls[] = ['getStatistics', array_slice($args, 0, 2)];
			if (($case['countFails'] ?? false) === true) {
				throw new RuntimeException('count failed');
			}

			return ['total' => ($case['objects'] ?? 0)];
		});

		$cache = $this->createMock(RegisterCacheHandler::class);
		$cache->method('invalidate')->willReturnCallback(function (int $registerId) use ($case): void {
			$this->calls[] = ['invalidate', $registerId];
			if (($case['cacheFails'] ?? false) === true) {
				throw new RuntimeException('cache failed');
			}
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context): void {
			unset($context['file'], $context['line']);
			$this->calls[] = ['warning', $message, $context];
		});

		$schemaMapper = $this->createMock(SchemaMapper::class);

		return RegistersControllerFactory::create(
			'openregister',
			$request,
			$this->registerService(case: $case),
			$objects,
			$this->createMock(UploadService::class),
			$logger,
			$session,
			$this->createMock(ConfigurationService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$schemaMapper,
			$this->createMock(RegisterMapper::class),
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
		$service->method('find')->willReturnCallback(function ($id) use ($case): Register {
			$this->calls[] = ['find', $id];
			if (($case['find'] ?? null) === 'missing') {
				throw new DoesNotExistException('gone');
			}

			if (($case['find'] ?? null) === 'fails') {
				throw new RuntimeException('find failed');
			}

			$register = new Register();
			$register->setId(7);
			$register->setSlug('seven');
			$register->setAuthorization(['manage' => ['editors']]);
			return $register;
		});
		$service->method('delete')->willReturnCallback(function (Register $register) use ($case): Register {
			$this->calls[] = ['delete', $register->getId()];
			if (($case['delete'] ?? null) === 'validation') {
				throw new ValidationException('objects attached');
			}

			if (($case['delete'] ?? null) === 'fails') {
				throw new RuntimeException('delete failed');
			}

			return $register;
		});

		return $service;
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

	private function user(?string $uid): ?IUser {
		if ($uid === null) {
			return null;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}
}
