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
use OCA\OpenRegister\Service\Configuration\GitLabProjectRef;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\NotificationService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ConfigurationControllerFactory;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Publishing a configuration package to a GitLab repository: an
 * administrator writes the exported configuration to a project, branch and
 * path, and the configuration records that GitLab file as its source.
 *
 * @spec openspec/changes/capability-rows-verified-b/specs/data-import-export/spec.md
 */
class ConfigurationControllerGitLabPublishTest extends TestCase {

	private IRequest&MockObject $request;

	private ConfigurationMapper&MockObject $mapper;

	private ConfigurationService&MockObject $service;

	private GitLabHandler&MockObject $gitlab;

	private IAppManager&MockObject $apps;

	private LoggerInterface&MockObject $logger;

	/**
	 * @var list<string>
	 */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->mapper  = $this->createMock(ConfigurationMapper::class);
		$this->service = $this->createMock(ConfigurationService::class);
		$this->gitlab  = $this->createMock(GitLabHandler::class);
		$this->apps    = $this->createMock(IAppManager::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->gitlab->method('getApiBase')->willReturn('https://gitlab.example.nl/api/v4');
		$this->apps->method('getAppVersion')->willReturn('9.9');
	}

	private function controller(bool $admin = true): ConfigurationController {
		$userSession  = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$user         = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession->method('getUser')->willReturn($user);
		$groupManager->method('isAdmin')->willReturn($admin);

		return ConfigurationControllerFactory::create(
			'openregister',
			$this->request,
			$this->mapper,
			$this->service,
			$this->createMock(NotificationService::class),
			$this->createMock(GitHubClientInterface::class),
			$this->gitlab,
			$this->apps,
			$this->logger,
			$userSession,
			$groupManager
		);
	}

	private function localConfiguration(): Configuration {
		$configuration = new Configuration();
		$configuration->setId(5);
		$configuration->setTitle('afval-meldingen');
		$configuration->setIsLocal(true);
		$this->mapper->method('find')->with(5)->willReturn($configuration);

		return $configuration;
	}

	private function recordPublish(bool $isUpdate): void {
		$this->mapper->method('update')->willReturnCallback(function (Configuration $saved): Configuration {
			$this->calls[] = 'update ' . $saved->getSourceUrl() . ' local=' . var_export($saved->getIsLocal(), true);
			return $saved;
		});
		$this->service->method('exportConfig')->willReturnCallback(function (): array {
			$this->calls[] = 'export';
			return ['openapi' => '3.0.0', 'x-openregister' => ['sourceType' => 'local', 'sourceUrl' => 'x', 'app' => 'afval']];
		});
		$this->gitlab->method('getProjectByPath')->willReturnCallback(function (string $namespace, string $project): array {
			$this->calls[] = "project {$namespace}/{$project}";
			return ['id' => 42];
		});
		$this->gitlab->method('publishFile')->willReturnCallback(
			function (GitLabProjectRef $ref, string $path, string $content, string $message) use ($isUpdate): array {
				$this->calls[] = "publish {$ref->projectId} {$ref->ref} {$path} {$message} " . md5($content);
				return ['success' => true, 'is_update' => $isUpdate, 'file_path' => $path, 'branch' => $ref->ref];
			}
		);
	}

	public function testAnAdministratorPublishesToAGitLabProject(): void {
		$this->localConfiguration();
		$this->recordPublish(isUpdate: false);
		$this->request->method('getParams')->willReturn(
			['namespace' => 'gemeente', 'project' => 'configs', 'branch' => 'main', 'path' => '/configurations/afval-meldingen.json', 'commitMessage' => 'Publish']
		);

		$response = $this->controller()->publishToGitLab(5);

		$content = json_encode(
			['openapi' => '3.0.0', 'x-openregister' => ['app' => 'afval', 'openregister' => '9.9',
				'gitlab' => ['project' => 'gemeente/configs', 'branch' => 'main', 'path' => 'configurations/afval-meldingen.json']]],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		$sourceUrl = 'https://gitlab.example.nl/gemeente/configs/-/blob/main/configurations/afval-meldingen.json';
		$this->assertSame(
			[
				'project gemeente/configs',
				'export',
				'publish 42 main configurations/afval-meldingen.json Publish ' . md5($content),
				"update {$sourceUrl} local=true",
			],
			$this->calls
		);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'success' => true,
				'message' => 'Configuration published successfully to GitLab',
				'configurationId' => 5,
				'branch' => 'main',
				'path' => 'configurations/afval-meldingen.json',
				'file_url' => $sourceUrl,
				'is_update' => false,
			],
			$response->getData()
		);
	}

	public function testASecondPublishReportsAnUpdateOfTheSameFile(): void {
		$this->localConfiguration();
		$this->recordPublish(isUpdate: true);
		$this->request->method('getParams')->willReturn(['namespace' => 'gemeente', 'project' => 'configs', 'path' => 'configurations/afval-meldingen.json']);

		$data = $this->controller()->publishToGitLab(5)->getData();

		$this->assertTrue($data['is_update']);
		$this->assertSame('configurations/afval-meldingen.json', $data['path']);
	}

	public function testDefaultsAreBranchMainATitlePathAndAnUpdateMessage(): void {
		$this->localConfiguration();
		$this->recordPublish(isUpdate: false);
		$this->request->method('getParams')->willReturn(['namespace' => 'gemeente', 'project' => 'configs']);

		$this->controller()->publishToGitLab(5);

		$this->assertStringStartsWith('publish 42 main afval_meldingen_openregister.json Update configuration: afval-meldingen ', $this->calls[2]);
	}

	public function testANonAdministratorIsRefusedBeforeAnythingIsRead(): void {
		$this->mapper->expects($this->never())->method('find');
		$this->gitlab->expects($this->never())->method('publishFile');

		$response = $this->controller(false)->publishToGitLab(5);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'Only administrators may publish configurations to GitLab'], $response->getData());
	}

	public function testOnlyALocalConfigurationIsPublished(): void {
		$configuration = new Configuration();
		$configuration->setIsLocal(false);
		$this->mapper->method('find')->willReturn($configuration);
		$this->gitlab->expects($this->never())->method('publishFile');

		$response = $this->controller()->publishToGitLab(5);

		$this->assertSame([400, ['error' => 'Only local configurations can be published']], [$response->getStatus(), $response->getData()]);
	}

	public function testNamespaceAndProjectAreRequired(): void {
		$this->localConfiguration();
		$this->request->method('getParams')->willReturn(['namespace' => 'gemeente']);
		$this->gitlab->expects($this->never())->method('getProjectByPath');
		$this->mapper->expects($this->never())->method('update');

		$response = $this->controller()->publishToGitLab(5);

		$this->assertSame([400, ['error' => 'Namespace and project parameters are required']], [$response->getStatus(), $response->getData()]);
	}

	public function testAFailedPublishIsLoggedAndAnsweredGenerically(): void {
		$this->localConfiguration();
		$this->request->method('getParams')->willReturn(['namespace' => 'gemeente', 'project' => 'configs']);
		$this->service->method('exportConfig')->willReturn([]);
		$this->gitlab->method('getProjectByPath')->willReturn(['id' => 42]);
		$this->gitlab->method('publishFile')->willThrowException(new Exception('Failed to publish file: HTTP 403'));
		$this->mapper->expects($this->never())->method('update');
		$this->logger->expects($this->once())->method('error')
			->with('[ConfigurationController] Failed to publish to GitLab: Failed to publish file: HTTP 403');

		$response = $this->controller()->publishToGitLab(5);

		$this->assertSame([500, ['error' => 'Internal server error']], [$response->getStatus(), $response->getData()]);
	}
}
