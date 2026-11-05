<?php

/**
 * Runs a schema change and the admin facet clear over a given cache factory.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas\Support;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Settings\CacheSettingsHandler;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The facet cache paths of the schema and settings code, over one factory.
 */
class FacetCacheSubject {

	/**
	 * The facet cache handler under test.
	 *
	 * @var FacetCacheHandler
	 */
	private FacetCacheHandler $handler;

	/**
	 * Build the subject.
	 *
	 * @param TestCase      $test    The test, for mocks.
	 * @param ICacheFactory $factory The recording cache factory.
	 */
	public function __construct(private readonly TestCase $test, private readonly ICacheFactory $factory) {
		$this->handler = new FacetCacheHandler($this->emptyTable(), $this->mock(SchemaMapperInterface::class), $factory, new NullLogger());
	}//end __construct()

	/**
	 * A schema is updated.
	 *
	 * @return null
	 */
	public function schemaChange(): mixed {
		$this->handler->invalidateForSchemaChange(schemaId: 7, operation: 'update');
		return null;
	}//end schemaChange()

	/**
	 * The admin clears the facet cache.
	 *
	 * @return array<string, mixed> The response.
	 */
	public function adminClear(): array {
		$settings = new CacheSettingsHandler($this->factory, $this->mock(SchemaCacheHandler::class), $this->handler);
		return $settings->clearCache(type: 'facet');
	}//end adminClear()

	/**
	 * A mock of a class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return object The mock.
	 */
	private function mock(string $class): object {
		return $this->test->getMockBuilder($class)->disableOriginalConstructor()->getMock();
	}//end mock()

	/**
	 * A connection whose facet cache table is empty.
	 *
	 * @return IDBConnection The connection.
	 */
	private function emptyTable(): IDBConnection {
		$result = $this->mock(IResult::class);
		$result->method('fetchAll')->willReturn([]);
		$result->method('fetch')->willReturn(false);
		$expr = $this->mock(IExpressionBuilder::class);
		$expr->method($this->test->anything())->willReturn('1 = 1');
		$func = $this->mock(IFunctionBuilder::class);
		$func->method($this->test->anything())->willReturn($this->mock(IQueryFunction::class));
		$qb = $this->mock(IQueryBuilder::class);
		foreach (['delete', 'select', 'addSelect', 'from', 'where', 'andWhere', 'groupBy'] as $chain) {
			$qb->method($chain)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeStatement')->willReturn(0);
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->mock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}//end emptyTable()
}//end class
