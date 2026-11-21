<?php

/**
 * Characterisation of the GitHub issue proxy's read and submit paths.
 *
 * Written against the controller before index(), create() and the rate-limit
 * mapping were split into small units: the label parsing and the cache key it
 * feeds, a cache hit answered without GitHub, the miss path with its TTL and
 * header, the cache-miss rate limit, the submit path's specRef normalisation
 * and the slot it marks, and how a missing PAT and GitHub's own rate limit are
 * answered.
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

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
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
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\GitHubIssueSelection;
use OCA\OpenRegister\Kernel\Contracts\Value\GitHubIssueSubmission;
use OCA\OpenRegister\Kernel\Contracts\Value\GitHubIssueText;

/**
 * Characterisation of GitHubIssuesController.
 */
class GitHubIssuesControllerCharacterisationTest extends TestCase {

	private IRequest&MockObject $request;

	private GitHubClientInterface&MockObject $github;

	private GitHubGuards&MockObject $guards;

	private RateLimiterService&MockObject $rateLimiter;

	private IUserSession&MockObject $userSession;

	private ICache&MockObject $cache;

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => (array_key_exists($key, $this->params) === true ? $this->params[$key] : $default)
		);
		$this->github = $this->createMock(GitHubClientInterface::class);
		$this->guards = $this->createMock(GitHubGuards::class);
		$this->rateLimiter = $this->createMock(RateLimiterService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->cache = $this->createMock(ICache::class);
	}

	/**
	 * The controller over this test's doubles.
	 *
	 * @return GitHubIssuesController
	 */
	private function controller(): GitHubIssuesController {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache);

		return GitHubIssuesControllerFactory::create(
			appName: 'openregister',
			request: $this->request,
			githubHandler: $this->github,
			guards: $this->guards,
			validator: new GitHubRequestValidator(),
			rateLimiter: $this->rateLimiter,
			userSession: $this->userSession,
			cacheFactory: $cacheFactory,
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->createMock(IGroupManager::class)
		);
	}

	/**
	 * Sign alice in.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}

	/**
	 * A GitHub error response raised as Guzzle would.
	 *
	 * @param int                  $status  The status.
	 * @param array<string,string> $headers The headers.
	 *
	 * @return ClientException
	 */
	private function githubError(int $status, array $headers): ClientException {
		return new ClientException('github', new Request('GET', 'https://api.github.com'), new Response($status, $headers));
	}

	// --- index ------------------------------------------------------------

	public function testACacheHitIsAnsweredWithoutGitHubUnderASortedLabelKey(): void {
		$this->params = ['repo' => 'ConductionNL/openregister', 'labels' => ' b , a ,, ', 'per_page' => '10'];
		$this->cache->expects($this->once())->method('get')
			->with('list:ConductionNL/openregister:open:reactions-+1:10:a,b')->willReturn(['items' => [1]]);
		$this->github->expects($this->never())->method('listIssues');
		$this->guards->expects($this->never())->method('enforceGetRateLimit');

		$response = $this->controller()->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['items' => [1]], $response->getData());
		$this->assertSame('HIT', $response->getHeaders()['X-OpenRegister-GitHub-Cache']);
	}

	public function testAMissAsksGitHubWithTheParsedLabelsAndCachesTheAnswer(): void {
		$this->params = ['repo' => 'ConductionNL/openregister', 'labels' => 'b,a', 'state' => 'all', 'sort' => 'created'];
		$this->cache->method('get')->willReturn(null);
		$this->github->expects($this->once())->method('listIssues')
			->with(new GitHubIssueSelection(owner: 'ConductionNL', repo: 'openregister', state: 'all', sort: 'created', labels: ['b', 'a']))->willReturn(['items' => []]);
		$this->cache->expects($this->once())->method('set')
			->with('list:ConductionNL/openregister:all:created:30:a,b', ['items' => []], 900);

		$response = $this->controller()->index();

		$this->assertSame(['items' => []], $response->getData());
		$this->assertSame('MISS', $response->getHeaders()['X-OpenRegister-GitHub-Cache']);
	}

	public function testNoLabelsIsNullAndLeavesTheKeyBare(): void {
		$this->params = ['repo' => 'ConductionNL/openregister'];
		$this->cache->expects($this->once())->method('get')->with('list:ConductionNL/openregister:open:reactions-+1:30')->willReturn(null);
		$this->github->expects($this->once())->method('listIssues')
			->with(new GitHubIssueSelection(owner: 'ConductionNL', repo: 'openregister'))->willReturn([]);

		$this->controller()->index();
	}

	public function testAGuardRefusalIsReturnedBeforeTheCacheIsRead(): void {
		$refusal = new JSONResponse(['error' => 'feature_disabled'], 403);
		$this->guards->method('runGuards')->willReturn($refusal);
		$this->cache->expects($this->never())->method('get');

		$this->assertSame($refusal, $this->controller()->index());
	}

	public function testTheCacheMissRateLimitIsReturnedBeforeGitHubIsAsked(): void {
		$this->params = ['repo' => 'ConductionNL/openregister'];
		$limited = new JSONResponse(['error' => 'rate_limited'], 429);
		$this->guards->method('enforceGetRateLimit')->willReturn($limited);
		$this->github->expects($this->never())->method('listIssues');

		$this->assertSame($limited, $this->controller()->index());
	}

	public function testAReadWithoutAPatIsAnEmptyListWithAHint(): void {
		$this->params = ['repo' => 'ConductionNL/openregister'];
		$this->github->method('listIssues')->willThrowException(new \RuntimeException('github_pat_not_configured'));
		$this->cache->expects($this->never())->method('set');

		$response = $this->controller()->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['items' => [], 'hint' => 'github_pat_not_configured'], $response->getData());
	}

	public function testGitHubs429IsPassedOnWithTheResetMoment(): void {
		$this->params = ['repo' => 'ConductionNL/openregister'];
		$this->github->method('listIssues')->willThrowException($this->githubError(429, ['X-RateLimit-Reset' => '4102444800']));

		$response = $this->controller()->index();

		$this->assertSame(429, $response->getStatus());
		$this->assertSame(['error' => 'github_rate_limited', 'reset_at' => gmdate('c', 4102444800)], $response->getData());
		$this->assertArrayHasKey('Retry-After', $response->getHeaders());
	}

	public function testAn403WithNoRemainingQuotaIsARateLimitWithoutAResetHeader(): void {
		$this->params = ['repo' => 'ConductionNL/openregister'];
		$this->github->method('listIssues')->willThrowException($this->githubError(403, ['X-RateLimit-Remaining' => '0']));

		$response = $this->controller()->index();

		$this->assertSame(429, $response->getStatus());
		$this->assertSame(['error' => 'github_rate_limited'], $response->getData());
		$this->assertArrayNotHasKey('Retry-After', $response->getHeaders());
	}

	public function testAn403WithQuotaLeftIsABadGateway(): void {
		$this->params = ['repo' => 'ConductionNL/openregister'];
		$this->github->method('listIssues')->willThrowException($this->githubError(403, ['X-RateLimit-Remaining' => '5']));

		$response = $this->controller()->index();

		$this->assertSame(502, $response->getStatus());
		$this->assertSame(['error' => 'github_unavailable'], $response->getData());
	}

	// --- create -----------------------------------------------------------

	public function testASubmissionIsCreatedAndItsSlotMarked(): void {
		$this->signIn();
		$this->params = ['repo' => 'ConductionNL/openregister', 'title' => 'T', 'body' => 'B', 'specRef' => ''];
		$this->github->expects($this->once())->method('createIssue')
			->with(new GitHubIssueSubmission(owner: 'ConductionNL', repo: 'openregister', userId: 'alice', specRef: null), new GitHubIssueText(title: 'T', body: 'B'))->willReturn(['number' => 9]);
		$this->rateLimiter->expects($this->once())->method('markFixedWindow')->with('feature_submission:alice', 60);

		$response = $this->controller()->create();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['number' => 9], $response->getData());
	}

	public function testASpecRefIsPassedAsAString(): void {
		$this->signIn();
		$this->params = ['repo' => 'ConductionNL/openregister', 'title' => 'T', 'body' => 'B', 'specRef' => 'spec-x'];
		$this->github->expects($this->once())->method('createIssue')
			->with(new GitHubIssueSubmission(owner: 'ConductionNL', repo: 'openregister', userId: 'alice', specRef: 'spec-x'), new GitHubIssueText(title: 'T', body: 'B'))->willReturn([]);

		$this->controller()->create();
	}

	public function testARefusedSubmissionMarksNoSlot(): void {
		$this->signIn();
		$this->guards->method('runGuards')->willReturn(new JSONResponse(['error' => 'title_too_short'], 400));
		$this->github->expects($this->never())->method('createIssue');
		$this->rateLimiter->expects($this->never())->method('markFixedWindow');

		$this->assertSame(400, $this->controller()->create()->getStatus());
	}

	public function testASubmissionWithoutAPatIs503AndMarksNoSlot(): void {
		$this->signIn();
		$this->params = ['repo' => 'ConductionNL/openregister', 'title' => 'T', 'body' => 'B'];
		$this->github->method('createIssue')->willThrowException(new \RuntimeException('github_pat_not_configured'));
		$this->rateLimiter->expects($this->never())->method('markFixedWindow');

		$response = $this->controller()->create();

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(['error' => 'github_pat_not_configured'], $response->getData());
	}
}
