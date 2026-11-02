<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use OCA\OpenRegister\Controller\SearchTrailController;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Service\SearchTrailService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Golden test for SearchTrailController::extractRequestParameters().
 *
 * Each case feeds a set of request parameters to the parser that every
 * search-trail list endpoint shares, and compares what it returns with
 * fixtures/search-trail-request-parameters-golden.json. Dates are recorded
 * as ISO 8601 text. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\SearchTrailController
 */
class SearchTrailControllerRequestParametersGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/search-trail-request-parameters-golden.json';

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'no parameters' => [[]],
			'plain pagination' => [['limit' => '5', 'offset' => '10', 'page' => '3']],
			'underscore pagination' => [['_limit' => '7', '_offset' => '14', '_page' => '2']],
			'plain wins over underscore' => [['_limit' => '7', 'limit' => '9', '_offset' => '1', 'offset' => '2', '_page' => '4', 'page' => '5']],
			'page without offset' => [['page' => '4', 'limit' => '25']],
			'underscore page without offset uses the default limit' => [['_page' => '3']],
			'page zero' => [['page' => '0']],
			'page with offset keeps the offset' => [['page' => '4', '_offset' => '3']],
			'null values are ignored' => [['limit' => null, '_limit' => null, 'offset' => null, 'page' => null, 'from' => null, 'sort' => null]],
			'search plain' => [['search' => 'needle']],
			'search underscore wins' => [['_search' => 'first', 'search' => 'second']],
			'sort plain with order' => [['sort' => 'title', 'order' => 'ASC']],
			'sort underscore wins' => [['_sort' => 'name', 'sort' => 'title', '_order' => 'asc', 'order' => 'DESC']],
			'sort without order' => [['_sort' => 'created']],
			'order without sort' => [['order' => 'ASC']],
			'valid dates' => [['from' => '2026-01-02T03:04:05+00:00', 'to' => '2026-02-03T00:00:00+02:00']],
			'invalid dates are ignored' => [['from' => 'not a date', 'to' => '31-31-2026 99:99']],
			'filters keep unknown keys' => [['register' => '5', 'schema' => 'person', 'user' => 'alice', 'id' => '9', '_route' => 'x', 'limit' => '3', '_extend' => 'a']],
			'numeric keys' => [[0 => 'zero', 'limit' => '2']],
			'non numeric limit' => [['limit' => 'abc', 'offset' => '1.9']],
		];
	}

	#[DataProvider('cases')]
	public function testExtractRequestParametersMatchesTheRecording(array $params): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$controller = new SearchTrailController(
			'openregister',
			$request,
			$this->createMock(SearchTrailService::class),
			$this->createMock(SearchTrailMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class)
		);

		$method = new ReflectionMethod(SearchTrailController::class, 'extractRequestParameters');
		$result = $method->invoke($controller);
		foreach (['from', 'to'] as $key) {
			if ($result[$key] instanceof DateTime) {
				$result[$key] = 'DateTime ' . $result[$key]->format(DATE_ATOM);
			}
		}

		$this->compare($this->dataName(), ['types' => array_map('gettype', $result), 'result' => $result]);
	}

	/**
	 * @param array<string, mixed> $actual
	 */
	private function compare(string $key, array $actual): void {
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			if (self::$recorded === null) {
				self::$recorded = [];
			}

			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(
				self::FIXTURE,
				json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
			);
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
