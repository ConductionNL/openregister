<?php

/**
 * Golden test for the rows ScopeAudit::rows() reports.
 *
 * Pins which register and schema pairs are reported (a schema only against
 * the registers that hold it), their order, and the shape of each row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\ScopeAudit;
use PHPUnit\Framework\TestCase;

/**
 * Reported pairs and row shapes, pinned.
 */
class ScopeAuditRowsGoldenTest extends TestCase {

	/**
	 * A register holding these schema ids.
	 *
	 * @param string            $slug    The slug.
	 * @param array<int, mixed> $schemas The schema ids.
	 *
	 * @return Register The register.
	 */
	private static function register(string $slug, array $schemas): Register {
		$register = new Register();
		$register->setSlug($slug);
		$register->setSchemas($schemas);
		$register->setAuthorization(['read' => ['iedereen']]);

		return $register;
	}//end register()

	/**
	 * A schema with an id and a read rule.
	 *
	 * @param int    $id   The id.
	 * @param string $slug The slug.
	 *
	 * @return Schema The schema.
	 */
	private static function schema(int $id, string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setAuthorization(['read' => ['behandelaars'], 'update' => ['behandelaars']]);

		return $schema;
	}//end schema()

	public function testEachSchemaIsReportedOnlyAgainstTheRegistersHoldingIt(): void {
		$rows = (new ScopeAudit())->rows(
			registers: [self::register('zaken', [1, '2']), self::register('leeg', []), self::register('archief', [2])],
			schemas: [self::schema(1, 'zaak'), self::schema(2, 'besluit'), self::schema(3, 'los')]
		);

		$this->assertSame(
			[['zaken', 'zaak'], ['zaken', 'besluit'], ['archief', 'besluit']],
			array_map(static fn (array $row): array => [$row['register'], $row['schema']], $rows)
		);
		$this->assertSame(['register', 'schema', 'holders', 'denied', 'byAction'], array_keys($rows[0]));
		$this->assertSame(['behandelaars'], $rows[0]['byAction']['update']);
		$this->assertSame([], (new ScopeAudit())->rows(registers: [], schemas: [self::schema(1, 'zaak')]));
	}//end testEachSchemaIsReportedOnlyAgainstTheRegistersHoldingIt()
}//end class
