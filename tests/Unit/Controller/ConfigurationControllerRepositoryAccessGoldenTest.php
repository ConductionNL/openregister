<?php

/**
 * Golden test pinning the admin guard and outcomes of the repository
 * browsing actions of ConfigurationController.
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
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\NotificationService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ConfigurationControllerFactory;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * discover, getGitHub* and getGitLab* refuse anyone but an admin before
 * touching a handler; for an admin they validate, call the handler and
 * wrap its result.
 */
class ConfigurationControllerRepositoryAccessGoldenTest extends TestCase {

	private const ACTIONS = [
		'discover',
		'getGitHubBranches',
		'getGitHubRepositories',
		'getGitHubConfigurations',
		'getGitLabBranches',
		'getGitLabConfigurations',
	];

	private IRequest&MockObject $request;

	private GitHubClientInterface&MockObject $github;

	private GitLabHandler&MockObject $gitlab;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->github  = $this->createMock(GitHubClientInterface::class);
		$this->gitlab  = $this->createMock(GitLabHandler::class);
	}

	private function controller(?string $user, bool $admin): ConfigurationController {
		$userSession  = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$iUser        = null;
		if ($user !== null) {
			$iUser = $this->createMock(IUser::class);
			$iUser->method('getUID')->willReturn($user);
		}

		$userSession->method('getUser')->willReturn($iUser);
		$groupManager->method('isAdmin')->willReturn($admin);
		return ConfigurationControllerFactory::create(
			'openregister',
			$this->request,
			$this->createMock(ConfigurationMapper::class),
			$this->createMock(ConfigurationService::class),
			$this->createMock(NotificationService::class),
			$this->github,
			$this->gitlab,
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$userSession,
			$groupManager
		);
	}

	private function params(array $params): void {
		$this->request->method('getParams')->willReturn($params);
	}

	/**
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function refusals(): array {
		$cases = [];
		foreach (self::ACTIONS as $action) {
			$cases[$action . ' as non-admin'] = [$action, 'bob'];
			$cases[$action . ' anonymous']    = [$action, null];
		}

		return $cases;
	}

	#[DataProvider('refusals')]
	public function testNonAdminIsRefusedBeforeAnyHandlerCall(string $action, ?string $user): void {
		$this->params(['owner' => 'o', 'repo' => 'r', 'namespace' => 'n', 'project' => 'p']);
		$this->github->expects($this->never())->method($this->anything());
		$this->gitlab->expects($this->never())->method($this->anything());

		$response = $this->controller($user, false)->$action();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'Admin privileges required'], $response->getData());
	}

	public function testDiscoverGithubSearchesBothAndReturnsGithub(): void {
		$this->params(['source' => 'GitHub', '_search' => 'q', 'page' => '2']);
		$this->gitlab->expects($this->once())->method('searchConfigurations')->with('q', 2)->willReturn(['results' => ['gl']]);
		$this->github->expects($this->once())->method('searchConfigurations')->with('q', 2)->willReturn(['results' => ['gh']]);

		$response = $this->controller('admin', true)->discover();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => ['gh']], $response->getData());
	}

	public function testDiscoverGitlabDefaultsAndInvalidSource(): void {
		$this->params(['source' => 'gitlab']);
		$this->gitlab->expects($this->once())->method('searchConfigurations')->with('', 1)->willReturn(['results' => []]);
		$this->github->expects($this->never())->method('searchConfigurations');
		$this->assertSame(['results' => []], $this->controller('admin', true)->discover()->getData());
	}

	public function testDiscoverInvalidSource(): void {
		$this->params(['source' => 'svn']);
		$this->gitlab->expects($this->never())->method('searchConfigurations');
		$response = $this->controller('admin', true)->discover();
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Invalid source. Must be "github" or "gitlab"'], $response->getData());
	}

	public function testDiscoverFailure(): void {
		$this->params([]);
		$this->gitlab->method('searchConfigurations')->willThrowException(new Exception('down'));
		$this->github->method('searchConfigurations')->willThrowException(new Exception('down'));
		$response = $this->controller('admin', true)->discover();
		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Internal server error'], $response->getData());
	}

	public function testGitHubBranches(): void {
		$this->params(['owner' => 'o', 'repo' => 'r']);
		$this->github->expects($this->once())->method('getBranches')->with('o', 'r')->willReturn(['main']);
		$this->assertSame(['branches' => ['main']], $this->controller('admin', true)->getGitHubBranches()->getData());
	}

	public function testGitHubRepositories(): void {
		$this->params(['page' => '3', 'per_page' => '7']);
		$this->github->expects($this->once())->method('getRepositories')->with(3, 7)->willReturn(['x']);
		$this->assertSame(['repositories' => ['x']], $this->controller('admin', true)->getGitHubRepositories()->getData());
	}

	public function testGitHubRepositoriesDefaults(): void {
		$this->params([]);
		$this->github->expects($this->once())->method('getRepositories')->with(1, 100)->willReturn([]);
		$this->assertSame(200, $this->controller('admin', true)->getGitHubRepositories()->getStatus());
	}

	public function testGitHubConfigurations(): void {
		$this->params(['owner' => 'o', 'repo' => 'r']);
		$this->github->expects($this->once())->method('listConfigurationFiles')->with('o', 'r', 'main')->willReturn(['f']);
		$this->assertSame(['files' => ['f']], $this->controller('admin', true)->getGitHubConfigurations()->getData());
	}

	public function testGitLabBranchesAndConfigurations(): void {
		$this->params(['namespace' => 'n', 'project' => 'p', 'ref' => 'dev']);
		$this->gitlab->method('getProjectByPath')->with('n', 'p')->willReturn(['id' => 12]);
		$this->gitlab->expects($this->once())->method('getBranches')->with(12)->willReturn(['b']);
		$this->gitlab->expects($this->once())->method('listConfigurationFiles')->with(12, 'dev')->willReturn(['f']);
		$this->assertSame(['branches' => ['b']], $this->controller('admin', true)->getGitLabBranches()->getData());
		$this->assertSame(['files' => ['f']], $this->controller('admin', true)->getGitLabConfigurations()->getData());
	}

	/**
	 * @return array<string, array{0: string, 1: array, 2: string}>
	 */
	public static function missingParams(): array {
		$gh = 'Owner and repo parameters are required';
		$gl = 'Namespace and project parameters are required';
		return [
			'github branches' => ['getGitHubBranches', ['owner' => 'o'], $gh],
			'github configurations' => ['getGitHubConfigurations', ['repo' => 'r'], $gh],
			'gitlab branches' => ['getGitLabBranches', ['namespace' => 'n'], $gl],
			'gitlab configurations' => ['getGitLabConfigurations', ['project' => 'p'], $gl],
		];
	}

	#[DataProvider('missingParams')]
	public function testMissingParameters(string $action, array $params, string $error): void {
		$this->params($params);
		$response = $this->controller('admin', true)->$action();
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => $error], $response->getData());
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function failingActions(): array {
		return array_combine(array_slice(self::ACTIONS, 1), array_map(fn (string $a): array => [$a], array_slice(self::ACTIONS, 1)));
	}

	#[DataProvider('failingActions')]
	public function testHandlerFailureIsAGeneric500(string $action): void {
		$this->params(['owner' => 'o', 'repo' => 'r', 'namespace' => 'n', 'project' => 'p']);
		$this->github->method($this->anything())->willThrowException(new Exception('down'));
		$this->gitlab->method($this->anything())->willThrowException(new Exception('down'));
		$response = $this->controller('admin', true)->$action();
		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Internal server error'], $response->getData());
	}
}
