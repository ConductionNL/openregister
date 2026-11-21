<?php

/**
 * Golden for the guards of the GitHub issue proxy's read path.
 *
 * Written against the controller before its read guard pipeline took the
 * listing object: the repository allowlist is asked about the caller's own
 * repository on the read path, and a refused repository, page size, sort key
 * or label filter is answered before GitHub is asked.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/features-roadmap-menu/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\GitHubIssuesController;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Service\Configuration\GitHubGuards;
use OCA\OpenRegister\Service\Configuration\GitHubRequestValidator;
use OCA\OpenRegister\Service\Configuration\RateLimiterService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\GitHubIssuesControllerFactory;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The read path's allowlist and validators, allowed and refused.
 */
class GitHubIssuesReadGuardsGoldenTest extends TestCase {

	private GitHubClientInterface&MockObject $github;

	/**
	 * The repositories and read flags the allowlist was asked about.
	 *
	 * @var array<int, array{0: string, 1: bool}>
	 */
	private array $asked = [];

	/**
	 * Run index() over the given request parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return JSONResponse
	 */
	private function index(array $params): JSONResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);
		$guards = $this->createMock(GitHubGuards::class);
		$guards->method('runGuards')->willReturnCallback(
			static function (array $pipeline): ?JSONResponse {
				foreach ($pipeline as $guard) {
					$refusal = $guard();
					if ($refusal !== null) {
						return $refusal;
					}
				}

				return null;
			}
		);
		$guards->method('enforceRepoAllowlist')->willReturnCallback(
			function (string $repo, bool $isRead): ?JSONResponse {
				$this->asked[] = [$repo, $isRead];
				if ($repo === 'ConductionNL/openregister' && $isRead === true) {
					return null;
				}

				return new JSONResponse(['error' => 'repo_not_allowed'], 403);
			}
		);
		$cache = $this->createMock(ICache::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$controller = GitHubIssuesControllerFactory::create(
			appName: 'openregister',
			request: $request,
			githubHandler: $this->github,
			guards: $guards,
			validator: new GitHubRequestValidator(),
			rateLimiter: $this->createMock(RateLimiterService::class),
			userSession: $this->createMock(IUserSession::class),
			cacheFactory: $cacheFactory,
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->createMock(IGroupManager::class)
		);
		$this->assertInstanceOf(GitHubIssuesController::class, $controller);

		return $controller->index();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->github = $this->createMock(GitHubClientInterface::class);
	}

	public function testAnAllowedRepositoryIsReadAndTheAllowlistIsAskedAboutItOnTheReadPath(): void {
		$this->github->expects($this->once())->method('listIssues')->willReturn(['items' => []]);

		$response = $this->index(['repo' => 'ConductionNL/openregister', 'per_page' => '10', 'sort' => 'created', 'labels' => 'a']);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([['ConductionNL/openregister', true]], $this->asked);
	}

	public function testARepositoryOffTheAllowlistIsRefusedBeforeGitHubIsAsked(): void {
		$this->github->expects($this->never())->method('listIssues');

		$response = $this->index(['repo' => 'evil/repo']);

		$this->assertSame([403, ['error' => 'repo_not_allowed']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([['evil/repo', true]], $this->asked);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: int}>
	 */
	public static function refusedReads(): array {
		return [
			'malformed repo' => [['repo' => 'no-slash'], 400],
			'page size above 100' => [['repo' => 'ConductionNL/openregister', 'per_page' => '101'], 400],
			'page size of 0' => [['repo' => 'ConductionNL/openregister', 'per_page' => '0'], 400],
			'unknown sort' => [['repo' => 'ConductionNL/openregister', 'sort' => 'stars'], 400],
		];
	}

	/**
	 * @dataProvider refusedReads
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param int                  $status The refusal status.
	 */
	public function testARefusedReadNeverReachesGitHub(array $params, int $status): void {
		$this->github->expects($this->never())->method('listIssues');

		$this->assertSame($status, $this->index($params)->getStatus());
	}
}
