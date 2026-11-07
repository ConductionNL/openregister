<?php

/**
 * Golden test for how the two permission reports read role definitions.
 *
 * ObjectPermissionsResolver and ProvenanceResolver both flatten a register's
 * role definitions, and both accept two shapes: the stored list of `{name,
 * actions}` definitions and the flat `name => actions` map the hierarchy
 * resolver produces. Pins what each reports for both shapes, malformed entries
 * included.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ObjectPermissionsResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\ObjectPermissionsResolver
 */
final class RoleActionsGoldenTest extends TestCase {

	/**
	 * Both role-definition shapes grant the verbs they list; malformed entries grant nothing.
	 *
	 * @return void
	 */
	public function testBothRoleShapesGrantTheirVerbs(): void {
		$blocks = ['schema' => ['roles' => ['behandelaar' => ['behandelaars'], 'lezer' => ['lezers'], 'kapot' => ['niemand']]]];
		$resolver = new ObjectPermissionsResolver();

		$stored = $resolver->holders(
			blocks: $blocks,
			roleDefinitions: [
				['name' => 'behandelaar', 'actions' => ['read', 'update', 7]],
				['name' => 'kapot', 'actions' => 'read'],
				'lezer',
			]
		);
		$flat = $resolver->holders(
			blocks: $blocks,
			roleDefinitions: ['behandelaar' => ['read', 'update', null], 'lezer' => ['read'], 'kapot' => 'read']
		);

		$this->assertSame(['behandelaars' => ['read', 'update']], $this->verbsByPrincipal($stored));
		$this->assertSame(['behandelaars' => ['read', 'update'], 'lezers' => ['read']], $this->verbsByPrincipal($flat));
		$this->assertSame([], $this->verbsByPrincipal($resolver->holders(blocks: $blocks, roleDefinitions: 'roles')));
	}

	/**
	 * The verbs each reported principal holds.
	 *
	 * @param array $set The holder set.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function verbsByPrincipal(array $set): array {
		$out = [];
		foreach ($set['holders'] as $holder) {
			$out[$holder['principal']] = $holder['verbs'];
		}

		return $out;
	}
}
