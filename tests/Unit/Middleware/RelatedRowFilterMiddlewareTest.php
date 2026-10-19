<?php

/**
 * A refused related-row filter answers 400, not 500.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\RelatedRowFilterNotSupportedException;
use OCA\OpenRegister\Middleware\RelatedRowFilterMiddleware;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `RelatedRowFilterMiddleware` and the multi-schema facet refusal it answers.
 *
 * @covers \OCA\OpenRegister\Middleware\RelatedRowFilterMiddleware
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses \OCA\OpenRegister\Exception\RelatedRowFilterNotSupportedException
 */
class RelatedRowFilterMiddlewareTest extends TestCase {

	/**
	 * The refusal becomes a 400 carrying the exception's message in the route's error shape.
	 *
	 * @return void
	 */
	public function testTheRefusalAnswers400WithItsMessage(): void {
		$response = (new RelatedRowFilterMiddleware())->afterException(
			controller: $this->createMock(Controller::class),
			methodName: 'index',
			exception: new RelatedRowFilterNotSupportedException('\'_related\' filters are not supported')
		);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => '\'_related\' filters are not supported'], $response->getData());
	}//end testTheRefusalAnswers400WithItsMessage()

	/**
	 * Every other exception, including a plain InvalidArgumentException, is rethrown.
	 *
	 * @return void
	 */
	public function testOtherExceptionsAreRethrown(): void {
		$this->expectException(\InvalidArgumentException::class);

		(new RelatedRowFilterMiddleware())->afterException(
			controller: $this->createMock(Controller::class),
			methodName: 'index',
			exception: new \InvalidArgumentException('something else')
		);
	}//end testOtherExceptionsAreRethrown()

	/**
	 * 🔴 THE MULTI-SCHEMA FACET ROUTE REFUSES, BEFORE ITS OWN CATCH BLOCKS CAN SWALLOW IT.
	 *
	 * The per-facet queries catch every exception and return empty buckets, so
	 * the refusal has to come first; without it the filter was dropped and the
	 * counts were wrong with only a log line to show for it.
	 *
	 * @return void
	 */
	public function testTheUnionFacetsRefuseRelatedRowFilters(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$search = new MagicSearchHandler(
			db: $this->createMock(IDBConnection::class),
			logger: $logger,
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($logger),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class)
		);
		$facets = new MagicFacetHandler(db: $this->createMock(IDBConnection::class), logger: $logger, searchHandler: $search);
		$tables = [['tableName' => 'openregister_table_1_2', 'register' => null, 'schema' => new Schema()]];

		foreach (['_related', '_referenced'] as $key) {
			try {
				$facets->getSimpleFacetsUnion(tableConfigs: $tables, query: ['_facets' => ['status'], $key => ['s' => ['case' => ['value' => 'x']]]]);
				$this->fail('Expected a refusal for ' . $key);
			} catch (RelatedRowFilterNotSupportedException $e) {
				$this->assertStringContainsString("'" . $key . "'", $e->getMessage());
			}
		}
	}//end testTheUnionFacetsRefuseRelatedRowFilters()
}//end class
