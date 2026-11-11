<?php

/**
 * Golden test pinning the admin guard and outcomes of the configuration
 * CRUD, preview, import, export, enrich and version actions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
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
use OCA\OpenRegister\Kernel\Contracts\Value\GitHubFileLocation;
use OCA\OpenRegister\Kernel\Contracts\Value\GitHubFileWrite;

/**
 * Admin-only actions refuse anyone else before reading or writing; for an
 * admin they answer 404 for a missing configuration and the same bodies as
 * before on success and failure.
 */
class ConfigurationControllerCrudAccessGoldenTest extends TestCase {

	private IRequest&MockObject $request;

	private ConfigurationMapper&MockObject $mapper;

	private ConfigurationService&MockObject $service;

	private NotificationService&MockObject $notifications;

	private GitHubClientInterface&MockObject $github;

	protected function setUp(): void {
		parent::setUp();
		$this->request       = $this->createMock(IRequest::class);
		$this->mapper        = $this->createMock(ConfigurationMapper::class);
		$this->service       = $this->createMock(ConfigurationService::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->github        = $this->createMock(GitHubClientInterface::class);
	}

	private function controller(bool $admin): ConfigurationController {
		$userSession  = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$user         = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('someone');
		$userSession->method('getUser')->willReturn($user);
		$groupManager->method('isAdmin')->willReturn($admin);
		return ConfigurationControllerFactory::create(
			'openregister',
			$this->request,
			$this->mapper,
			$this->service,
			$this->notifications,
			$this->github,
			$this->createMock(GitLabHandler::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$userSession,
			$groupManager
		);
	}

	private function configuration(bool $local = false): Configuration {
		$configuration = new Configuration();
		$configuration->setId(4);
		$configuration->setTitle('C');
		$configuration->setIsLocal($local);
		return $configuration;
	}

	private function call(bool $admin, string $action): \OCP\AppFramework\Http\JSONResponse {
		$controller = $this->controller($admin);
		if (in_array($action, ['create', 'enrichDetails'], true) === true) {
			return $controller->$action();
		}

		return $controller->$action(4);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function adminActions(): array {
		$out = [];
		foreach (['show', 'enrichDetails', 'create', 'update', 'destroy', 'preview', 'import', 'export'] as $a) {
			$out[$a] = [$a];
		}

		return $out;
	}

	#[DataProvider('adminActions')]
	public function testNonAdminIsRefusedBeforeAnyRead(string $action): void {
		$this->request->method('getParams')->willReturn(['owner' => 'o', 'repo' => 'r', 'path' => 'p']);
		$this->mapper->expects($this->never())->method($this->anything());
		$this->service->expects($this->never())->method($this->anything());
		$this->github->expects($this->never())->method($this->anything());

		$response = $this->call(false, $action);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'Admin privileges required'], $response->getData());
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function idActions(): array {
		$out = [];
		foreach (['show', 'update', 'destroy', 'preview', 'import', 'export', 'versionStatus'] as $a) {
			$out[$a] = [$a];
		}

		return $out;
	}

	#[DataProvider('idActions')]
	public function testMissingConfigurationIs404(string $action): void {
		$this->request->method('getParams')->willReturn([]);
		$this->mapper->method('find')->willThrowException(new DoesNotExistException('x'));

		$response = $this->call(true, $action);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Configuration not found'], $response->getData());
	}

	/**
	 * @return array<string, array{0: string, 1: array}>
	 */
	public static function failures(): array {
		$generic = ['error' => 'Internal server error'];
		return [
			'show' => ['show', ['error' => 'Failed to fetch configuration']],
			'update' => ['update', $generic],
			'destroy' => ['destroy', ['error' => 'Failed to delete configuration']],
			'preview' => ['preview', ['error' => 'Failed to preview configuration changes']],
			'import' => ['import', $generic],
			'export' => ['export', $generic],
			'versionStatus' => ['versionStatus', ['error' => 'Failed to check version']],
			'create' => ['create', $generic],
		];
	}

	#[DataProvider('failures')]
	public function testFailureBodies(string $action, array $body): void {
		$this->request->method('getParams')->willReturn([]);
		$this->mapper->method('find')->willThrowException(new Exception('db'));
		$this->mapper->method('insert')->willThrowException(new Exception('db'));

		$response = $this->call(true, $action);

		$this->assertSame(500, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}

	public function testShowDestroyAndExport(): void {
		$configuration = $this->configuration();
		$this->mapper->method('find')->with(4)->willReturn($configuration);
		$this->mapper->expects($this->once())->method('delete')->with($configuration);
		$this->request->method('getParams')->willReturn(['includeObjects' => true]);
		$this->service->expects($this->once())->method('exportConfig')->with($configuration, true)->willReturn(['x' => 1]);

		$this->assertSame($configuration, $this->call(true, 'show')->getData());
		$this->assertSame(['success' => true], $this->call(true, 'destroy')->getData());
		$this->assertSame(['x' => 1], $this->call(true, 'export')->getData());
	}

	public function testExportIncludeObjectsMustBeStrictTrue(): void {
		$this->mapper->method('find')->willReturn($this->configuration());
		$this->request->method('getParams')->willReturn(['includeObjects' => 'true']);
		$this->service->expects($this->once())->method('exportConfig')->with($this->anything(), false)->willReturn([]);
		$this->call(true, 'export');
	}

	public function testCreateDefaultsAndLocalVersion(): void {
		$this->request->method('getParams')->willReturn(['title' => 'T', 'version' => '2.0.0']);
		$this->mapper->expects($this->once())->method('insert')->with($this->callback(
			fn (Configuration $c): bool => $c->getTitle() === 'T' && $c->getDescription() === '' && $c->getType() === 'manual'
				&& $c->getSourceType() === 'local' && $c->getVersion() === '2.0.0' && $c->getLocalVersion() === '2.0.0'
				&& $c->getRegisters() === [] && $c->getAutoUpdate() === false
		))->willReturnCallback(function (Configuration $c): Configuration {
			$c->setId(9);
			return $c;
		});

		$response = $this->call(true, 'create');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(9, $response->getData()['id']);
	}

	public function testUpdateAppliesNonNullFieldsAndSyncsLocalVersion(): void {
		$configuration = $this->configuration(true);
		$configuration->setDescription('keep');
		$this->mapper->method('find')->willReturn($configuration);
		$this->request->method('getParams')->willReturn(['title' => 'N', 'description' => null, 'version' => '3.0.0', 'unknown' => 'x']);
		$this->mapper->expects($this->once())->method('update')->with($this->callback(
			fn (Configuration $c): bool => $c->getTitle() === 'N' && $c->getDescription() === 'keep'
				&& $c->getVersion() === '3.0.0' && $c->getLocalVersion() === '3.0.0'
		))->willReturnArgument(0);

		$this->assertSame($configuration, $this->call(true, 'update')->getData());
	}

	public function testPreviewAndImport(): void {
		$configuration = $this->configuration();
		$this->mapper->method('find')->willReturn($configuration);
		$this->request->method('getParams')->willReturn(['selection' => ['registers' => ['a']]]);
		$this->service->method('previewConfigurationChanges')->willReturn(['p' => 1]);
		$this->service->expects($this->once())->method('importConfigurationWithSelection')
			->with($configuration, ['registers' => ['a']])
			->willReturn(['registers' => [1, 2], 'schemas' => [1], 'objects' => []]);
		$this->notifications->expects($this->once())->method('markConfigurationUpdated')->with($configuration);

		$this->assertSame(['p' => 1], $this->call(true, 'preview')->getData());
		$this->assertSame(
			['success' => true, 'registersCount' => 2, 'schemasCount' => 1, 'objectsCount' => 0],
			$this->call(true, 'import')->getData()
		);
	}

	public function testVersionStatusNeedsNoAdmin(): void {
		$this->mapper->method('find')->willReturn($this->configuration());
		$this->service->method('checkRemoteVersion')->willReturnOnConsecutiveCalls(null, '1.1.0');
		$this->service->method('compareVersions')->willReturn(['hasUpdate' => true]);

		$unknown = $this->call(false, 'versionStatus');
		$known   = $this->call(false, 'versionStatus');

		$this->assertSame(['error' => 'Could not check remote version'], $unknown->getData());
		$this->assertSame(500, $unknown->getStatus());
		$this->assertSame(['hasUpdate' => true], $known->getData());
	}

	public function testEnrichDetails(): void {
		$this->request->method('getParams')->willReturnOnConsecutiveCalls(
			['owner' => 'o', 'repo' => 'r', 'path' => 'p'],
			['owner' => 'o', 'repo' => 'r', 'path' => 'p', 'source' => 'GitLab'],
			['owner' => 'o', 'repo' => 'r'],
			['owner' => 'o', 'repo' => 'r', 'path' => 'p', 'branch' => 'dev']
		);
		$this->github->method('enrichConfigurationDetails')
			->willReturnCallback(fn (GitHubFileLocation $file): ?array => $file->branch === 'dev' ? null : ['b' => $file->branch]);

		$this->assertSame(['b' => 'main'], $this->call(true, 'enrichDetails')->getData());
		$gitlab = $this->call(true, 'enrichDetails');
		$this->assertSame([404, ['error' => 'Failed to fetch configuration details']], [$gitlab->getStatus(), $gitlab->getData()]);
		$missing = $this->call(true, 'enrichDetails');
		$this->assertSame([400, ['error' => 'Missing required parameters: owner, repo, path']], [$missing->getStatus(), $missing->getData()]);
		$this->assertSame(404, $this->call(true, 'enrichDetails')->getStatus());
	}

	public function testPublishRefusedForNonAdmin(): void {
		$this->mapper->expects($this->never())->method($this->anything());
		$this->github->expects($this->never())->method($this->anything());

		$response = $this->controller(false)->publishToGitHub(4);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'Only administrators may publish configurations to GitHub'], $response->getData());
	}

	/**
	 * @return array<string, array{0: string|null, 1: string, 2: string}>
	 */
	public static function publishBranches(): array {
		return [
			'default branch' => ['main', '. Note: GitHub Code Search may take a few minutes to index new files.', 'File published successfully. GitHub Code Search indexing may take a few minutes.'],
			'other branch' => ['trunk', ". Note: Published to branch 'main' (default is 'trunk'). GitHub Code Search primarily indexes the default branch, so this configuration may not appear in search results immediately.", "Published to non-default branch. For discovery, publish to 'trunk' branch."],
			'unknown default' => [null, '. Note: GitHub Code Search may take a few minutes to index new files.', 'File published successfully. GitHub Code Search indexing may take a few minutes.'],
		];
	}

	#[DataProvider('publishBranches')]
	public function testPublishAsAdmin(?string $default, string $suffix, string $note): void {
		$configuration = $this->configuration(true);
		$configuration->setTitle('My Config-One');
		$this->mapper->method('find')->willReturn($configuration);
		$this->request->method('getParams')->willReturn(['owner' => 'o', 'repo' => 'r', 'path' => '/']);
		$this->service->method('exportConfig')->willReturn(['x-openregister' => ['sourceType' => 'local', 'sourceUrl' => 'u', 'keep' => 1]]);
		$this->github->method('getFileSha')->willThrowException(new Exception('none'));
		$this->github->expects($this->once())->method('publishConfiguration')
			->with(new GitHubFileLocation(owner: 'o', repo: 'r', path: 'my_config_one_openregister.json', branch: 'main'), $this->callback(
				fn (GitHubFileWrite $write): bool => json_decode($write->content, true)['x-openregister'] === [
					'keep' => 1,
					'openregister' => '',
					'github' => ['repo' => 'o/r', 'branch' => 'main', 'path' => 'my_config_one_openregister.json'],
				] && $write->commitMessage === 'Update configuration: My Config-One' && $write->fileSha === null
			))
			->willReturn(['commit_sha' => 's', 'commit_url' => 'cu', 'file_url' => 'fu']);
		if ($default === null) {
			$this->github->method('getRepositoryInfo')->willThrowException(new Exception('x'));
		} else {
			$this->github->method('getRepositoryInfo')->willReturn(['default_branch' => $default]);
		}

		$this->mapper->expects($this->once())->method('update')->with($this->callback(
			fn (Configuration $c): bool => $c->getGithubRepo() === 'o/r' && $c->getGithubBranch() === 'main'
				&& $c->getSourceUrl() === 'https://github.com/o/r/blob/main/my_config_one_openregister.json'
		));

		$response = $this->controller(true)->publishToGitHub(4);

		$this->assertSame([
			'success' => true,
			'message' => 'Configuration published successfully to GitHub' . $suffix,
			'configurationId' => 4,
			'commit_sha' => 's',
			'commit_url' => 'cu',
			'file_url' => 'fu',
			'branch' => 'main',
			'default_branch' => $default,
			'indexing_note' => $note,
		], $response->getData());
	}

	public function testPublishRefusals(): void {
		$this->mapper->method('find')->willReturnOnConsecutiveCalls($this->configuration(false), $this->configuration(true));
		$this->request->method('getParams')->willReturn(['owner' => 'o']);
		$this->github->expects($this->never())->method('publishConfiguration');

		$remote  = $this->controller(true)->publishToGitHub(4);
		$noRepo  = $this->controller(true)->publishToGitHub(4);

		$this->assertSame([400, ['error' => 'Only local configurations can be published']], [$remote->getStatus(), $remote->getData()]);
		$this->assertSame([400, ['error' => 'Owner and repo parameters are required']], [$noRepo->getStatus(), $noRepo->getData()]);
	}
}
