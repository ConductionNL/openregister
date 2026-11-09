<?php

/**
 * Golden pin for OpenRegister's own action-matrix repair step.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\InitializeFlowActions;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins what InitializeFlowActions seeds into OpenRegister's action matrix, and
 * which users the seeded matrix then allows and refuses.
 */
final class InitializeFlowActionsGoldenTest extends TestCase {

	/**
	 * The in-memory app config store, keyed "app/key".
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * A temporary app directory holding the seed.
	 *
	 * @var string
	 */
	private string $appDir = '';

	/**
	 * Remove the temporary seed directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->appDir !== '' && is_dir($this->appDir) === true) {
			@unlink($this->appDir . '/lib/actions.seed.json');
			@rmdir($this->appDir . '/lib');
			@rmdir($this->appDir);
		}

	}//end tearDown()

	/**
	 * OpenRegister's action-auth service over the in-memory store.
	 *
	 * @param bool          $admin  Whether the judged user is an admin.
	 * @param array<string> $groups The judged user's groups.
	 *
	 * @return OpenRegisterActionAuthService
	 */
	private function actionAuth(bool $admin, array $groups): OpenRegisterActionAuthService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->store[$app . '/' . $key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$app . '/' . $key] = $value;
				return true;
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($admin);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new OpenRegisterActionAuthService($appConfig, $groupManager);

	}//end actionAuth()

	/**
	 * Run the repair step over a seed.
	 *
	 * @param array<string, array<int, string>> $actions The seeded actions.
	 *
	 * @return void
	 */
	private function runSeed(array $actions): void {
		$this->appDir = sys_get_temp_dir() . '/or-flow-actions-' . bin2hex(random_bytes(4));
		mkdir($this->appDir . '/lib', 0777, true);
		file_put_contents($this->appDir . '/lib/actions.seed.json', json_encode(['actions' => $actions]));

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn($this->appDir);

		$step = new InitializeFlowActions($this->actionAuth(false, []), $appManager, $this->createMock(LoggerInterface::class));
		$this->assertSame('Initialize openregister action-authorization matrix (ADR-023)', $step->getName());
		$step->run($this->createMock(IOutput::class));

	}//end runSeed()

	/**
	 * Whether the given user may run an action under the stored matrix.
	 *
	 * @param bool          $admin  Whether the user is an admin.
	 * @param array<string> $groups The user's groups.
	 * @param string        $action The action.
	 *
	 * @return bool
	 */
	private function can(bool $admin, array $groups, string $action): bool {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		return $this->actionAuth($admin, $groups)->can($user, $action);

	}//end can()

	/**
	 * A fresh install stores the seed under openregister's own app id.
	 *
	 * @return void
	 */
	public function testSeedsTheMatrixUnderOpenRegister(): void {
		$this->runSeed(['flow.run' => ['admin', 'editors'], 'flow.edit' => ['admin']]);

		$this->assertSame(
			['openregister/actions' => '{"flow.run":["admin","editors"],"flow.edit":["admin"]}'],
			$this->store
		);

	}//end testSeedsTheMatrixUnderOpenRegister()

	/**
	 * Existing grants survive; only missing actions are added.
	 *
	 * @return void
	 */
	public function testKeepsExistingGrantsAndAddsMissingActions(): void {
		$this->store['openregister/actions'] = '{"flow.run":["@authenticated"]}';
		$this->runSeed(['flow.run' => ['admin'], 'flow.edit' => ['admin', 'editors']]);

		$this->assertSame('{"flow.run":["@authenticated"],"flow.edit":["admin","editors"]}', $this->store['openregister/actions']);

	}//end testKeepsExistingGrantsAndAddsMissingActions()

	/**
	 * The seeded matrix allows admins and listed groups, refuses everyone else.
	 *
	 * @return void
	 */
	public function testTheSeededMatrixAllowsAndRefuses(): void {
		$this->runSeed(['flow.run' => ['admin', 'editors'], 'flow.edit' => ['admin']]);

		$this->assertTrue($this->can(true, [], 'flow.edit'));
		$this->assertTrue($this->can(false, ['editors'], 'flow.run'));
		$this->assertFalse($this->can(false, ['editors'], 'flow.edit'));
		$this->assertFalse($this->can(false, ['viewers'], 'flow.run'));
		$this->assertFalse($this->can(false, ['editors'], 'flow.unknown'));

	}//end testTheSeededMatrixAllowsAndRefuses()
}//end class
