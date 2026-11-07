<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Controller;

use OCA\OpenRegister\Controller\RegistersController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Service\UploadService;
use OCA\OpenRegister\Service\Configuration\AppImportJobRecorder;
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
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * Golden test for RegistersController::rollbackImport().
 *
 * Every case records each collaborator call in order and the response, and
 * compares the recording with fixtures/registers-rollback-golden.json. Set
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
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Rbac\ReadRuleTenancy
 */
class RegistersControllerRollbackGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/registers-rollback-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$cases = [
			'no job id' => ['job' => null],
			'empty job id' => ['job' => ''],
			'job id not a string' => ['job' => ['j1']],
			'anonymous' => ['user' => null],
			'anonymous on an app job' => ['user' => null, 'app' => 'dossiq'],
			'app job' => ['app' => 'dossiq'],
			'app job of the importer' => ['app' => 'dossiq', 'user' => 'importer', 'importer' => 'importer'],
			'unknown job' => ['importer' => false],
			'unknown job as admin' => ['importer' => false, 'admin' => true],
			'somebody else' => ['user' => 'other'],
			'admin' => ['user' => 'root', 'admin' => true],
			'importer' => ['user' => 'importer'],
			'audit row without a user' => ['user' => 'other', 'importer' => 'no-method'],
			'audit row without a user as admin' => ['user' => 'root', 'admin' => true, 'importer' => 'no-method'],
			'first row decides' => ['user' => 'second', 'rows' => ['first', 'second']],
			'delete fails' => ['user' => 'importer', 'fails' => true],
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
	public function testRollbackMatchesGolden(array $case): void {
		$this->calls = [];
		$response = $this->buildController(case: $case)->rollbackImport();
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

		$this->assertNotSame([], $recording['data']);
		$this->assertSame($golden[$name] ?? null, $recording);
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function buildController(array $case): RegistersController {
		$request = $this->createMock(IRequest::class);
		$job = array_key_exists('job', $case) === true ? $case['job'] : 'j1';
		$request->method('getParam')->willReturnCallback(function (string $key) use ($job) {
			$this->calls[] = ['getParam', $key];
			return $job;
		});

		$uid = array_key_exists('user', $case) === true ? $case['user'] : 'other';
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user(uid: $uid));

		$recorder = $this->createMock(AppImportJobRecorder::class);
		$recorder->method('appForJob')->willReturnCallback(function (string $importJobId) use ($case): ?string {
			$this->calls[] = ['appForJob', $importJobId];
			return $case['app'] ?? null;
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($recorder) {
			$this->calls[] = ['container', $id];
			return $recorder;
		});

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('findByImportJobId')->willReturnCallback(function (string $importJobId, ?string $action) use ($case): array {
			$this->calls[] = ['findByImportJobId', $importJobId, $action];
			return $this->auditRows(case: $case);
		});

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(function (string $user) use ($case): bool {
			$this->calls[] = ['isAdmin', $user];
			return ($case['admin'] ?? false);
		});

		$import = $this->createMock(ImportService::class);
		$import->method('softDeleteByImportJobId')->willReturnCallback(function (string $importJobId) use ($case): array {
			$this->calls[] = ['softDeleteByImportJobId', $importJobId];
			if (($case['fails'] ?? false) === true) {
				throw new RuntimeException('delete failed');
			}

			return ['deleted' => 2];
		});

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		return RegistersControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(RegisterService::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(UploadService::class),
			$logger,
			$session,
			$this->createMock(ConfigurationService::class),
			$audit,
			$this->createMock(ExportService::class),
			$import,
			$schemaMapper,
			$this->createMock(RegisterMapper::class),
			$this->createMock(GitHubHandler::class),
			$this->createMock(IAppManager::class),
			$this->createMock(OasService::class),
			$container,
			$groups,
			$this->createMock(RegisterCacheHandler::class),
			new RegisterSerializer($schemaMapper, $logger),
			$this->createMock(MigrationPackService::class)
		);
	}

	private function user(?string $uid): ?IUser {
		if ($uid === null) {
			return null;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	/**
	 * @param array<string, mixed> $case
	 *
	 * @return list<object>
	 */
	private function auditRows(array $case): array {
		$importer = ($case['importer'] ?? 'importer');
		if ($importer === false) {
			return [];
		}

		if ($importer === 'no-method') {
			return [new stdClass()];
		}

		$rows = [];
		foreach (($case['rows'] ?? [$importer]) as $uid) {
			$rows[] = new class($uid) {
				public function __construct(private readonly string $uid) {
				}

				public function getUser(): string {
					return $this->uid;
				}
			};
		}

		return $rows;
	}
}
