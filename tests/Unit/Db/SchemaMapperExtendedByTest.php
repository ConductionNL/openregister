<?php

/**
 * SchemaMapperExtendedByTest.
 *
 * `findAllExtendedBy()` built its reverse map by casting each `allOf` entry to
 * string. An `allOf` entry is a SCHEMA OBJECT in standard JSON Schema —
 * `{"$ref": "person"}` — and `(string)` on one yields the literal "Array",
 * which matches no key in the lookup. So the map came back EMPTY while a PHP
 * notice was emitted per row:
 *
 *   Array to string conversion at lib/Db/SchemaMapper.php#4148
 *   GET /index.php/apps/openregister/api/schemas?_limit=1000
 *
 * Measured across the fleet's descriptors: 236 allOf/oneOf/anyOf entries, ALL
 * objects, NOT ONE a bare string. The feature was inert for every real schema.
 *
 * 🔴 THE POINT OF THIS SUITE IS THE CALLER, NOT THE RESOLVER.
 * `parentIdentifierFromAllOfEntry()` already handles the three entry shapes and
 * is already tested. The defect was that `findAllExtendedBy()` did not USE it —
 * so a test that exercises the resolver directly would have stayed green
 * throughout. These arms drive `findAllExtendedBy()` itself.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the reverse "extended by" map over realistic allOf shapes.
 */
class SchemaMapperExtendedByTest extends TestCase {
	/**
	 * A result that yields the given rows once, then false.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows to serve.
	 *
	 * @return IResult The fake result.
	 */
	private function resultOf(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$queue  = $rows;
		$result->method('fetch')->willReturnCallback(
			static function () use (&$queue) {
				if ($queue === []) {
					return false;
				}
				return array_shift($queue);
			}
		);
		return $result;
	}

	/**
	 * A mapper whose two queries return the given row sets, in order.
	 *
	 * @param array<int, array<string, mixed>> $extending All schemas carrying a composition keyword.
	 * @param array<int, array<string, mixed>> $all       Every schema, for id/uuid/slug lookup.
	 *
	 * @return SchemaMapper The mapper under test.
	 */
	private function mapperFor(array $extending, array $all): SchemaMapper {
		$results = [$this->resultOf($extending), $this->resultOf($all)];

		$expr = $this->createMock(IExpressionBuilder::class);
		// Typed returns: orX() declares ICompositeExpression, isNotNull()
		// declares string. A mock that returns the wrong type errors before the
		// method under test is ever reached.
		$expr->method('orX')->willReturn($this->createMock(ICompositeExpression::class));
		$expr->method('isNotNull')->willReturn('notnull');

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$results, $expr): IQueryBuilder {
				$qb = $this->createMock(IQueryBuilder::class);
				$qb->method('select')->willReturnSelf();
				$qb->method('from')->willReturnSelf();
				$qb->method('where')->willReturnSelf();
				$qb->method('expr')->willReturn($expr);
				$qb->method('executeQuery')->willReturnCallback(
					static function () use (&$results) {
						if ($results === []) {
							throw new DoesNotExistException('unexpected third query');
						}
						return array_shift($results);
					}
				);
				return $qb;
			}
		);

		return new SchemaMapper(
			$db,
			$this->createMock(IEventDispatcher::class),
			$this->createMock(PropertyValidatorHandler::class),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * 🔴 THE SHAPE THE FLEET ACTUALLY SHIPS. All 236 composition entries across
	 * the fleet's descriptors are objects; not one is a bare string. Under the
	 * old cast this returned [] and logged a notice per row.
	 */
	public function testAnObjectRefEntryResolvesToTheParentSchema(): void {
		$mapper = $this->mapperFor(
			[['id' => 7, 'uuid' => 'child-uuid', 'slug' => 'child', 'all_of' => '[{"$ref": "person"}]']],
			[
				['id' => 3, 'uuid' => 'person-uuid', 'slug' => 'person'],
				['id' => 7, 'uuid' => 'child-uuid', 'slug' => 'child'],
			]
		);

		$map = $mapper->findAllExtendedBy();

		$this->assertArrayHasKey(3, $map, 'the parent schema must appear in the reverse map');
		$this->assertContains('child-uuid', $map[3]);
	}

	/**
	 * A `$ref` with a JSON-pointer path resolves to its LAST segment, which is
	 * what the lookup is keyed on.
	 */
	public function testAPointerRefResolvesToItsLastSegment(): void {
		$mapper = $this->mapperFor(
			[['id' => 7, 'uuid' => 'child-uuid', 'slug' => 'child', 'all_of' => '[{"$ref": "#/components/schemas/person"}]']],
			[['id' => 3, 'uuid' => 'person-uuid', 'slug' => 'person']]
		);

		$map = $mapper->findAllExtendedBy();

		$this->assertArrayHasKey(3, $map);
	}

	/**
	 * The scalar shorthand must keep working — it is OpenRegister's own form
	 * and the whole allOf-delta mechanism was built around it.
	 */
	public function testTheScalarShorthandStillResolves(): void {
		$mapper = $this->mapperFor(
			[['id' => 7, 'uuid' => 'child-uuid', 'slug' => 'child', 'all_of' => '["person"]']],
			[['id' => 3, 'uuid' => 'person-uuid', 'slug' => 'person']]
		);

		$map = $mapper->findAllExtendedBy();

		$this->assertArrayHasKey(3, $map);
	}

	/**
	 * 🔴 AN ENTRY THAT NAMES NO PARENT CONTRIBUTES NOTHING — quietly, and
	 * without a cast. `{"if": …, "then": …}` and inline `{"properties": …}` are
	 * valid JSON Schema; they are not references. The old code turned each into
	 * the string "Array" and looked it up.
	 */
	public function testAnInlineCompositionEntryNamesNoParentAndIsSkipped(): void {
		$mapper = $this->mapperFor(
			[[
				'id' => 7,
				'uuid' => 'child-uuid',
				'slug' => 'child',
				'one_of' => '[{"required": ["ltiPlatformId"], "not": {"required": ["ltiToolId"]}}]',
			]],
			[['id' => 3, 'uuid' => 'person-uuid', 'slug' => 'person']]
		);

		$map = $mapper->findAllExtendedBy();

		$this->assertSame([], $map, 'an inline composition entry names no parent');
	}
}
