<?php

/**
 * ApiCallRecordMapper: the callers of a schema's object routes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
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

namespace OCA\OpenRegister\Tests\Unit\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use DateTime;
use OCA\OpenRegister\Db\ApiCallRecordMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ApiCallRecordMapper
 */
final class ApiCallRecordMapperCallersOfRoutesTest extends TestCase {
	use RecordsQueries;

	private const SLUG = '/apps/openregister/api/objects/zaken/melding';

	private const ID = '/apps/openregister/api/objects/{id}/melding';

	public function testCallersOnSlugAndIdRoutesAreSummedPerPrincipalBusiestFirst(): void {
		$from = new DateTime('2026-08-15T00:00:00+00:00');
		$this->results[] = [
			['principal' => 'leverancier-a', 'calls' => '40', 'last_call' => '2026-10-01 08:00:00'],
			['principal' => '', 'calls' => '7', 'last_call' => null],
		];

		$callers = (new ApiCallRecordMapper($this->recordingDb()))->findCallersOfRoutes([self::SLUG, self::ID, self::SLUG], $from, 10);

		$this->assertSame('leverancier-a', $callers[0]['principal']);
		$this->assertSame(40, $callers[0]['calls']);
		$this->assertSame((new DateTime('2026-10-01 08:00:00'))->format(DATE_ATOM), $callers[0]['lastSeen']);
		$this->assertSame(['principal' => '', 'calls' => 7, 'lastSeen' => null], $callers[1], 'The anonymous principal is returned like any other.');
		$this->assertSame(
			'select(principal) selectAlias(sum(call_count), calls) selectAlias(max(last_seen), last_call) from(openregister_api_calls)'
			. ' where(gte(last_seen, :p0)) andWhere(orX(eq(route, :p1), like(route, :p2), eq(route, :p3), like(route, :p4)))'
			. ' groupBy(principal) orderBy(calls, DESC) addOrderBy(principal, ASC) setMaxResults(10) executeQuery()',
			$this->sql()
		);
		$this->assertSame([$from, IQueryBuilder::PARAM_DATETIME_MUTABLE], $this->params[':p0']);
		$this->assertSame(self::SLUG, $this->bound(':p1'));
		$this->assertSame(self::SLUG . '/%', $this->bound(':p2'), 'Object routes below the list route match; a longer slug does not.');
		$this->assertSame('/apps/openregister/api/objects/{id}/melding/%', $this->bound(':p4'));
	}//end testCallersOnSlugAndIdRoutesAreSummedPerPrincipalBusiestFirst()

	public function testALikeWildcardInASlugIsEscaped(): void {
		(new ApiCallRecordMapper($this->recordingDb()))->findCallersOfRoutes(['/x/a_b'], new DateTime());

		$this->assertSame('/x/a\\_b/%', $this->bound(':p2'));
		$this->assertStringContainsString('setMaxResults(500)', $this->sql());
	}//end testALikeWildcardInASlugIsEscaped()

	public function testNoPrefixesAskNothing(): void {
		$mapper = new ApiCallRecordMapper($this->recordingDb());

		$this->assertSame([], $mapper->findCallersOfRoutes([], new DateTime()));
		$this->assertSame(0, $mapper->countCallersOfRoutes([], new DateTime()));
		$this->assertSame([], $this->queries);
	}//end testNoPrefixesAskNothing()
}//end class
