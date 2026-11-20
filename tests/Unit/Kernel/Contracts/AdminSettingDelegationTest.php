<?php

/**
 * The admin setting delegation id is the OpenRegister admin panel's class name, and the controllers that used the class authorise against it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel\Contracts;

use OCA\OpenRegister\Controller\FlowTimerDiagnosticController;
use OCA\OpenRegister\Controller\RegisterDescriptorController;
use OCA\OpenRegister\Controller\RulesController;
use OCA\OpenRegister\Controller\SetupController;
use OCA\OpenRegister\Controller\WorkingCalendarController;
use OCA\OpenRegister\Kernel\Contracts\AdminSettingDelegation;
use OCA\OpenRegister\Settings\OpenRegisterAdmin;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\Settings\IDelegatedSettings;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Kernel\Contracts\AdminSettingDelegation
 */
class AdminSettingDelegationTest extends TestCase {

	/**
	 * The id is exactly the class name Nextcloud records a delegation under.
	 *
	 * @return void
	 */
	public function testTheIdIsTheAdminPanelClassName(): void {
		$this->assertSame(expected: OpenRegisterAdmin::class, actual: AdminSettingDelegation::OPEN_REGISTER_ADMIN);
	}//end testTheIdIsTheAdminPanelClassName()

	/**
	 * The id names a setting that can be delegated.
	 *
	 * @return void
	 */
	public function testTheIdNamesADelegatedSetting(): void {
		$delegated = is_a(object_or_class: AdminSettingDelegation::OPEN_REGISTER_ADMIN, class: IDelegatedSettings::class, allow_string: true);

		$this->assertTrue(condition: $delegated);
	}//end testTheIdNamesADelegatedSetting()

	/**
	 * The admin-only endpoints that name the setting by its id.
	 *
	 * The argument key is the one each attribute is written with: `settings`
	 * when named, `0` when positional.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: int|string}>
	 */
	public static function adminOnlyEndpoints(): array {
		return [
			'term diagnostic'            => [FlowTimerDiagnosticController::class, 'explain', 'settings'],
			'working calendar preview'   => [WorkingCalendarController::class, 'preview', 'settings'],
			'register descriptor import' => [RegisterDescriptorController::class, 'import', 'settings'],
			'setup status'               => [SetupController::class, 'status', 0],
			'setup save config'          => [SetupController::class, 'saveConfig', 0],
			'setup run action'           => [SetupController::class, 'runAction', 0],
			'rules inventory'            => [RulesController::class, 'index', 'settings'],
			'rules switch'               => [RulesController::class, 'setEnabled', 'settings'],
			'rules trial'                => [RulesController::class, 'evaluate', 'settings'],
			'rules replay'               => [RulesController::class, 'replay', 'settings'],
			'rules run log'              => [RulesController::class, 'runs', 'settings'],
		];
	}//end adminOnlyEndpoints()

	/**
	 * The attribute argument the security middleware reads is unchanged.
	 *
	 * @param string     $controller The controller class.
	 * @param string     $method     The endpoint method.
	 * @param int|string $key        The argument key the attribute is written with.
	 *
	 * @return void
	 *
	 * @dataProvider adminOnlyEndpoints
	 */
	public function testTheEndpointIsDelegatedThroughTheAdminPanel(string $controller, string $method, int|string $key): void {
		$endpoint   = new ReflectionMethod(objectOrMethod: $controller, method: $method);
		$attributes = $endpoint->getAttributes(name: AuthorizedAdminSetting::class);

		$this->assertCount(expectedCount: 1, haystack: $attributes);
		$this->assertSame(expected: [$key => OpenRegisterAdmin::class], actual: $attributes[0]->getArguments());
	}//end testTheEndpointIsDelegatedThroughTheAdminPanel()
}//end class
