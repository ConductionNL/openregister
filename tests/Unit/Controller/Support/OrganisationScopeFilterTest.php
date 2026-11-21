<?php

/**
 * Unit test for OrganisationScopeFilter.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\OrganisationScopeFilter;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Narrowing a query to the caller's organisations.
 *
 * @covers \OCA\OpenRegister\Controller\Support\OrganisationScopeFilter
 */
class OrganisationScopeFilterTest extends TestCase {
	public function testNoScopeLeavesTheQueryAlone(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects($this->never())->method('andWhere');
		$qb->expects($this->never())->method('createNamedParameter');

		(new OrganisationScopeFilter(null))->narrow($qb, 'organisation');
	}

	public function testAScopeAddsAnInOverTheColumn(): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->expects($this->once())->method('in')->with('organisation', ':uuids')->willReturn('organisation IN (:uuids)');
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->expects($this->once())->method('createNamedParameter')
			->with(['o-1', 'o-2'], IQueryBuilder::PARAM_STR_ARRAY)->willReturn(':uuids');
		$qb->expects($this->once())->method('andWhere')->with('organisation IN (:uuids)');

		(new OrganisationScopeFilter(['o-1', 'o-2']))->narrow($qb, 'organisation');
	}

	public function testAnEmptyScopeStillNarrowsToNothing(): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturn('x');
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->expects($this->once())->method('createNamedParameter')->with([], IQueryBuilder::PARAM_STR_ARRAY)->willReturn(':u');
		$qb->expects($this->once())->method('andWhere')->with('x');

		(new OrganisationScopeFilter([]))->narrow($qb, 'org');
	}
}
