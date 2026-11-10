<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\ConfigurationController;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\NotificationService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ConfigurationControllerFactory;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins what the shared helpers of ConfigurationController produce: the
 * failure log and body of an action on one configuration, the failure of an
 * admin repository call, the refusal of an incomplete source, the success
 * body of an import and the calls and body of a publish to GitHub.
 */
class ConfigurationControllerHelperChainGoldenTest extends TestCase {

	private IRequest&MockObject $request;

	private ConfigurationMapper&MockObject $mapper;

	private ConfigurationService&MockObject $service;

	private GitHubClientInterface&MockObject $github;

	private IAppManager&MockObject $apps;

	/**
	 * @var list<array{0: string, 1: string}>
	 */
	private array $logs = [];

	/**
	 * @var list<string>
	 */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->mapper  = $this->createMock(ConfigurationMapper::class);
		$this->service = $this->createMock(ConfigurationService::class);
		$this->github  = $this->createMock(GitHubClientInterface::class);
		$this->apps    = $this->createMock(IAppManager::class);
	}

	private function controller(): ConfigurationController {
		$userSession  = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$user         = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession->method('getUser')->willReturn($user);
		$groupManager->method('isAdmin')->willReturn(true);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message) use ($level): void {
				$this->logs[] = [$level, $message];
			});
		}

		return ConfigurationControllerFactory::create(
			'openregister',
			$this->request,
			$this->mapper,
			$this->service,
			$this->createMock(NotificationService::class),
			$this->github,
			$this->createMock(GitLabHandler::class),
			$this->apps,
			$logger,
			$userSession,
			$groupManager
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string|null}>
	 */
	public static function failingActions(): array {
		return [
			'show' => ['show', 'fetch', 'Failed to fetch configuration', null],
			'update' => ['update', 'update', 'Internal server error', 'Failed to update configuration'],
			'destroy' => ['destroy', 'delete', 'Failed to delete configuration', null],
			'versionStatus' => ['versionStatus', 'check version for', 'Failed to check version', null],
			'preview' => ['preview', 'preview', 'Failed to preview configuration changes', null],
			'import' => ['import', 'import', 'Internal server error', 'Failed to import configuration'],
			'export' => ['export', 'export', 'Internal server error', 'Failed to export configuration'],
		];
	}

	#[DataProvider('failingActions')]
	public function testAFailingActionLogsItsVerbAndAnswersItsMessage(string $action, string $verb, string $message, ?string $context): void {
		$this->request->method('getParams')->willReturn([]);
		$this->mapper->method('find')->willThrowException(new Exception('boom'));

		$response = $this->controller()->$action(7);

		$expected = [['error', "[ConfigurationController] Failed to {$verb} configuration 7: boom"]];
		if ($context !== null) {
			$expected[] = ['error', "[OCA\\OpenRegister\\Controller\\ConfigurationController] {$context}: boom"];
		}

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => $message], $response->getData());
		$this->assertSame($expected, $this->logs);
	}

	#[DataProvider('failingActions')]
	public function testAMissingConfigurationIsNotFound(string $action): void {
		$this->request->method('getParams')->willReturn([]);
		$this->mapper->method('find')->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller()->$action(7);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Configuration not found'], $response->getData());
		$this->assertSame([], $this->logs);
	}

	public function testAFailingRepositoryCallLogsAndAnswersItsContext(): void {
		$this->request->method('getParams')->willReturn(['owner' => 'o', 'repo' => 'r']);
		$this->github->method('getBranches')->willThrowException(new Exception('down'));

		$response = $this->controller()->getGitHubBranches();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Internal server error'], $response->getData());
		$this->assertSame(
			[['error', '[ConfigurationController] Failed to get GitHub branches: down'], ['error', '[OCA\\OpenRegister\\Controller\\ConfigurationController] Failed to fetch branches: down']],
			array_values(array_filter($this->logs, static fn (array $log): bool => $log[0] === 'error'))
		);
	}

	public function testAnIncompleteGitHubSourceIsRefused(): void {
		$this->request->method('getParams')->willReturn(['owner' => 'o', 'repo' => 'r']);

		$response = $this->controller()->importFromGitHub();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Failed to import configuration: Owner, repo, and path parameters are required'], $response->getData());
	}

	public function testAFinishedImportAnswersItsSourceAndCounts(): void {
		$this->request->method('getParams')->willReturn(['owner' => 'o', 'repo' => 'r', 'path' => 'p.json']);
		$this->github->method('getFileContent')->willReturn(['x-openregister' => ['app' => 'demo']]);
		$this->mapper->method('findByApp')->willReturn([]);
		$this->mapper->method('insert')->willReturnCallback(static function (Configuration $configuration): Configuration {
			$configuration->setId(12);
			return $configuration;
		});
		$this->service->method('importFromJson')->willReturn(['registers' => [1], 'schemas' => [1, 2], 'objects' => []]);

		$response = $this->controller()->importFromGitHub();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(
			[
				'success' => true,
				'message' => 'Configuration imported successfully from github',
				'configurationId' => 12,
				'result' => ['registersCount' => 1, 'schemasCount' => 2, 'objectsCount' => 0],
			],
			$response->getData()
		);
	}

	public function testAPublishSendsTheLocationAndAnswersTheCommit(): void {
		$configuration = new Configuration();
		$configuration->setId(3);
		$configuration->setTitle('Demo');
		$configuration->setIsLocal(true);
		$this->mapper->method('find')->willReturn($configuration);
		$this->mapper->method('update')->willReturnCallback(function (Configuration $saved): Configuration {
			$this->calls[] = 'update ' . $saved->getGithubRepo() . ' ' . $saved->getGithubBranch() . ' ' . $saved->getGithubPath() . ' ' . $saved->getSourceUrl();
			return $saved;
		});
		$this->request->method('getParams')->willReturn(['owner' => 'o', 'repo' => 'r', 'path' => '/dir/c.json', 'branch' => 'dev', 'commitMessage' => 'm']);
		$this->service->method('exportConfig')->willReturnCallback(function (): array {
			$this->calls[] = 'export';
			return ['x-openregister' => ['sourceType' => 'local']];
		});
		$this->apps->method('getAppVersion')->willReturn('9.9');
		$this->github->method('getFileSha')->willReturnCallback(function (string $owner, string $repo, string $path, string $branch): string {
			$this->calls[] = "sha {$owner} {$repo} {$path} {$branch}";
			return 'old';
		});
		$this->github->method('publishConfiguration')->willReturnCallback(
			function (string $owner, string $repo, string $path, string $branch, string $content, string $commitMessage, ?string $fileSha): array {
				$this->calls[] = "publish {$owner} {$repo} {$path} {$branch} {$commitMessage} {$fileSha} " . md5($content);
				return ['commit_sha' => 'c1', 'commit_url' => 'cu', 'file_url' => 'fu'];
			}
		);
		$this->github->method('getRepositoryInfo')->willReturn(['default_branch' => 'main']);

		$response = $this->controller()->publishToGitHub(3);

		$content = json_encode(
			['x-openregister' => ['openregister' => '9.9', 'github' => ['repo' => 'o/r', 'branch' => 'dev', 'path' => 'dir/c.json']]],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		$this->assertSame(
			[
				'export',
				'sha o r dir/c.json dev',
				'publish o r dir/c.json dev m old ' . md5($content),
				'update o/r dev dir/c.json https://github.com/o/r/blob/dev/dir/c.json',
			],
			$this->calls
		);
		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(
			['success' => true, 'configurationId' => 3, 'commit_sha' => 'c1', 'commit_url' => 'cu', 'file_url' => 'fu', 'branch' => 'dev', 'default_branch' => 'main'],
			array_diff_key($data, ['message' => true, 'indexing_note' => true])
		);
		$this->assertContains(['debug', '[ConfigurationController] Successfully published configuration Demo to GitHub'], $this->logs);
	}
}
