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
use OCA\OpenRegister\Db\MagicMapper\StatisticsScope;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
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
 * Golden test for RegistersController::show().
 *
 * Every case records each collaborator call in order and the response, and
 * compares the recording with fixtures/registers-show-golden.json. Set
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
 * @uses \OCA\OpenRegister\Service\Rbac\ReadRuleTenancy
 * @uses \OCA\OpenRegister\Db\Register
 */
class RegistersControllerShowGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/registers-show-golden.json';

	private const KEPT_FIELDS = ['id', 'title', 'stats'];

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$stats = ['extend' => ['@self.stats']];
		$public = ['read' => ['public']];
		$cases = [
			'signed in' => [],
			'signed in, private register' => ['auth' => ['read' => ['staff']]],
			'admin' => ['admin' => true],
			'extend as text' => ['extend' => 'schemas'],
			'stats as text' => ['extend' => '@self.stats'],
			'stats for an admin' => $stats + ['admin' => true],
			'stats for a member' => $stats,
			'stats among others' => ['extend' => ['schemas', '@self.stats'], 'admin' => true],
			'anonymous, public register' => ['user' => null, 'auth' => $public],
			'anonymous, public group entry' => ['user' => null, 'auth' => ['read' => [['group' => 'public']]]],
			'anonymous, conditional public entry' => ['user' => null, 'auth' => ['read' => [['group' => 'public', 'match' => ['a' => 1]]]]],
			'anonymous, private register' => ['user' => null, 'auth' => ['read' => ['staff']]],
			'anonymous, no authorization' => ['user' => null, 'auth' => null],
			'anonymous, stats on a public register' => $stats + ['user' => null, 'auth' => $public],
			'anonymous, stats on a private register' => $stats + ['user' => null, 'auth' => ['read' => ['staff']]],
			'unknown register' => ['find' => 'missing'],
			'find fails' => ['find' => 'fails'],
			'statistics fail' => $stats + ['statsFail' => true],
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
	public function testShowMatchesGolden(array $case): void {
		$this->calls = [];
		$response = $this->buildController(case: $case)->show('seven');
		$data = array_intersect_key((array)$response->getData(), array_flip([...self::KEPT_FIELDS, 'error']));
		$recording = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $data];

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

		$this->assertNotSame([], $recording['data']);
		$this->assertSame($golden[$name] ?? null, $recording);
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function buildController(array $case): RegistersController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(function (string $key, $default = null) use ($case) {
			$this->calls[] = ['getParam', $key, $default];
			return ($case['extend'] ?? $default);
		});

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user(uid: array_key_exists('user', $case) === true ? $case['user'] : 'member'));

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(function (string $uid) use ($case): bool {
			$this->calls[] = ['isAdmin', $uid];
			return ($case['admin'] ?? false);
		});

		$objects = $this->createMock(MagicMapper::class);
		$objects->method('getStatistics')->willReturnCallback(function (StatisticsScope $scope) use ($case): array {
			$args = [$scope->registerId, $scope->schemaId, $scope->exclude];
			$this->calls[] = ['objectStatistics', array_slice($args, 0, 2)];
			if (($case['statsFail'] ?? false) === true) {
				throw new RuntimeException('count failed');
			}

			return ['total' => 3];
		});

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('getStatistics')->willReturnCallback(function (...$args): array {
			$this->calls[] = ['auditStatistics', array_slice($args, 0, 2)];
			return ['total' => 8, 'size' => 80];
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context): void {
			unset($context['file'], $context['line']);
			$this->calls[] = ['error', $message, $context];
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
			$audit,
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$schemaMapper,
			$this->createMock(RegisterMapper::class),
			$this->createMock(GitHubHandler::class),
			$this->createMock(IAppManager::class),
			$this->createMock(OasService::class),
			$this->createMock(ContainerInterface::class),
			$groups,
			$this->createMock(RegisterCacheHandler::class),
			new RegisterSerializer($schemaMapper, $logger),
			$this->createMock(MigrationPackService::class)
		);
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function registerService(array $case): RegisterService {
		$service = $this->createMock(RegisterService::class);
		$service->method('find')->willReturnCallback(function ($id, $_extend, $_multitenancy) use ($case): Register {
			$this->calls[] = ['find', $id, $_extend, $_multitenancy];
			if (($case['find'] ?? null) === 'missing') {
				throw new DoesNotExistException('gone');
			}

			if (($case['find'] ?? null) === 'fails') {
				throw new RuntimeException('find failed');
			}

			$register = new Register();
			$register->setId(7);
			$register->setTitle('Seven');
			$register->setAuthorization(array_key_exists('auth', $case) === true ? $case['auth'] : ['read' => ['public']]);
			return $register;
		});

		return $service;
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
