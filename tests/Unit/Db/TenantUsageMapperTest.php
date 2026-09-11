<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Db;

use InvalidArgumentException;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The guard on the per-organisation usage delete.
 *
 * What the delete removes is pinned against a real database by
 * TenantPurgeScopeIntegrationTest. This pins the one thing that needs no
 * database: an empty uuid is refused before any query is built.
 */
class TenantUsageMapperTest extends TestCase {

	/**
	 * An empty or blank uuid never reaches the database.
	 *
	 * @return void
	 */
	public function testDeleteByOrganisationRefusesAnEmptyUuid(): void {
		foreach (['', '   '] as $blank) {
			$db = $this->createMock(IDBConnection::class);
			$db->expects($this->never())->method('getQueryBuilder');

			$mapper = new TenantUsageMapper($db);

			try {
				$mapper->deleteByOrganisation($blank);
				$this->fail('a blank uuid must be refused, not run: "' . $blank . '"');
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString('without an organisation uuid', $e->getMessage());
			}
		}

	}//end testDeleteByOrganisationRefusesAnEmptyUuid()

}//end class
