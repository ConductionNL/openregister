<?php

/**
 * The label of a facet value: register and schema titles, organisation and object names.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetFieldLabel
 * @uses   \OCA\OpenRegister\Formats\UuidFormat
 */
class MagicFacetFieldLabelTest extends TestCase {
	private const UUID = '11111111-2222-4333-8444-555555555555';

	/**
	 * Every field, value, stored value and known name, with the label it gives.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: mixed, 3: array<string, string>|null, 4: string, 5: string|null}>
	 */
	public static function labels(): array {
		$named = [self::UUID => 'Named'];
		return [
			'register title' => ['_register', '5', 'Zaken', null, 'Zaken', 'openregister_registers.title id=5'],
			'register without a title' => ['_register', 5, false, null, 'Register 5', 'openregister_registers.title id=5'],
			'register, failing query' => ['_register', 5, 'throw', null, 'Register 5', 'openregister_registers.title id=5'],
			'register that is no number' => ['_register', 'abc', 'x', null, 'abc', null],
			'schema title' => ['_schema', 7, 'Besluit', null, 'Besluit', 'openregister_schemas.title id=7'],
			'schema without a title' => ['_schema', '7', false, null, 'Schema 7', 'openregister_schemas.title id=7'],
			'schema, failing query' => ['_schema', 7, 'throw', null, 'Schema 7', 'openregister_schemas.title id=7'],
			'empty title' => ['_schema', 7, '', null, '', 'openregister_schemas.title id=7'],
			'organisation name' => ['_organisation', self::UUID, 'Gemeente', $named, 'Gemeente', 'openregister_organisations.name uuid=' . self::UUID],
			'organisation found by its object' => ['_organisation', self::UUID, false, $named, 'Named', 'openregister_organisations.name uuid=' . self::UUID],
			'organisation with a null name' => ['_organisation', self::UUID, null, $named, 'Named', 'openregister_organisations.name uuid=' . self::UUID],
			'organisation, failing query' => ['_organisation', self::UUID, 'throw', $named, 'Named', 'openregister_organisations.name uuid=' . self::UUID],
			'organisation nobody names' => ['_organisation', self::UUID, false, [], '11111111...', 'openregister_organisations.name uuid=' . self::UUID],
			'organisation without name lookup' => ['_organisation', self::UUID, false, null, '11111111...', 'openregister_organisations.name uuid=' . self::UUID],
			'organisation that is no uuid' => ['_organisation', 'org-a', false, ['org-a' => 'A'], 'A', 'openregister_organisations.name uuid=org-a'],
			'empty organisation' => ['_organisation', '', 'x', null, '', null],
			'organisation that is a number' => ['_organisation', 12, 'x', null, '12', null],
			'object name' => ['owner', self::UUID, 'x', $named, 'Named', null],
			'object nobody names' => ['owner', self::UUID, 'x', [], '11111111...', null],
			'object without name lookup' => ['owner', self::UUID, 'x', null, '11111111...', null],
			'plain text' => ['status', 'open', 'x', $named, 'open', null],
			'a number' => ['count', 3, 'x', null, '3', null],
			'an empty value' => ['count', null, 'x', null, '', null],
		];
	}//end labels()

	/**
	 * @dataProvider labels
	 *
	 * @param string                     $field  The field.
	 * @param mixed                      $value  The value.
	 * @param mixed                      $stored What the query answers, or `throw`.
	 * @param array<string, string>|null $names  The names the lookup knows, or null without a lookup.
	 * @param string                     $label  The label.
	 * @param string|null                $query  The query that ran, or null for none.
	 *
	 * @return void
	 */
	public function testTheLabelOfAValue(string $field, mixed $value, mixed $stored, ?array $names, string $label, ?string $query): void {
		$queries = [];

		$this->assertSame($label, $this->label(handler: $this->handler(db: $this->db(stored: $stored, queries: $queries), names: $names), field: $field, value: $value));
		$this->assertSame(array_filter([$query]), $queries);
	}//end testTheLabelOfAValue()

	/**
	 * The label as the handler gives it.
	 *
	 * @param MagicFacetHandler $handler The handler.
	 * @param string            $field   The field.
	 * @param mixed             $value   The value.
	 *
	 * @return string The label.
	 */
	private function label(MagicFacetHandler $handler, string $field, mixed $value): string {
		$method = new ReflectionMethod(MagicFacetHandler::class, 'getFieldLabel');

		return $method->invoke($handler, $field, $value);
	}//end label()

	/**
	 * A handler on the given connection and names.
	 *
	 * @param IDBConnection              $db    The connection.
	 * @param array<string, string>|null $names The names the lookup knows, or null without a lookup.
	 *
	 * @return MagicFacetHandler The handler.
	 */
	private function handler(IDBConnection $db, ?array $names): MagicFacetHandler {
		$lookup = null;
		if ($names !== null) {
			$lookup = $this->createMock(ObjectNameCacheInterface::class);
			$lookup->method('getMultipleObjectNames')->willReturnCallback(
				static fn (array $identifiers): array => array_intersect_key($names, array_flip($identifiers))
			);
		}

		$reflection = new ReflectionClass(MagicFacetHandler::class);
		$handler    = $reflection->newInstanceWithoutConstructor();
		foreach (['db' => $db, 'cacheHandler' => $lookup, 'container' => null, 'lazyResolved' => true] as $name => $value) {
			$reflection->getProperty($name)->setValue($handler, $value);
		}

		return $handler;
	}//end handler()

	/**
	 * A connection whose every query answers the same and is recorded.
	 *
	 * @param mixed             $stored  What a query answers, or `throw`.
	 * @param array<int,string> $queries The queries that ran, filled by reference.
	 *
	 * @return IDBConnection The connection.
	 */
	private function db(mixed $stored, array &$queries): IDBConnection {
		$seen = ['select' => '', 'from' => '', 'where' => ''];
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn ($column, $value): string => "{$column}={$value}");

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('select')->willReturnCallback(static function ($column) use (&$seen, $qb) {
			$seen['select'] = $column;
			return $qb;
		});
		$qb->method('from')->willReturnCallback(static function ($table) use (&$seen, $qb) {
			$seen['from'] = $table;
			return $qb;
		});
		$qb->method('where')->willReturnCallback(static function ($where) use (&$seen, $qb) {
			$seen['where'] = $where;
			return $qb;
		});

		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($stored);
		$qb->method('executeQuery')->willReturnCallback(
			static function () use (&$seen, &$queries, $stored, $result): IResult {
				$queries[] = "{$seen['from']}.{$seen['select']} {$seen['where']}";
				if ($stored === 'throw') {
					throw new \RuntimeException('query refused');
				}

				return $result;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}//end db()
}//end class
