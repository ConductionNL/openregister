<?php

/**
 * Characterisation of TenantUsageMapper::findByOrgAndPeriod(): the query for one
 * organisation's usage row in one period, and null when there is none.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Golden period lookup of TenantUsageMapper.
 *
 * @covers \OCA\OpenRegister\Db\TenantUsageMapper
 */
final class TenantUsageMapperPeriodLookupGoldenTest extends TestCase {
	use RecordsQueries;

	/**
	 * The lookup matches the organisation and the period's stored form.
	 *
	 * @return void
	 */
	public function testLookupMatchesOrganisationAndPeriod(): void {
		$this->results[] = [['id' => 3]];
		$mapper = new TenantUsageMapper($this->recordingDb());

		$this->assertSame(3, $mapper->findByOrgAndPeriod('org-1', new DateTime('2026-05-06 07:00:00'))?->getId());
		$this->assertSame(
			'select(*) from(openregister_tenant_usage) where(eq(organisation_uuid, :p0)) andWhere(eq(period, :p1)) executeQuery()',
			$this->sql()
		);
		$this->assertSame(['org-1', IQueryBuilder::PARAM_STR], $this->params[':p0']);
		$this->assertSame(['2026-05-06 07:00:00', IQueryBuilder::PARAM_STR], $this->params[':p1']);
	}//end testLookupMatchesOrganisationAndPeriod()

	/**
	 * No row, or more than one, is null.
	 *
	 * @return void
	 */
	public function testMissingOrAmbiguousRowIsNull(): void {
		$this->results[] = [];
		$this->results[] = [['id' => 1], ['id' => 2]];
		$mapper = new TenantUsageMapper($this->recordingDb());

		$this->assertNull($mapper->findByOrgAndPeriod('org-1', new DateTime('2026-05-06 07:00:00')));
		$this->assertNull($mapper->findByOrgAndPeriod('org-1', new DateTime('2026-05-06 08:00:00')));
	}//end testMissingOrAmbiguousRowIsNull()
}//end class
