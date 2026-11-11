<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use Exception;
use OCA\OpenRegister\Controller\SearchTrailController;
use OCA\OpenRegister\Db\SearchTrail;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Service\SearchPeriod;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\SearchTrailControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of every SearchTrailController action.
 *
 * Pins the admin gate (401 for anonymous, 403 for a non-admin, nothing
 * touched behind it) and, for an admin, the exact status and body of each
 * allowed and failing path: the pagination envelope and its next/prev
 * links, the statistics shapes, cleanup's date handling, the export rows
 * and the clear-all outcomes. Export filenames carry a timestamp and are
 * only checked for their prefix and extension.
 *
 * @covers \OCA\OpenRegister\Controller\SearchTrailController
 */
class SearchTrailControllerA24GoldenTest extends TestCase {
	/** @var SearchTrailService&MockObject */
	private SearchTrailService $service;

	/** @var SearchTrailMapper&MockObject */
	private SearchTrailMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(SearchTrailService::class);
		$this->mapper = $this->createMock(SearchTrailMapper::class);
	}

	/**
	 * Build the controller for a caller and a request.
	 *
	 * @param string|null          $uid    The caller, null for anonymous.
	 * @param array<string, mixed> $params The request parameters.
	 * @param string               $uri    The request URI.
	 *
	 * @return SearchTrailController
	 */
	private function controller(?string $uid = 'admin', array $params = [], string $uri = '/api/search-trails'): SearchTrailController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default
		);
		$request->method('getRequestUri')->willReturn($uri);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $id): bool => $id === 'admin');

		return SearchTrailControllerFactory::create('openregister', $request, $this->service, $this->mapper, $session, $groups);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function actions(): array {
		$names = ['index', 'show', 'statistics', 'popularTerms', 'activity', 'registerSchemaStats', 'userAgentStats',
			'cleanup', 'export', 'destroy', 'destroyMultiple', 'clearAll'];

		return array_combine($names, array_map(static fn (string $name): array => [$name], $names));
	}

	private function call(SearchTrailController $controller, string $action): JSONResponse {
		if ($action === 'show' || $action === 'destroy') {
			return $controller->$action(7);
		}

		return $controller->$action();
	}

	private function assertOutcome(int $status, mixed $body, JSONResponse $response): void {
		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}

	#[DataProvider('actions')]
	public function testAnonymousCallerIsRefusedWith401(string $action): void {
		$this->service->expects($this->never())->method($this->anything());
		$this->mapper->expects($this->never())->method($this->anything());

		$response = $this->call($this->controller(null, ['before' => 'garbage']), $action);

		$this->assertOutcome(401, ['error' => 'Authentication required'], $response);
	}

	#[DataProvider('actions')]
	public function testNonAdminCallerIsRefusedWith403(string $action): void {
		$this->service->expects($this->never())->method($this->anything());
		$this->mapper->expects($this->never())->method($this->anything());

		$response = $this->call($this->controller('bob', ['before' => 'garbage']), $action);

		$this->assertOutcome(403, ['error' => 'Forbidden: this search-trail operation is admin-only'], $response);
	}

	#[DataProvider('actions')]
	public function testAdminCallerGetsPastTheGate(string $action): void {
		$this->service->method('getSearchTrail')->willReturn(new SearchTrail());
		$this->service->method('getSearchTrails')->willReturn(['results' => []]);

		$response = $this->call($this->controller('admin'), $action);

		$this->assertSame(200, $response->getStatus());
	}

	public function testIndexPassesRawParamsWithoutRouteAndIdAndBuildsLinks(): void {
		$this->service->expects($this->once())->method('getSearchTrails')
			->with(['_page' => '2', 'register' => '5'])
			->willReturn(['results' => ['a', 'b'], 'total' => 45, 'limit' => 10, 'offset' => 10, 'page' => 2]);

		$response = $this->controller(
			'admin',
			['_page' => '2', 'register' => '5', '_route' => 'r', 'id' => '3'],
			'/api/search-trails?_page=2&_limit=10'
		)->index();

		$this->assertOutcome(
			200,
			[
				'results' => ['a', 'b'],
				'total' => 45,
				'page' => 2,
				'pages' => 5.0,
				'limit' => 10,
				'offset' => 10,
				'next' => '/api/search-trails?_page=3&_limit=10',
				'prev' => '/api/search-trails?_page=1&_limit=10',
			],
			$response
		);
	}

	public function testIndexRewritesTheLegacyPageParameterAndDerivesTheOffset(): void {
		$this->service->method('getSearchTrails')->willReturn(['results' => [], 'total' => 30, 'limit' => 10, 'offset' => 0, 'page' => 2]);

		$response = $this->controller('admin', [], '/x?page=2&a=1')->index();

		$this->assertOutcome(
			200,
			['results' => [], 'total' => 30, 'page' => 2, 'pages' => 3.0, 'limit' => 10, 'offset' => 10,
				'next' => '/x?_page=3&a=1', 'prev' => '/x?_page=1&a=1'],
			$response
		);
	}

	public function testIndexAppendsThePageWithAnAmpersandAndDerivesThePageFromTheOffset(): void {
		$this->service->method('getSearchTrails')->willReturn(['results' => ['r'], 'total' => 50, 'limit' => 10, 'offset' => 20, 'page' => 1]);

		$response = $this->controller('admin', [], '/x')->index();

		$this->assertOutcome(
			200,
			['results' => ['r'], 'total' => 50, 'page' => 3.0, 'pages' => 5.0, 'limit' => 10, 'offset' => 20,
				'next' => '/x&_page=4', 'prev' => '/x&_page=2'],
			$response
		);
	}

	public function testIndexRaisesTheTotalToTheResultCount(): void {
		$this->service->method('getSearchTrails')->willReturn(['results' => [1, 2, 3], 'total' => 0, 'limit' => 2, 'offset' => 0, 'page' => 1]);

		$response = $this->controller('admin', [], '/x?q=1')->index();

		$this->assertOutcome(
			200,
			['results' => [1, 2, 3], 'total' => 3, 'page' => 1, 'pages' => 2.0, 'limit' => 2, 'offset' => 0, 'next' => '/x?q=1&_page=2'],
			$response
		);
	}

	public function testIndexDefaultsAnEmptyServiceResult(): void {
		$this->service->method('getSearchTrails')->willReturn([]);

		$response = $this->controller()->index();

		$this->assertOutcome(200, ['results' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'limit' => 20, 'offset' => 0], $response);
	}

	public function testIndexFailure(): void {
		$this->service->method('getSearchTrails')->willThrowException(new Exception('boom'));

		$this->assertOutcome(500, ['error' => 'Failed to retrieve search trails: boom'], $this->controller()->index());
	}

	public function testShowReturnsTheTrail(): void {
		$trail = new SearchTrail();
		$this->service->expects($this->once())->method('getSearchTrail')->with(7)->willReturn($trail);

		$response = $this->controller()->show(7);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($trail, $response->getData());
	}

	public function testShowNotFound(): void {
		$this->service->method('getSearchTrail')->willThrowException(new DoesNotExistException('gone'));

		$this->assertOutcome(404, ['error' => 'Search trail not found'], $this->controller()->show(7));
	}

	public function testShowFailure(): void {
		$this->service->method('getSearchTrail')->willThrowException(new Exception('boom'));

		$this->assertOutcome(500, ['error' => 'Failed to retrieve search trail: boom'], $this->controller()->show(7));
	}

	public function testStatisticsPassesTheParsedPeriod(): void {
		$this->service->expects($this->once())->method('getSearchStatistics')
			->with($this->isInstanceOf(DateTime::class), null)
			->willReturn(['total' => 4]);

		$response = $this->controller('admin', ['from' => '2024-01-01', 'to' => 'not-a-date'])->statistics();

		$this->assertOutcome(200, ['total' => 4], $response);
	}

	public function testStatisticsFailure(): void {
		$this->service->method('getSearchStatistics')->willThrowException(new Exception('boom'));

		$this->assertOutcome(500, ['error' => 'Failed to get search statistics: boom'], $this->controller()->statistics());
	}

	public function testActivityPassesTheInterval(): void {
		$this->service->expects($this->once())->method('getSearchActivity')->with(new SearchPeriod(), 'week')->willReturn(['a' => 1]);

		$this->assertOutcome(200, ['a' => 1], $this->controller('admin', ['interval' => 'week'])->activity());
	}

	public function testActivityFailure(): void {
		$this->service->method('getSearchActivity')->willThrowException(new Exception('boom'));

		$this->assertOutcome(500, ['error' => 'Failed to get search activity: boom'], $this->controller()->activity());
	}

	public function testPopularTermsPagesTheTermsAndAddsTheMetadata(): void {
		$this->service->expects($this->once())->method('getPopularSearchTerms')->with(new SearchPeriod(), 5)
			->willReturn(['terms' => ['t1', 't2'], 'total_unique_terms' => 12, 'total_searches' => 99, 'period' => ['days' => 7]]);

		$response = $this->controller('admin', ['_limit' => '5', 'page' => '2'], '/terms?page=2')->popularTerms();

		$this->assertOutcome(
			200,
			['results' => ['t1', 't2'], 'total' => 12, 'page' => 2, 'pages' => 3.0, 'limit' => 5, 'offset' => 5,
				'next' => '/terms?_page=3', 'prev' => '/terms?_page=1', 'total_searches' => 99, 'period' => ['days' => 7]],
			$response
		);
	}

	public function testPopularTermsDefaults(): void {
		$this->service->expects($this->once())->method('getPopularSearchTerms')->with(new SearchPeriod(), 10)->willReturn([]);

		$response = $this->controller()->popularTerms();

		$this->assertOutcome(
			200,
			['results' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'limit' => 10, 'offset' => 0, 'total_searches' => 0, 'period' => null],
			$response
		);
	}

	public function testPopularTermsFailure(): void {
		$this->service->method('getPopularSearchTerms')->willThrowException(new Exception('boom'));

		$this->assertOutcome(500, ['error' => 'Failed to get popular search terms: boom'], $this->controller()->popularTerms());
	}

	public function testRegisterSchemaStatsPagesTheStatistics(): void {
		$this->service->method('getRegisterSchemaStatistics')
			->willReturn(['statistics' => ['s1'], 'total_combinations' => 1, 'total_searches' => 7, 'period' => 'p']);

		$response = $this->controller()->registerSchemaStats();

		$this->assertOutcome(
			200,
			['results' => ['s1'], 'total' => 1, 'page' => 1, 'pages' => 1, 'limit' => 20, 'offset' => 0, 'total_searches' => 7, 'period' => 'p'],
			$response
		);
	}

	public function testRegisterSchemaStatsUsesThePlainLimitWhenNoUnderscoreLimit(): void {
		$this->service->method('getRegisterSchemaStatistics')->willReturn([]);

		$response = $this->controller('admin', ['limit' => '3'])->registerSchemaStats();

		$this->assertSame(3, $response->getData()['limit']);
	}

	public function testRegisterSchemaStatsFailure(): void {
		$this->service->method('getRegisterSchemaStatistics')->willThrowException(new Exception('boom'));

		$this->assertOutcome(500, ['error' => 'Failed to get register/schema statistics: boom'], $this->controller()->registerSchemaStats());
	}

	public function testUserAgentStatsStructuredResult(): void {
		$this->service->expects($this->once())->method('getUserAgentStatistics')->with(new SearchPeriod(), 10)->willReturn([
			'user_agents' => ['a' => 'ua1', 'b' => 'ua2'],
			'total_user_agents' => 2,
			'period' => 'p',
			'browser_distribution' => ['Firefox' => 2],
		]);

		$response = $this->controller()->userAgentStats();

		$this->assertOutcome(
			200,
			['results' => ['ua1', 'ua2'], 'total' => 2, 'page' => 1, 'pages' => 1, 'limit' => 10, 'offset' => 0,
				'total_searches' => 0, 'period' => 'p', 'browser_breakdown' => ['Firefox' => 2]],
			$response
		);
	}

	public function testUserAgentStatsStructuredWithoutBrowsersAndNonArrayAgents(): void {
		$this->service->method('getUserAgentStatistics')->willReturn(['user_agents' => 'oops', 'browser_distribution' => []]);

		$response = $this->controller()->userAgentStats();

		$this->assertOutcome(
			200,
			['results' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'limit' => 10, 'offset' => 0, 'total_searches' => 0, 'period' => null],
			$response
		);
	}

	public function testUserAgentStatsPlainList(): void {
		$this->service->method('getUserAgentStatistics')->willReturn(['x' => 'ua1', 'y' => 'ua2', 'z' => 'ua3']);

		$response = $this->controller('admin', ['limit' => '2'], '/ua')->userAgentStats();

		$this->assertOutcome(
			200,
			['results' => ['ua1', 'ua2', 'ua3'], 'total' => 3, 'page' => 1, 'pages' => 2.0, 'limit' => 2, 'offset' => 0, 'next' => '/ua&_page=2'],
			$response
		);
	}

	public function testUserAgentStatsFailure(): void {
		$this->service->method('getUserAgentStatistics')->willThrowException(new Exception('boom'));

		$this->assertOutcome(500, ['error' => 'Failed to get user agent statistics: boom'], $this->controller()->userAgentStats());
	}

	public function testCleanupRejectsAnInvalidDateBeforeTouchingTheService(): void {
		$this->service->expects($this->never())->method('cleanupSearchTrails');

		$response = $this->controller('admin', ['before' => 'garbage'])->cleanup();

		$this->assertOutcome(400, ['error' => 'Invalid date format for before parameter'], $response);
	}

	public function testCleanupWithADate(): void {
		$this->service->expects($this->once())->method('cleanupSearchTrails')
			->with($this->isInstanceOf(DateTime::class))->willReturn(['deleted' => 3]);

		$this->assertOutcome(200, ['deleted' => 3], $this->controller('admin', ['before' => '2024-01-01'])->cleanup());
	}

	public function testCleanupWithoutADate(): void {
		$this->service->expects($this->once())->method('cleanupSearchTrails')->with(null)->willReturn(['deleted' => 0]);

		$this->assertOutcome(200, ['deleted' => 0], $this->controller()->cleanup());
	}

	public function testCleanupFailure(): void {
		$this->service->method('cleanupSearchTrails')->willThrowException(new Exception('boom'));

		$this->assertOutcome(500, ['error' => 'Cleanup failed: boom'], $this->controller()->cleanup());
	}

	private function exportRow(): object {
		return new class {
			public function getId(): int {
				return 1;
			}

			public function getSearchTerm(): string {
				return 'needle';
			}

			public function getRequestUri(): string {
				return '/q';
			}

			public function getResultCount(): int {
				return 2;
			}

			public function getTotalResults(): int {
				return 9;
			}

			public function getResponseTime(): int {
				return 30;
			}

			public function getExecutionType(): string {
				return 'sync';
			}

			public function getUserId(): string {
				return 'alice';
			}

			public function getUserAgent(): string {
				return 'UA';
			}

			public function getIpAddress(): string {
				return '10.0.0.1';
			}

			public function getSessionId(): string {
				return 's1';
			}

			public function getCreated(): ?string {
				return null;
			}

			public function getUpdated(): ?string {
				return null;
			}

			public function getSearchParameters(): array {
				return ['a' => 1];
			}

			public function getResultMetadata(): array {
				return ['b' => 2];
			}
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function exportBase(): array {
		return [
			'id' => 1, 'search_term' => 'needle', 'request_uri' => '/q', 'result_count' => 2, 'total_results' => 9,
			'response_time' => 30, 'execution_type' => 'sync', 'user_id' => 'alice', 'user_agent' => 'UA',
			'ip_address' => '10.0.0.1', 'session_id' => 's1', 'created' => null, 'updated' => null,
		];
	}

	/**
	 * Assert the export envelope.
	 *
	 * @param string               $content     The expected content.
	 * @param string               $contentType The expected content type.
	 * @param string               $extension   The expected filename extension.
	 * @param array<string, mixed> $data        The response body.
	 *
	 * @return void
	 */
	private function assertExport(string $content, string $contentType, string $extension, array $data): void {
		$this->assertSame(['success', 'data'], array_keys($data));
		$this->assertTrue($data['success']);
		$this->assertSame(['content', 'filename', 'contentType', 'size'], array_keys($data['data']));
		$this->assertSame($content, $data['data']['content']);
		$this->assertSame($contentType, $data['data']['contentType']);
		$this->assertSame(strlen($content), $data['data']['size']);
		$this->assertStringStartsWith('search-trails-', $data['data']['filename']);
		$this->assertStringEndsWith($extension, $data['data']['filename']);
	}

	public function testExportCsvWithMetadataQueriesTheFilteredTrails(): void {
		$this->service->expects($this->once())->method('getSearchTrails')->with([
			'filters' => ['register' => '5', 'includeMetadata' => 'true'],
			'search' => 'q',
			'from' => null,
			'to' => null,
			'limit' => null,
			'offset' => null,
		])->willReturn(['results' => [$this->exportRow()]]);

		$response = $this->controller('admin', ['register' => '5', '_search' => 'q', 'includeMetadata' => 'true'])->export();

		$header = 'id,search_term,request_uri,result_count,total_results,response_time,execution_type,user_id,user_agent,'
			. 'ip_address,session_id,created,updated,search_parameters,result_metadata';
		$row = '1,needle,/q,2,9,30,sync,alice,UA,10.0.0.1,s1,,,"{""a"":1}","{""b"":2}"';
		$this->assertSame(200, $response->getStatus());
		$this->assertExport($header . "\n" . $row . "\n", 'text/csv', '.csv', $response->getData());
	}

	public function testExportJsonWithoutMetadata(): void {
		$this->service->method('getSearchTrails')->willReturn(['results' => [$this->exportRow()]]);

		$response = $this->controller('admin', ['format' => 'json'])->export();

		$this->assertExport(json_encode([$this->exportBase()], JSON_PRETTY_PRINT), 'application/json', '.json', $response->getData());
	}

	public function testExportWithNoRows(): void {
		$this->service->method('getSearchTrails')->willReturn(['results' => []]);

		$this->assertExport('', 'text/csv', '.csv', $this->controller()->export()->getData());
	}

	public function testExportOfARealEntityRow(): void {
		$this->service->method('getSearchTrails')->willReturn(['results' => [new SearchTrail()]]);

		$response = $this->controller()->export();

		$this->assertOutcome(500, ['error' => 'Export failed: userId is not a valid attribute'], $response);
	}

	public function testExportFailure(): void {
		$this->service->method('getSearchTrails')->willThrowException(new Exception('boom'));

		$this->assertOutcome(500, ['error' => 'Export failed: boom'], $this->controller()->export());
	}

	public function testDestroyValidatesTheTrail(): void {
		$this->service->expects($this->once())->method('getSearchTrail')->with(7)->willReturn(new SearchTrail());

		$this->assertOutcome(
			200,
			['success' => true, 'message' => 'Search trail deletion not implemented yet'],
			$this->controller()->destroy(7)
		);
	}

	public function testDestroyNotFound(): void {
		$this->service->method('getSearchTrail')->willThrowException(new DoesNotExistException('gone'));

		$this->assertOutcome(404, ['error' => 'Search trail not found'], $this->controller()->destroy(7));
	}

	public function testDestroyFailure(): void {
		$this->service->method('getSearchTrail')->willThrowException(new Exception('boom'));

		$this->assertOutcome(500, ['error' => 'Deletion failed: boom'], $this->controller()->destroy(7));
	}

	public function testDestroyMultiple(): void {
		$message = 'Multiple search trail deletion not implemented yet';

		$this->assertOutcome(
			200,
			['success' => true, 'results' => ['deleted' => 0, 'failed' => 0, 'message' => $message], 'message' => $message],
			$this->controller()->destroyMultiple()
		);
	}

	public function testClearAllWhenRowsWereCleared(): void {
		$this->mapper->expects($this->once())->method('clearAllLogs')->willReturn(true);

		$this->assertOutcome(
			200,
			['success' => true, 'message' => 'All search trails cleared successfully', 'deleted' => 'All expired search trails have been deleted'],
			$this->controller()->clearAll()
		);
	}

	public function testClearAllWhenNothingWasCleared(): void {
		$this->mapper->method('clearAllLogs')->willReturn(false);

		$this->assertOutcome(
			200,
			['success' => true, 'message' => 'No expired search trails found to clear', 'deleted' => 0],
			$this->controller()->clearAll()
		);
	}

	public function testClearAllFailure(): void {
		$this->mapper->method('clearAllLogs')->willThrowException(new Exception('boom'));

		$this->assertOutcome(
			500,
			['success' => false, 'error' => 'Failed to clear search trails: boom'],
			$this->controller()->clearAll()
		);
	}
}
