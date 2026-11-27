<?php

/**
 * FlowTriggerMapper on a query builder that answers parameters as objects, as Nextcloud 34 does.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Flow\FlowTriggerSet;
use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * A live publish on Nextcloud 34 failed with "Return value must be of type string, OC\DB\QueryBuilder\Parameter
 * returned": the insert typed its parameter closure as string, so no flow trigger was ever indexed.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
 */
class FlowTriggerMapperParameterObjectTest extends TestCase {

	/**
	 * Indexing a flow inserts its trigger row when the builder hands back parameter objects.
	 *
	 * @return void
	 */
	public function testReplaceForInsertsWhenParametersAreObjects(): void {
		$inserted = [];

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('flow_uuid = :p');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createNamedParameter')->willReturnCallback(
			fn (mixed $value): IParameter => $this->parameter(value: $value)
		);
		$qb->method('expr')->willReturn($expr);
		$qb->method('delete')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('insert')->willReturnSelf();
		$qb->method('values')->willReturnCallback(
			static function (array $values) use (&$inserted, $qb): IQueryBuilder {
				$inserted[] = $values;
				return $qb;
			}
		);
		$qb->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$written = (new FlowTriggerMapper($db))->replaceFor(
			new FlowTriggerSet(
				flowUuid: 'f1',
				triggers: [['event' => 'object.updated', 'register' => 'cases', 'schema' => 'case']],
				enabled: true
			)
		);

		$this->assertSame(1, $written);
		$this->assertCount(1, $inserted);
		$this->assertSame(['flow_uuid', 'event', 'register', 'schema_slug', 'enabled'], array_keys($inserted[0]));
		$this->assertContainsOnlyInstancesOf(IParameter::class, $inserted[0]);
		$this->assertSame(":'object.updated'", (string) $inserted[0]['event']);
	}//end testReplaceForInsertsWhenParametersAreObjects()

	/**
	 * A parameter object naming the value it stands for.
	 *
	 * @param mixed $value The bound value.
	 *
	 * @return IParameter The parameter.
	 */
	private function parameter(mixed $value): IParameter {
		return new class(':' . var_export($value, true)) implements IParameter {
			/**
			 * @param string $name The placeholder text.
			 */
			public function __construct(private readonly string $name) {
			}


			/**
			 * @return string The placeholder text.
			 */
			public function __toString(): string {
				return $this->name;
			}
		};
	}//end parameter()
}//end class
