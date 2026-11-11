<?php

/**
 * Golden test for ViewPresentationService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Service\CalendarRange;
use OCA\OpenRegister\Search\Service\ViewPresentationService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * Each case runs getCalendarObjects() or getKanbanBoard() against a recorded
 * object service. The recording holds the result (objects described, not
 * dumped), every object-service call and every logged error.
 * Compared with fixtures/view-presentation-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Search\Service\ViewPresentationService
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Search\Db\View
 * @uses   \OCA\OpenRegister\Service\Rbac\AggregateVisibility
 * @uses   \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 */
class ViewPresentationServiceGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/view-presentation-golden.json';

	/**
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * Case => [call, presentation, query, results per search call, facets].
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function cases(): array {
		$query = ['registers' => [3], 'schemas' => ['meetings'], 'filters' => ['status' => 'open'], 'sort' => ['start' => 'asc']];
		$calendar = ['viewType' => 'calendar', 'calendar' => ['dateField' => 'start', 'endDateField' => 'end']];
		$kanban = ['viewType' => 'kanban', 'kanban' => ['groupByField' => 'status']];
		return [
			'calendar not a calendar'      => ['calendar', ['viewType' => 'map'], $query, [], null],
			'calendar no presentation'     => ['calendar', null, $query, [], null],
			'calendar missing dateField'   => ['calendar', ['viewType' => 'calendar', 'calendar' => ['dateField' => '']], $query, [], null],
			'calendar missing schema'      => ['calendar', $calendar, ['registers' => [3]], [], null],
			'calendar without end field'   => ['calendar', ['viewType' => 'calendar', 'calendar' => ['dateField' => 'start']], $query, [
				['results' => [['id' => 1, 't' => 'a'], ['uuid' => 'u-2'], ['t' => 'no id'], ['t' => 'no id']]],
			], null],
			'calendar end field not string' => [
				'calendar',
				['viewType' => 'calendar', 'calendar' => ['dateField' => 'start', 'endDateField' => 7]],
				['registers' => [3], 'schemas' => [4]],
				[
					['results' => [['id' => 9]]],
				],
				null,
			],
			'calendar empty end field'     => ['calendar', ['viewType' => 'calendar', 'calendar' => ['dateField' => 'start', 'endDateField' => '']], $query, [
				[],
			], null],
			'calendar with spanning merge' => ['calendar', $calendar, $query + ['filters' => []], [
				['results' => ['entity:u-1:1', 'entity::5', 'entity::', ['id' => 'u-1', 'v' => 'array'], 'object']],
				['results' => ['entity:u-1:2', 'entity::5', ['id' => 7], ['uuid' => 'u-8', 'id' => 8]]],
			], null],
			'calendar no filters no sort'  => ['calendar', $calendar, ['registers' => [3], 'schemas' => [4], 'filters' => 'x', 'sort' => ''], [
				['results' => []],
				['results' => [['id' => 1]]],
			], null],
			'kanban facet data buckets'    => ['kanban', $kanban, $query, [['results' => [['id' => 1]], 'total' => 4], ['results' => []]], [
				'facets' => ['status' => ['data' => ['buckets' => [['value' => 'open'], ['key' => 'closed'], ['count' => 2]]]]],
			]],
			'kanban facet buckets'         => ['kanban', $kanban, $query, [['results' => [['id' => 1], ['id' => 2]]]], [
				'facets' => ['status' => ['buckets' => [['key' => 'todo', 'value' => null], ['value' => 0]]]],
			]],
			'kanban facet none'            => ['kanban', $kanban, $query, [], ['facets' => []]],
			'kanban facet throws'          => ['kanban', $kanban, $query, [], 'throw'],
		];
	}

	/**
	 * @param string                    $call         calendar or kanban.
	 * @param array<string, mixed>|null $presentation The view presentation.
	 * @param array<string, mixed>      $query        The view query.
	 * @param list<array<string, mixed>> $results     Results per search call.
	 * @param mixed                     $facets       Facets answer, or 'throw'.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testGolden(string $call, ?array $presentation, array $query, array $results, mixed $facets): void {
		$view = new View();
		$view->setPresentation($presentation);
		$view->setQuery($query);
		$service = $this->service($results, $facets);

		try {
			$result = match ($call) {
				'calendar' => $service->getCalendarObjects($view, new CalendarRange('2026-03-01', '2026-03-31')),
				default => $service->getKanbanBoard($view, ['_limit' => '5', '_offset' => 2]),
			};
			$recorded = $this->describe($result);
		} catch (InvalidArgumentException $e) {
			$recorded = ['error' => $e->getMessage()];
		}

		$this->assertGolden((string) $this->dataName(), ['result' => $recorded, 'log' => $this->log]);
	}

	/**
	 * @param list<array<string, mixed>> $results Results per search call.
	 * @param mixed                      $facets  Facets answer, or 'throw'.
	 *
	 * @return ViewPresentationService
	 */
	private function service(array $results, mixed $facets): ViewPresentationService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnCallback(function ($register) use ($objectService) {
			$this->log[] = ['setRegister' => $register];
			return $objectService;
		});
		$objectService->method('setSchema')->willReturnCallback(function ($schema) use ($objectService) {
			$this->log[] = ['setSchema' => $schema];
			return $objectService;
		});
		$objectService->method('searchObjectsPaginated')->willReturnCallback(function (array $query) use (&$results) {
			$this->log[] = ['search' => $query];
			$answer = (array_shift($results) ?? []);
			if (isset($answer['results']) === true) {
				$answer['results'] = array_map(fn ($spec) => $this->object($spec), $answer['results']);
			}
			return $answer;
		});
		$objectService->method('getFacetsForObjects')->willReturnCallback(function (array $query) use ($facets) {
			$this->log[] = ['facets' => $query];
			if ($facets === 'throw') {
				throw new RuntimeException('facets down');
			}
			return $facets;
		});

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn(new Schema());

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
			unset($context['file'], $context['line']);
			$this->log[] = ['error' => [(string) $message, $context]];
		});

		return new ViewPresentationService($schemaMapper, $objectService, $logger);
	}

	/**
	 * Build a result row from its spec: an array row, `entity:<uuid>:<id>` or `object`.
	 *
	 * @param mixed $spec The spec.
	 *
	 * @return mixed
	 */
	private function object(mixed $spec): mixed {
		if ($spec === 'object') {
			$object = new stdClass();
			$object->name = 'plain';
			return $object;
		}

		if (is_string($spec) === true && str_starts_with($spec, 'entity:') === true) {
			[, $uuid, $id] = explode(':', $spec);
			$entity = new ObjectEntity();
			$entity->setUuid($uuid);
			if ($id !== '') {
				$entity->setId((int) $id);
			}
			return $entity;
		}

		return $spec;
	}

	/**
	 * Replace result rows by a stable description.
	 *
	 * @param array<string, mixed> $result The service result.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(array $result): array {
		$describe = static function (mixed $row): mixed {
			if ($row instanceof ObjectEntity) {
				return 'entity:' . $row->getUuid() . ':' . $row->getId();
			}
			if (is_object($row) === true) {
				return 'object:' . get_class($row);
			}
			return $row;
		};
		if (isset($result['objects']) === true) {
			$result['objects'] = array_map($describe, $result['objects']);
		}
		foreach (($result['columns'] ?? []) as $index => $column) {
			$result['columns'][$index]['cards'] = array_map($describe, $column['cards']);
		}
		return $result;
	}

	/**
	 * @param string $case   The case name.
	 * @param mixed  $actual The recorded value.
	 *
	 * @return void
	 */
	private function assertGolden(string $case, mixed $actual): void {
		$actual = json_decode((string) json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true);
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$case] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$this->assertArrayHasKey($case, $golden, 'No golden entry; record with UPDATE_GOLDEN=1.');
		$this->assertSame($golden[$case], $actual);
	}
}
