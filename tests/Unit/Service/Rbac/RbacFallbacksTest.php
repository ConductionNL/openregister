<?php

/**
 * The Rbac fallbacks build the default collaborators when none is injected.
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

use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\RbacFallbacks;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\RbacFallbacks
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 */
class RbacFallbacksTest extends TestCase {

	public function testTheObjectScopeFallbackIsAFreshResolver(): void {
		$first = RbacFallbacks::objectScope();

		$this->assertInstanceOf(ObjectScopeResolver::class, $first);
		$this->assertNotSame($first, RbacFallbacks::objectScope());
	}

	public function testTheDenyEnforcementFallbackReadsTheGivenConfig(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->atLeastOnce())
			->method('getValueString')
			->with('openregister', 'deny_enforcement', 'staging')
			->willReturn(' Enforcing ');

		$mode = RbacFallbacks::denyEnforcement($appConfig, $this->createMock(LoggerInterface::class));

		$this->assertInstanceOf(DenyEnforcementMode::class, $mode);
		$this->assertTrue($mode->enforces());
	}
}
