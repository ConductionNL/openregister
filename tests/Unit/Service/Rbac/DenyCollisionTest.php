<?php

/**
 * A deny collision explains, in one sentence, a rule that grants and denies the same verb.
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

use OCA\OpenRegister\Service\Rbac\DenyCollision;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\DenyCollision
 */
class DenyCollisionTest extends TestCase {

	public function testTheSentenceNamesSubjectVerbAndPrincipalInBothPlaces(): void {
		$collision = new DenyCollision(action: 'update', principal: 'group:staff');

		$this->assertSame(
			'The authorization of schema "cases" both grants and denies "update" to "group:staff" at the same level: '
			.'the rule "group:staff" in update and the rule "group:staff" in update.deny. '
			.'Remove one of the two; a deny wins, so leaving both stored would grant nothing and read as a grant.',
			$collision->sentence('schema "cases"')
		);
	}

	public function testTheFieldsAreKept(): void {
		$collision = new DenyCollision(action: 'read', principal: 'user:alice');

		$this->assertSame('read', $collision->action);
		$this->assertSame('user:alice', $collision->principal);
		$this->assertStringContainsString('register "zaken"', $collision->sentence('register "zaken"'));
	}
}
