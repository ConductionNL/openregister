<?php

/**
 * The OAS reads a roles block the way enforcement does.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\EffectiveAuthorization;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\EffectiveAuthorization
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class EffectiveAuthorizationTest extends TestCase {

	/**
	 * The effective authorization of a schema whose block names roles.
	 *
	 * @param array<string, mixed>      $block    The schema's authorization block.
	 * @param array<int, mixed>|null    $roles    The register's role definitions; null for no register.
	 *
	 * @return array|null The answer.
	 */
	private function forSchema(array $block, ?array $roles): ?array {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setAuthorization($block);

		$mapper = $this->createMock(RegisterMapper::class);
		if ($roles === null) {
			$mapper->method('getFirstRegisterWithSchema')->willReturn(null);
		} else {
			$register = new Register();
			$register->setId(1);
			$register->setConfiguration(['roles' => $roles]);
			$mapper->method('getFirstRegisterWithSchema')->willReturn(1);
			$mapper->method('find')->willReturn($register);
		}

		return (new EffectiveAuthorization(registerMapper: $mapper))->forSchema(schema: $schema);
	}//end forSchema()

	/**
	 * A roles block over a register without role definitions grants nobody anything.
	 *
	 * @return void
	 */
	public function testARolesBlockWithoutRoleDefinitionsGrantsNothing(): void {
		$expected = ['read' => [], 'create' => [], 'update' => [], 'delete' => []];

		$this->assertSame($expected, $this->forSchema(block: ['roles' => ['viewer' => ['staff']]], roles: []));
		$this->assertSame($expected, $this->forSchema(block: ['roles' => ['viewer' => ['staff']]], roles: null));
	}//end testARolesBlockWithoutRoleDefinitionsGrantsNothing()

	/**
	 * A roles block whose every name is unknown grants nobody anything.
	 *
	 * @return void
	 */
	public function testARolesBlockWithOnlyUnknownRolesGrantsNothing(): void {
		$answer = $this->forSchema(
			block: ['roles' => ['ghost' => ['staff']]],
			roles: [['name' => 'viewer', 'actions' => ['read']]]
		);

		$this->assertSame(['read' => [], 'create' => [], 'update' => [], 'delete' => []], $answer);
	}//end testARolesBlockWithOnlyUnknownRolesGrantsNothing()

	/**
	 * A roles block that resolves still expands to the verbs of its roles.
	 *
	 * @return void
	 */
	public function testARolesBlockThatResolvesIsExpanded(): void {
		$answer = $this->forSchema(
			block: ['roles' => ['viewer' => ['staff']]],
			roles: [['name' => 'viewer', 'actions' => ['read']]]
		);

		$this->assertSame(['read' => ['staff']], $answer);
	}//end testARolesBlockThatResolvesIsExpanded()
}//end class
