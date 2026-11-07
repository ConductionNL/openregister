<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\Registers\RegisterCacheHandler;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCA\OpenRegister\Files\Service\UploadService;
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

/**
 * Golden test for RegistersController::publishToGitHub().
 *
 * Records the response and every GitHub call per case and compares them with
 * fixtures/registers-github-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class RegistersControllerGitHubGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/registers-github-golden.json';

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	/**
	 * Cases: request parameters and how GitHub behaves.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$repo = ['owner' => 'o', 'repo' => 'r'];
		return [
			'default target' => [['params' => $repo]],
			'custom target' => [['params' => $repo + ['path' => '/a/b.json', 'branch' => 'dev', 'commitMessage' => 'm']]],
			'no slug' => [['params' => $repo, 'slug' => null]],
			'no owner' => [['params' => ['repo' => 'r']]],
			'no repo' => [['params' => ['owner' => 'o']]],
			'non default branch' => [['params' => $repo + ['branch' => 'dev']]],
			'default branch unreported' => [['params' => $repo + ['branch' => 'dev'], 'info' => []]],
			'default branch empty' => [['params' => $repo + ['branch' => 'dev'], 'info' => ['default_branch' => '']]],
			'repository info fails' => [['params' => $repo + ['branch' => 'dev'], 'infoFails' => true]],
			'file exists' => [['params' => $repo, 'sha' => 'abc']],
			'sha lookup fails' => [['params' => $repo, 'shaFails' => true]],
			'publish fails' => [['params' => $repo, 'publishFails' => true]],
			'register missing' => [['params' => $repo, 'missing' => true]],
			'not an admin' => [['params' => $repo, 'admin' => false]],
		];
	}//end cases()

	/**
	 * A publish answers as recorded.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testPublishMatchesTheRecording(array $case): void {
		$this->calls = [];
		$response    = RegistersControllerFactory::create(...$this->collaborators(case: $case))->publishToGitHub(1);
		$actual      = ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $this->calls];

		$all = is_file(self::FIXTURE) === true ? json_decode((string) file_get_contents(self::FIXTURE), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$this->dataName()] = $actual;
			ksort($all);
			file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame($all[$this->dataName()], json_decode((string) json_encode($actual), true));
	}//end testPublishMatchesTheRecording()

	/**
	 * The arguments for RegistersControllerFactory::create().
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int, mixed>
	 */
	private function collaborators(array $case): array {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createConfiguredMock(IUser::class, ['getUID' => 'alice']));
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($case['admin'] ?? true);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($case['params']);
		$oas = $this->createMock(OasService::class);
		$oas->method('createOas')->willReturn(['openapi' => '3.0.0', 'url' => 'a/b']);
		return [
			'openregister',
			$request,
			$this->createMock(RegisterService::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(UploadService::class),
			$this->createMock(LoggerInterface::class),
			$session,
			$this->createMock(ConfigurationService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ObjectExporterInterface::class),
			$this->createMock(ObjectImporterInterface::class),
			$this->createMock(SchemaMapper::class),
			$this->registers(case: $case),
			$this->gitHub(case: $case),
			$this->createMock(IAppManager::class),
			$oas,
			$this->createMock(ContainerInterface::class),
			$groups,
			$this->createMock(RegisterCacheHandler::class),
			new RegisterSerializer($this->createMock(SchemaMapper::class), $this->createMock(LoggerInterface::class)),
			$this->createMock(MigrationPackService::class),
		];
	}//end collaborators()

	/**
	 * The register mapper.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return RegisterMapper
	 */
	private function registers(array $case): RegisterMapper {
		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->method('find')->willReturnCallback(
			function () use ($case): Register {
				if (($case['missing'] ?? false) === true) {
					throw new DoesNotExistException('no register');
				}

				$register = new Register();
				$register->setId(1);
				$register->setTitle('Reg');
				$register->setSlug(array_key_exists('slug', $case) === true ? $case['slug'] : 'reg');
				return $register;
			}
		);
		return $mapper;
	}//end registers()

	/**
	 * The GitHub client, noting every call.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return GitHubClientInterface
	 */
	private function gitHub(array $case): GitHubClientInterface {
		$client = $this->createMock(GitHubClientInterface::class);
		$client->method('getFileSha')->willReturnCallback(
			function (...$args) use ($case): ?string {
				$this->calls[] = ['getFileSha', ...array_values($args)];
				if (($case['shaFails'] ?? false) === true) {
					throw new Exception('no file');
				}

				return $case['sha'] ?? null;
			}
		);
		$client->method('getRepositoryInfo')->willReturnCallback(
			function (...$args) use ($case): array {
				$this->calls[] = ['getRepositoryInfo', ...array_values($args)];
				if (($case['infoFails'] ?? false) === true) {
					throw new Exception('no info');
				}

				return $case['info'] ?? ['default_branch' => 'main'];
			}
		);
		$client->method('publishConfiguration')->willReturnCallback(
			function (...$args) use ($case): array {
				$this->calls[] = ['publishConfiguration', ...array_values($args)];
				if (($case['publishFails'] ?? false) === true) {
					throw new Exception('refused');
				}

				return ['commit_sha' => 'c1', 'commit_url' => 'u1', 'file_url' => 'f1'];
			}
		);
		return $client;
	}//end gitHub()
}//end class
