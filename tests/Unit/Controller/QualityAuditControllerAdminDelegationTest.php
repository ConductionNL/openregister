<?php

/**
 * Every quality audit endpoint stays delegated through the OpenRegister admin panel.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\QualityAuditController;
use OCA\OpenRegister\Settings\OpenRegisterAdmin;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The controller names the setting by its Kernel id; the security middleware must still read the panel class.
 *
 * @covers \OCA\OpenRegister\Controller\QualityAuditController
 */
class QualityAuditControllerAdminDelegationTest extends TestCase {

	/**
	 * The quality audit endpoints, each the controller's own public method.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function endpoints(): array {
		$endpoints = [];
		foreach ((new ReflectionClass(objectOrClass: QualityAuditController::class))->getMethods(filter: ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() === QualityAuditController::class && $method->isConstructor() === false) {
				$endpoints[$method->getName()] = [$method->getName()];
			}
		}

		return $endpoints;
	}//end endpoints()

	/**
	 * The nine endpoints are all there, so a new one cannot slip past the check below.
	 *
	 * @return void
	 */
	public function testTheNineEndpointsAreChecked(): void {
		$this->assertSame(
			expected: ['index', 'create', 'show', 'update', 'destroy', 'run', 'runs', 'failures', 'failuresCsv'],
			actual: array_keys(self::endpoints())
		);
	}//end testTheNineEndpointsAreChecked()

	/**
	 * The attribute argument the security middleware reads is the admin panel's class name, as before.
	 *
	 * @param string $method The endpoint method.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testTheEndpointIsDelegatedThroughTheAdminPanel(string $method): void {
		$endpoint   = new ReflectionMethod(objectOrMethod: QualityAuditController::class, method: $method);
		$attributes = $endpoint->getAttributes(name: AuthorizedAdminSetting::class);

		$this->assertCount(expectedCount: 1, haystack: $attributes);
		$this->assertSame(expected: ['settings' => OpenRegisterAdmin::class], actual: $attributes[0]->getArguments());
	}//end testTheEndpointIsDelegatedThroughTheAdminPanel()
}//end class
