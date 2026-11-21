<?php

/**
 * AuthorizationCapability: the scope kinds in the capabilities document.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Capabilities
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/access-owner-and-condition-scopes/specs/authorization-rbac/spec.md#requirement-the-capabilities-document-states-the-enforced-scope-kinds
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Capabilities;

use OCA\OpenRegister\Capabilities\AuthorizationCapability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the authorization capability.
 */
#[CoversClass(AuthorizationCapability::class)]
class AuthorizationCapabilityTest extends TestCase {

	/**
	 * The document lists group, creator and condition under openregister.authorization.scopes.
	 *
	 * @return void
	 */
	public function testTheThreeKindsAreAdvertised(): void {
		$this->assertSame(
			['openregister' => ['authorization' => ['scopes' => ['group', 'creator', 'condition']]]],
			(new AuthorizationCapability())->getCapabilities()
		);
	}
}
