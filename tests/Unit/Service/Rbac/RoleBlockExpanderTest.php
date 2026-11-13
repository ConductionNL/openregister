<?php

/**
 * Role blocks expand into per-verb group lists, following role inheritance.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
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

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rbac\RoleBlockExpander;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\RoleBlockExpander
 */
class RoleBlockExpanderTest extends TestCase {

	private const NOTHING = ['read' => [], 'create' => [], 'update' => [], 'delete' => []];

	private function quiet(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		return $logger;
	}

	public function testARoleGivesItsGroupsEveryActionOfTheRole(): void {
		$expander = new RoleBlockExpander($this->quiet(), 7);

		$block = $expander->expand(
			['roles' => ['editor' => ['staff', 'leads']]],
			[['name' => 'editor', 'actions' => ['read', 'update']]]
		);

		$this->assertSame(['read' => ['staff', 'leads'], 'update' => ['staff', 'leads']], $block);
	}

	public function testRoleGroupsJoinTheBlocksOwnListsWithoutDuplicates(): void {
		$expander = new RoleBlockExpander($this->quiet(), 7);

		$block = $expander->expand(
			['read' => ['public', 'staff'], 'roles' => ['viewer' => 'staff', 'auditor' => ['audit']]],
			[['name' => 'viewer', 'actions' => ['read']], ['name' => 'auditor', 'actions' => ['read']]]
		);

		$this->assertSame(['read' => ['public', 'staff', 'audit']], $block);
	}

	public function testAnInheritingRoleCarriesItsParentsActionsFirst(): void {
		$expander = new RoleBlockExpander($this->quiet(), 7);

		$block = $expander->expand(
			['roles' => ['manager' => ['bosses']]],
			[
				['name' => 'viewer', 'actions' => ['read']],
				['name' => 'editor', 'extends' => 'viewer', 'actions' => ['update', 'read']],
				['name' => 'manager', 'extends' => ['editor', '', 42, 'missing'], 'actions' => ['delete', 7]],
			]
		);

		$this->assertSame(['read' => ['bosses'], 'update' => ['bosses'], 'delete' => ['bosses']], $block);
	}

	public function testACyclicHierarchyIsCutAndWarned(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())
			->method('warning')
			->with(
				'[PermissionHandler] Cyclic role-hierarchy reference; ignoring repeat',
				$this->callback(static fn (array $context): bool => $context['schemaId'] === 3 && isset($context['roleName']) === true)
			);

		$block = (new RoleBlockExpander($logger, 3))->expand(
			['roles' => ['a' => ['g']]],
			[['name' => 'a', 'extends' => 'b', 'actions' => ['read']], ['name' => 'b', 'extends' => 'a', 'actions' => ['update']]]
		);

		$this->assertSame(['update' => ['g'], 'read' => ['g']], $block);
	}

	public function testAnUnknownRoleGrantsNothingAndIsWarned(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'[PermissionHandler] Unknown role name referenced in authorization',
				$this->callback(static fn (array $context): bool => $context['roleName'] === 'ghost' && $context['schemaId'] === null)
			);

		$block = (new RoleBlockExpander($logger, null))->expand(
			['roles' => ['ghost' => ['staff']]],
			[['name' => 'viewer', 'actions' => ['read']]]
		);

		$this->assertSame(self::NOTHING, $block);
	}

	public function testNoDefinitionsKeepTheRestOfTheBlockAndWarn(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('[PermissionHandler] Schema has role references but register has no role definitions', $this->arrayHasKey('schemaId'));

		$block = (new RoleBlockExpander($logger, 9))->expand(['read' => ['public'], 'roles' => ['editor' => ['staff']]], []);

		$this->assertSame(['read' => ['public']], $block);
	}

	public function testNoDefinitionsAndNothingElseGrantsNothing(): void {
		$block = (new RoleBlockExpander($this->createMock(LoggerInterface::class), 9))->expand(['roles' => ['editor' => ['staff']]], []);

		$this->assertSame(self::NOTHING, $block);
	}

	public function testDefinitionsWithoutANameOrWithNonListActionsAddNothing(): void {
		$expander = new RoleBlockExpander($this->quiet(), 1);

		$block = $expander->expand(
			['create' => ['x'], 'roles' => ['odd' => ['staff']]],
			[['actions' => ['read']], ['name' => 'odd', 'actions' => 'read']]
		);

		$this->assertSame(['create' => ['x']], $block);
	}

	public function testGrantNothingWhenEmptyKeepsANonEmptyBlock(): void {
		$this->assertSame(['read' => []], RoleBlockExpander::grantNothingWhenEmpty(['read' => []]));
		$this->assertSame(self::NOTHING, RoleBlockExpander::grantNothingWhenEmpty([]));
	}
}
