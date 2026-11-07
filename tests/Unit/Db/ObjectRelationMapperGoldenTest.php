<?php

/**
 * Golden test for ObjectRelationMapper: every query it builds and what it returns.
 *
 * Records each query-builder call made by the finders, createFromArray(),
 * save() and the deletions, with every bound parameter. A generated uuid is
 * written as `<uuid v4>` and a wall-clock date as its class only, so nothing
 * depends on today's date or randomness. Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Db\ObjectRelationMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ObjectRelationMapper
 */
class ObjectRelationMapperGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait {
		renderArgs as private recordedArgs;
	}

	private const FIXTURE = __DIR__ . '/fixtures/object-relation-mapper-golden.json';

	private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	public function testQueriesAndResultsMatchGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => [$call, $rows]) {
			$actual[$name] = $this->exercise(call: $call, rows: $rows);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertNotEmpty($expected);
		foreach ($expected as $name => $outcome) {
			$this->assertArrayHasKey('queries', $outcome, $name);
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testQueriesAndResultsMatchGolden()

	public function testCreateStampsCreatedAndUpdatedWithOneClock(): void {
		$outcome = $this->exercise(call: ['createFromArray', [['sourceUuid' => 's-1', 'targetUuid' => 't-1']]], rows: []);

		$this->assertSame('<uuid v4>', $outcome['result']['uuid']);
		$this->assertTrue($outcome['result']['createdIsUpdated']);
	}//end testCreateStampsCreatedAndUpdatedWithOneClock()

	private function cases(): array {
		$row = static fn (int $id): array => ['id' => $id, 'uuid' => 'rel-' . $id, 'source_uuid' => 's-1', 'target_uuid' => 't-' . $id, 'kind' => 'object'];
		$created = new DateTime('2001-05-06 07:08:09', new DateTimeZone('UTC'));
		$existing = new ObjectRelation();
		$existing->setId(4);
		$existing->setUuid('rel-4');
		$existing->setLabel('changed');

		return [
			'findBySource' => [['findBySource', ['s-1']], [[$row(1), $row(2)]]],
			'findBySource, kind' => [['findBySource', ['s-1', 'external']], [[$row(1)]]],
			'findBySource, empty kind' => [['findBySource', ['s-1', '']], [[]]],
			'findByTarget' => [['findByTarget', ['t-1']], [[$row(1)]]],
			'findTouching, none' => [['findTouching', [[]]], []],
			'findTouching, one' => [['findTouching', [['a']]], [[$row(1)]]],
			'findTouching, many' => [['findTouching', [['a', 'b', 'c']]], [[$row(1), $row(3)]]],
			'findByUuid, found' => [['findByUuid', ['rel-1']], [[$row(1)]]],
			'findByUuid, missing' => [['findByUuid', ['rel-9']], [[]]],
			'findByUuid, twice' => [['findByUuid', ['rel-1']], [[$row(1), $row(2)]]],
			'findByAnchor' => [['findByAnchor', ['s-1', 'anchor-a']], [[$row(2)]]],
			'createFromArray, minimal' => [['createFromArray', [['sourceUuid' => 's-1', 'targetUuid' => 't-1']]], []],
			'createFromArray, given uuid and created' => [['createFromArray', [$this->fullData(created: $created)]], []],
			'save' => [['save', [$existing]], []],
			'deleteByAnchor' => [['deleteByAnchor', ['s-1', 'anchor-a']], []],
			'deleteByObject' => [['deleteByObject', ['obj-1']], []],
		];
	}//end cases()

	private function fullData(DateTime $created): array {
		return [
			'uuid' => 'rel-given',
			'sourceUuid' => 's-1',
			'kind' => 'external',
			'targetUrl' => 'https://example.org/x',
			'origin' => 'prose',
			'anchor' => 'a1',
			'created' => $created,
		];
	}//end fullData()

	private function exercise(array $call, array $rows): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		$mapper = new ObjectRelationMapper($db);

		[$method, $args] = $call;
		$outcome = [];
		try {
			$outcome['result'] = $this->render($mapper->$method(...$args));
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e);
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end exercise()

	/**
	 * Call arguments as text, with generated uuids masked and fixed dates written out.
	 *
	 * @param array<int|string, mixed> $args The arguments.
	 *
	 * @return string The arguments joined by commas.
	 */
	private function renderArgs(array $args): string {
		foreach ($args as $key => $arg) {
			if (is_string($arg) === true && preg_match(self::UUID_V4, $arg) === 1) {
				$args[$key] = '<uuid v4>';
			}

			if ($arg instanceof DateTime && $arg->format('Y') === '2001') {
				$args[$key] = 'DateTime(' . $arg->format('Y-m-d H:i:s e') . ')';
			}
		}

		return $this->recordedArgs($args);
	}//end renderArgs()

	private function render(mixed $result): mixed {
		if (is_array($result) === true) {
			return array_map(fn (ObjectRelation $r): array => $this->relation(relation: $r), $result);
		}

		if ($result instanceof ObjectRelation) {
			return $this->relation(relation: $result);
		}

		return $result;
	}//end render()

	private function relation(ObjectRelation $relation): array {
		$uuid = (string) $relation->getUuid();
		if (preg_match(self::UUID_V4, $uuid) === 1) {
			$uuid = '<uuid v4>';
		}

		$created = $relation->getCreated();
		return [
			'id' => $relation->getId(),
			'uuid' => $uuid,
			'source' => $relation->getSourceUuid(),
			'target' => $relation->getTargetUuid(),
			'kind' => $relation->getKind(),
			'created' => $this->when(date: $created),
			'updated' => $this->when(date: $relation->getUpdated()),
			'createdIsUpdated' => $created !== null && $created === $relation->getUpdated(),
		];
	}//end relation()

	private function when(?DateTime $date): ?string {
		if ($date === null) {
			return null;
		}

		if ($date->format('Y') === '2001') {
			return $date->format('c');
		}

		return 'now';
	}//end when()
}//end class
