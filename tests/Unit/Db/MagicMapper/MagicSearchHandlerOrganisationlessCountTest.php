<?php

/**
 * The org-less count reads past an organisation context, and only org-less rows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\TableSearch;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealInterface;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * MagicSearchHandler::countOrganisationless() builds its query outside an active
 * organisation context, forces the organisation filter to IS NULL, and restores
 * the context for every read after it.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 */
class MagicSearchHandlerOrganisationlessCountTest extends TestCase {

	/**
	 * Inside a context the count is not bounded by the context and asks only for org-less rows.
	 *
	 * @return void
	 */
	public function testTheCountReadsPastTheContextAndOnlyOrganisationlessRows(): void {
		$seen    = [];
		$handler = null;
		$schema  = $this->schema();

		$reveal = $this->createMock(originalClassName: ExplicitRevealInterface::class);
		$reveal->method('refuseOracleQuery')->willReturnCallback(
			static function (array $query) use (&$seen): void {
				$seen['query'] = $query;
			}
		);

		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$seen, &$handler, $schema) {
				$seen['bounded'] = $this->contextBounds(handler: $handler, schema: $schema);
				throw new RuntimeException(message: 'stop');
			}
		);

		$handler = $this->handler(db: $db, reveal: $reveal);

		OrganisationContext::run(
			organisationUuid: 'org-a',
			operation: function () use ($handler, $schema): void {
				try {
					$handler->countOrganisationless(
						query: ['code' => 'A-1', '@self' => ['organisation' => 'org-b']],
						tableSearch: $this->tableSearch(schema: $schema)
					);
					self::fail(message: 'The query builder was not reached.');
				} catch (RuntimeException $e) {
					self::assertSame(expected: 'stop', actual: $e->getMessage());
				}

				self::assertTrue(condition: $this->contextBounds(handler: $handler, schema: $schema));
			}
		);

		self::assertFalse(condition: $seen['bounded']);
		self::assertSame(expected: 'IS NULL', actual: $seen['query']['@self']['organisation']);
		self::assertSame(expected: 'A-1', actual: $seen['query']['code']);
		self::assertFalse(condition: $seen['query']['_rbac']);
		self::assertFalse(condition: $seen['query']['_multitenancy']);
	}//end testTheCountReadsPastTheContextAndOnlyOrganisationlessRows()

	/**
	 * Whether the handler applies the organisation boundary to a read with RBAC and multitenancy off.
	 *
	 * @param MagicSearchHandler $handler The handler.
	 * @param Schema             $schema  The schema.
	 *
	 * @return bool True when the boundary applies.
	 */
	private function contextBounds(MagicSearchHandler $handler, Schema $schema): bool {
		return (new ReflectionMethod(MagicSearchHandler::class, 'multitenancyApplies'))->invoke($handler, $schema, false, false, false);
	}//end contextBounds()

	/**
	 * A handler on mocks.
	 *
	 * @param IDBConnection           $db     The connection.
	 * @param ExplicitRevealInterface $reveal The reveal state, which sees the built query first.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function handler(IDBConnection $db, ExplicitRevealInterface $reveal): MagicSearchHandler {
		$logger = new NullLogger();

		return new MagicSearchHandler(
			db: $db,
			logger: $logger,
			rbacHandler: $this->createMock(originalClassName: MagicRbacHandler::class),
			organizationHandler: $this->createMock(originalClassName: MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer(logger: $logger),
			relatedRows: $this->createMock(originalClassName: RelatedRowQueryApplier::class),
			explicitReveal: $reveal
		);
	}//end handler()

	/**
	 * A schema with one string property.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties(['code' => ['type' => 'string']]);

		return $schema;
	}//end schema()

	/**
	 * The table of the schema in register 3.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return TableSearch The table.
	 */
	private function tableSearch(Schema $schema): TableSearch {
		$register = new Register();
		$register->setId(3);

		return new TableSearch(table: new RegisterSchema(register: $register, schema: $schema), tableName: 'openregister_table_3_7');
	}//end tableSearch()
}//end class
