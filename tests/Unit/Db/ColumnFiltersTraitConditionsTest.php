<?php

/**
 * ColumnFiltersTrait condition tests.
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

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ColumnFiltersTrait;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * 'IS NULL' and 'IS NOT NULL' become null checks; any other value is equality.
 */
class ColumnFiltersTraitConditionsTest extends TestCase {
	use RecordsQueries;

	/**
	 * Apply filters through a host that uses the trait.
	 *
	 * @param array|null $filters The filters.
	 *
	 * @return string The recorded query.
	 */
	private function apply(?array $filters): string {
		$host = new class {
			use ColumnFiltersTrait;

			/**
			 * Expose the trait's private method.
			 *
			 * @param IQueryBuilder $qb      The builder.
			 * @param array|null    $filters The filters.
			 *
			 * @return void
			 */
			public function run(IQueryBuilder $qb, ?array $filters): void {
				$this->applyColumnFilters(qb: $qb, filters: $filters);
			}//end run()
		};
		$host->run($this->recordingDb()->getQueryBuilder(), $filters);
		return $this->sql();
	}//end apply()

	/**
	 * Each sentinel maps to its null check and other values bind as equality.
	 *
	 * @return void
	 */
	public function testSentinelsBecomeNullChecks(): void {
		$sql = $this->apply(['deleted' => 'IS NULL', 'owner' => 'IS NOT NULL', 'slug' => 'zaken']);

		$this->assertSame('andWhere(isNull(deleted)) andWhere(isNotNull(owner)) andWhere(eq(slug, :p0))', $sql);
		$this->assertSame(['zaken'], $this->boundValues());
	}//end testSentinelsBecomeNullChecks()

	/**
	 * Null or empty filters add no condition.
	 *
	 * @return void
	 */
	public function testNoFiltersAddNothing(): void {
		$this->assertSame('', $this->apply(null));
		$this->assertSame('', $this->apply([]));
		$this->assertSame([], $this->params);
	}//end testNoFiltersAddNothing()
}//end class
