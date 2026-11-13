<?php

/**
 * A special-right grant stores a bare group unless it is scoped.
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

use OCA\OpenRegister\Service\Rbac\SpecialRightGrant;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\SpecialRightGrant
 */
class SpecialRightGrantTest extends TestCase {

	public function testAnUnscopedGrantIsTheGroupAlone(): void {
		$grant = new SpecialRightGrant(right: 'manage', group: 'admins');

		$this->assertSame('admins', $grant->entry());
		$this->assertSame('manage', $grant->right);
	}

	public function testARegisterScopedGrantNamesTheRegister(): void {
		$grant = new SpecialRightGrant(right: 'manage', group: 'clerks', register: 'zaken');

		$this->assertSame(['group' => 'clerks', 'register' => 'zaken'], $grant->entry());
	}

	public function testASchemaScopedGrantListsTheSchemasReindexed(): void {
		$grant = new SpecialRightGrant(right: 'manage', group: 'clerks', schemas: [3 => 'case', 9 => 'task']);

		$this->assertSame(['group' => 'clerks', 'schemas' => ['case', 'task']], $grant->entry());
	}

	public function testAFullyScopedGrantCarriesBoth(): void {
		$grant = new SpecialRightGrant(right: 'manage', group: 'clerks', register: 'zaken', schemas: []);

		$this->assertSame(['group' => 'clerks', 'register' => 'zaken', 'schemas' => []], $grant->entry());
	}
}
