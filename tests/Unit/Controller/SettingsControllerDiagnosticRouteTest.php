<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SettingsController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The posture Nextcloud's middleware reads from the diagnostic route.
 *
 * @coversNothing
 */
class SettingsControllerDiagnosticRouteTest extends TestCase {
	/**
	 * The route stays behind the default admin check.
	 *
	 * @return void
	 */
	public function testDiagnosticRouteRequiresAnAdmin(): void {
		$method = new ReflectionMethod(SettingsController::class, 'debugTypeFiltering');

		$this->assertDoesNotMatchRegularExpression('/^\s*\*\s*@NoAdminRequired\b/m', (string)$method->getDocComment());
		$this->assertSame([], $method->getAttributes(NoAdminRequired::class));
	}
}
