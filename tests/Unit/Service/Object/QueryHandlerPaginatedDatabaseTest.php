<?php

/**
 * The calls and the response of QueryHandler::searchObjectsPaginatedDatabase().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\ContentSearchHandler;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Search\HistoryNarrowing;
use OCP\AppFramework\IAppContainer;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the pagination arithmetic, the queries handed to the mapper, the render
 * path chosen, the facet calls and every `@self` key of the response.
 *
 * Expected values live in `fixtures/paginated-database.php`, one entry per case.
 * Timings are recorded by their metric keys only.
 */
class QueryHandlerPaginatedDatabaseTest extends TestCase {

	/**
	 * Every case: the call arguments and the collaborators' answers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function cases(): array {
		$authSchema = ['id' => 1, 'properties' => ['secret' => ['authorization' => ['read' => ['admin']]]]];

		return [
			'defaults'                         => [],
			'page given'                       => ['query' => ['_limit' => 10, '_page' => 3]],
			'page below one'                   => ['query' => ['_limit' => 10, '_page' => -2]],
			'offset given'                     => ['query' => ['_limit' => 10, '_offset' => 25]],
			'page and offset given'            => ['query' => ['_limit' => 10, '_page' => 4, '_offset' => 5]],
			'limit zero'                       => ['query' => ['_limit' => 0, '_offset' => 25], 'answer' => ['total' => 7]],
			'negative limit'                   => ['query' => ['_limit' => -5]],
			'control keys removed'             => [
				'query' => ['_limit' => 5, '_page' => 2, '_facetable' => 'true', '_extend' => '_schema,owner', 'name' => 'x'],
			],
			'multitenancy asks the org'        => ['multitenancy' => true],
			'rbac false and ids and uses'      => ['rbac' => false, 'ids' => ['a', 'b'], 'uses' => 'u-1', 'deleted' => true],
			'history narrows to nothing'       => [
				'query'   => ['_was_ever' => ['status' => 'open'], 'name' => 'x'],
				'history' => [],
			],
			'history narrows to some'          => [
				'query'   => ['_was_ever' => ['status' => 'open'], '_changed_between' => ['x' => 'nonsense']],
				'history' => ['id-1'],
			],
			'history unparsed only'            => ['query' => ['_was_ever' => ['' => 'open']]],
			'content search'                   => ['query' => ['_content_search' => 'true', '_search' => 'tax'], 'answer' => ['total' => 2]],
			'cheap path with rows'             => ['answer' => ['results' => [['id' => 'r1']], 'total' => 41], 'query' => ['_limit' => 20]],
			'render for fields'                => ['query' => ['_fields' => ['name']], 'answer' => ['results' => [['id' => 'r1']]]],
			'render for filter and unset'      => ['query' => ['_filter' => ['a' => 1], '_unset' => ['b']], 'answer' => ['results' => []]],
			'render for extend string'         => ['query' => ['_extend' => ' owner , @self.schema ,'], 'answer' => ['results' => []]],
			'only schema extends stay cheap'   => ['query' => ['_extend' => ['_schema', '@self.register']], 'answer' => ['results' => []]],
			'render for array property authz'  => ['answer' => ['results' => [], 'schemas' => [1 => $authSchema]]],
			'empty authz stays cheap'          => [
				'answer' => ['results' => [], 'schemas' => [1 => ['properties' => ['a' => ['authorization' => []], 'b' => 'x']]]],
			],
			'render for entity property authz' => ['answer' => ['results' => [], 'schemas' => ['entity-authz']]],
			'render for a lens'                => [
				'answer' => ['results' => [], 'schemas' => [1 => ['configuration' => ['x-openregister-lenses' => ['a' => []]]]]],
			],
			'non-array results skip rendering' => ['query' => ['_fields' => ['a']], 'answer' => ['results' => 'nope']],
			'registers and schemas extended'   => [
				'query'  => ['_extend' => '_registers,@self.schemas'],
				'answer' => ['results' => [], 'registers' => [3 => ['id' => 3]], 'schemas' => [1 => ['id' => 1]]],
			],
			'extend singular forms'            => [
				'query'  => ['_extend' => ['@self.register', '_schema']],
				'answer' => ['results' => [], 'registers' => [3 => ['id' => 3]], 'schemas' => [1 => ['id' => 1]]],
			],
			'extend asked but nothing loaded'  => ['query' => ['_extend' => '_registers,_schemas']],
			'ignored filters without mistakes' => ['answer' => ['ignoredFilters' => ['colour']]],
			'ignored filters with mistakes'    => ['answer' => ['ignoredFilters' => ['limit', 'colour', 'sort']]],
			'mapper metrics and source'        => [
				'answer' => ['source' => 'index', 'metrics' => ['search_ms' => 1.5, 'count_ms' => 2.5]],
			],
			'mapper metrics partial'           => ['answer' => ['metrics' => []]],
			'recent lens report'               => ['query' => ['_recentLens' => ['answered' => false, 'reason' => 'no audit']]],
			'recent lens not an array'         => ['query' => ['_recentLens' => 'yes']],
			'facets'                           => ['query' => ['_facets' => ['status' => ['type' => 'terms']], '_limit' => 3]],
			'facets with breakdown'            => ['query' => ['_facets' => 'x'], 'facets' => ['facets' => ['f' => 1], 'performance_metadata' => ['facet_db_ms' => ['f' => 3]]]],
			'facetable true'                   => ['query' => ['_facetable' => true]],
			'facetable string'                 => ['query' => ['_facetable' => 'true']],
			'facetable other string'           => ['query' => ['_facetable' => '1']],
		];
	}//end cases()

	/**
	 * Every case paired with its expected outcome.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function casesWithExpectations(): array {
		$expected = require __DIR__.'/fixtures/paginated-database.php';
		$pairs    = [];
		foreach (self::cases() as $name => $case) {
			$pairs[$name] = [$case, $expected[$name] ?? ['missing expectation' => $name]];
		}

		return $pairs;
	}//end casesWithExpectations()

	/**
	 * The method makes the recorded calls and answers the recorded response.
	 *
	 * @param array<string, mixed> $case     The case
	 * @param array<string, mixed> $expected The recorded calls and response
	 *
	 * @return void
	 */
	#[DataProvider('casesWithExpectations')]
	public function testThePaginatedSearchAnswersAsRecorded(array $case, array $expected): void {
		$this->assertSame($expected, $this->runCase(case: $case));
	}//end testThePaginatedSearchAnswersAsRecorded()

	/**
	 * Run one case and record the collaborator calls and the response.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return array{calls: array<int, array>, response: array}
	 */
	public function runCase(array $case): array {
		$calls   = new \ArrayObject();
		$handler = $this->handlerFor(case: $case, calls: $calls);

		$response = $handler->searchObjectsPaginatedDatabase(
			query: $case['query'] ?? [],
			_rbac: $case['rbac'] ?? true,
			_multitenancy: $case['multitenancy'] ?? false,
			deleted: $case['deleted'] ?? false,
			ids: $case['ids'] ?? null,
			uses: $case['uses'] ?? null
		);

		$response['@self']['metrics'] = array_keys($response['@self']['metrics']);

		return ['calls' => $calls->getArrayCopy(), 'response' => $response];
	}//end runCase()

	/**
	 * Build a handler whose collaborators record their calls and answer as the case says.
	 *
	 * @param array<string, mixed> $case  The case
	 * @param \ArrayObject         $calls Collects the calls
	 *
	 * @return QueryHandler
	 */
	private function handlerFor(array $case, \ArrayObject $calls): QueryHandler {
		$answer = ($case['answer'] ?? []) + ['results' => [], 'total' => 0];
		if (in_array('entity-authz', $answer['schemas'] ?? [], true) === true) {
			$schema = $this->createMock(Schema::class);
			$schema->method('hasPropertyAuthorization')->willReturn(true);
			$answer['schemas'] = [1 => $schema];
		}

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('searchObjectsPaginated')->willReturnCallback(
			static function (...$args) use ($calls, $answer): array {
				$calls[] = ['searchObjectsPaginated', $args];
				return $answer;
			}
		);

		$render = $this->createMock(RenderObject::class);
		$render->method('renderEntities')->willReturnCallback(
			static function (...$args) use ($calls): array {
				$calls[] = ['renderEntities', $args];
				return [['rendered' => true]];
			}
		);
		foreach (['attachLightweightFilesToRows', 'resolveTranslationsForRows'] as $method) {
			$render->method($method)->willReturnCallback(
				static function (...$args) use ($calls, $method): void {
					$calls[] = [$method, $args];
				}
			);
		}

		$render->method('redactWriteOnlyFromRows')->willReturnCallback(
			static function (...$args) use ($calls): void {
				$calls[] = ['redactWriteOnlyFromRows', $args];
			}
		);

		$facets = $this->createMock(FacetHandler::class);
		$facets->method('getFacetsForObjects')->willReturnCallback(
			static function (...$args) use ($calls, $case): array {
				$calls[] = ['getFacetsForObjects', $args];
				return $case['facets'] ?? ['facets' => ['status' => []]];
			}
		);
		$facets->method('getFacetableFields')->willReturnCallback(
			static function (...$args) use ($calls): array {
				$calls[] = ['getFacetableFields', $args];
				return ['name' => 'string'];
			}
		);

		$performance = $this->createMock(PerformanceOptimizationHandler::class);
		$performance->method('getActiveOrganisationForContext')->willReturn('org-1');

		$content = $this->createMock(ContentSearchHandler::class);
		$content->method('augmentWithChunkMatches')->willReturnCallback(
			static function (...$args) use ($calls): array {
				$calls[] = ['augmentWithChunkMatches', $args];
				return ['results' => [['id' => 'chunk-hit']], 'total' => 99];
			}
		);

		$history = null;
		if (array_key_exists('history', $case) === true) {
			$history = $this->createMock(HistoryNarrowing::class);
			$history->method('narrow')->willReturnCallback(
				static function (...$args) use ($calls, $case): array {
					$calls[] = ['narrow', [$args[1] ?? null]];
					return $case['history'];
				}
			);
		}

		return new QueryHandler(
			$mapper,
			$this->createMock(GetObject::class),
			$render,
			$this->createMock(SearchQueryHandler::class),
			$facets,
			$performance,
			$content,
			$this->createMock(IAppContainer::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IRequest::class),
			$history
		);
	}//end handlerFor()
}//end class
