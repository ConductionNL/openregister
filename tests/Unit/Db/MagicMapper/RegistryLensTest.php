<?php

/**
 * RegistryLensTest: the `_registry[state]` / `_registry[updatedBefore]` lenses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-subscription-state-and-freshness-are-query-lenses
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\RegistryLens;
use OCA\OpenRegister\Exception\RegistryLensException;
use OCA\OpenRegister\Exception\RelatedRowFilterNotSupportedException;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\RegistryLens
 */
class RegistryLensTest extends TestCase {

	/**
	 * Values the binder received, in order.
	 *
	 * @var list<array{0: mixed, 1: bool}>
	 */
	private array $bound = [];

	/**
	 * A binder that records each value and names it `:pN`.
	 *
	 * @return callable
	 */
	private function bind(): callable {
		return function (mixed $value, bool $isList): string {
			$this->bound[] = [$value, $isList];
			return ':p' . count($this->bound);
		};
	}//end bind()

	public function testAQueryWithoutTheLensHasNone(): void {
		$this->assertNull((new RegistryLens())->parse(['_limit' => 10]));
	}//end testAQueryWithoutTheLensHasNone()

	public function testStatesAreReadFromAStringAListOrACommaList(): void {
		$lens = new RegistryLens();

		$this->assertSame(['states' => ['active'], 'updatedBefore' => null], $lens->parse(['_registry' => ['state' => 'active']]));
		$this->assertSame(['active', 'ended'], $lens->parse(['_registry' => ['state' => 'Active, ended,active']])['states']);
		$this->assertSame(['requested', 'failed'], $lens->parse(['_registry' => ['state' => ['requested', 'failed']]])['states']);
	}//end testStatesAreReadFromAStringAListOrACommaList()

	public function testTheMomentIsNormalisedToUtc(): void {
		$lens = new RegistryLens();

		$this->assertSame('2026-10-24 00:00:00', $lens->parse(['_registry' => ['updatedBefore' => '2026-10-24']])['updatedBefore']);
		$this->assertSame('2026-10-24 07:00:00', $lens->parse(['_registry' => ['updatedBefore' => '2026-10-24T09:00:00+02:00']])['updatedBefore']);
	}//end testTheMomentIsNormalisedToUtc()

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function refusedLenses(): array {
		return [
			'not a map' => ['active'],
			'empty' => [[]],
			'unknown key' => [['status' => 'active']],
			'unknown state' => [['state' => 'stale']],
			'state not a string' => [['state' => 3]],
			'moment not a date' => [['updatedBefore' => 'last month']],
			'moment empty' => [['updatedBefore' => '']],
			'moment not a string' => [['updatedBefore' => ['2026-10-24']]],
			'moment out of range' => [['updatedBefore' => '2026-13-45']],
		];
	}//end refusedLenses()

	/**
	 * A lens it cannot read is refused, never dropped: dropping answers the whole list.
	 *
	 * @dataProvider refusedLenses
	 */
	public function testALensThatCannotBeReadIsRefused(mixed $lens): void {
		$this->expectException(RegistryLensException::class);
		(new RegistryLens())->parse(['_registry' => $lens]);
	}//end testALensThatCannotBeReadIsRefused()

	public function testStateAndMomentNarrowToStaleSubscriptions(): void {
		$sql = (new RegistryLens())->condition(['states' => ['active'], 'updatedBefore' => '2026-10-24 00:00:00'], 't._uuid', $this->bind());

		$this->assertSame(
			'EXISTS (SELECT 1 FROM *PREFIX*openregister_registry_subs cn_rs WHERE cn_rs.object_uuid = t._uuid'
			. ' AND cn_rs.state IN (:p1) AND (cn_rs.last_update_at IS NULL OR cn_rs.last_update_at < :p2))',
			$sql
		);
		$this->assertSame([[['active'], true], ['2026-10-24 00:00:00', false]], $this->bound);
	}//end testStateAndMomentNarrowToStaleSubscriptions()

	public function testStateNoneAlsoMatchesObjectsWithoutASubscription(): void {
		$sql = (new RegistryLens())->condition(['states' => ['none'], 'updatedBefore' => null], 't._uuid', $this->bind());

		$this->assertSame(
			'(EXISTS (SELECT 1 FROM *PREFIX*openregister_registry_subs cn_rs WHERE cn_rs.object_uuid = t._uuid AND cn_rs.state IN (:p1))'
			. ' OR NOT EXISTS (SELECT 1 FROM *PREFIX*openregister_registry_subs cn_rsn WHERE cn_rsn.object_uuid = t._uuid))',
			$sql
		);
	}//end testStateNoneAlsoMatchesObjectsWithoutASubscription()

	public function testAMomentAloneAlsoMatchesObjectsWithoutASubscription(): void {
		$sql = (new RegistryLens())->condition(['states' => null, 'updatedBefore' => '2026-10-24 00:00:00'], 't._uuid', $this->bind());

		$this->assertStringStartsWith('(EXISTS (', $sql);
		$this->assertStringEndsWith(' OR NOT EXISTS (SELECT 1 FROM *PREFIX*openregister_registry_subs cn_rsn WHERE cn_rsn.object_uuid = t._uuid))', $sql);
		$this->assertStringNotContainsString('state IN', $sql);
	}//end testAMomentAloneAlsoMatchesObjectsWithoutASubscription()

	public function testAnActiveStateExcludesObjectsWithoutASubscription(): void {
		$sql = (new RegistryLens())->condition(['states' => ['active', 'requested'], 'updatedBefore' => null], 't._uuid', $this->bind());

		$this->assertStringNotContainsString('NOT EXISTS', $sql);
	}//end testAnActiveStateExcludesObjectsWithoutASubscription()

	public function testTheHandlerNarrowsTheListWithValuesBoundOnTheOuterQuery(): void {
		$captured = [];
		$named    = [];
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value, $type = null) use (&$named): string {
				$named[] = [$value, $type];
				return ':dcValue' . count($named);
			}
		);
		$qb->method('createFunction')->willReturnCallback(static fn (string $sql) => $sql);
		$qb->method('andWhere')->willReturnCallback(
			static function (...$predicates) use (&$captured, $qb) {
				foreach ($predicates as $predicate) {
					$captured[] = (string)$predicate;
				}

				return $qb;
			}
		);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyRegistryLens');
		$method->invoke($this->handler(), $qb, ['_registry' => ['state' => 'active', 'updatedBefore' => '2026-10-24']]);
		$method->invoke($this->handler(), $qb, ['_limit' => 5]);

		$this->assertCount(1, $captured);
		$this->assertStringContainsString('cn_rs.state IN (:dcValue1)', $captured[0]);
		$this->assertStringContainsString('cn_rs.last_update_at < :dcValue2', $captured[0]);
		$this->assertSame([[['active'], IQueryBuilder::PARAM_STR_ARRAY], ['2026-10-24 00:00:00', IQueryBuilder::PARAM_STR]], $named);
	}//end testTheHandlerNarrowsTheListWithValuesBoundOnTheOuterQuery()

	public function testTheUnionPathRefusesTheLens(): void {
		$this->expectException(RelatedRowFilterNotSupportedException::class);
		$this->expectExceptionMessage("'_registry' filters are not supported on a query across several schemas");
		$this->handler()->refuseRelatedRowFilters(query: ['_registry' => ['state' => 'active']]);
	}//end testTheUnionPathRefusesTheLens()

	/**
	 * The handler on a connection double.
	 *
	 * @return MagicSearchHandler
	 */
	private function handler(): MagicSearchHandler {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQL120Platform::class));
		$logger = $this->createMock(LoggerInterface::class);

		return new MagicSearchHandler(
			db: $db,
			logger: $logger,
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer(logger: $logger),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class)
		);
	}//end handler()
}//end class
