<?php

/**
 * GenericInitializeActionsTest: a seeded action reaches an instance that already has a matrix.
 *
 * The repair step used to write the seed only into an EMPTY matrix. Every
 * instance that ran it once kept that first matrix forever, so an action added
 * to the seed later (`flow.read`) never arrived: an unlisted action is
 * admin-only, and every non-admin flow author got 403 on the version history.
 *
 * These tests pin the merge: a seeded action the stored matrix lacks is added,
 * and an entry the stored matrix already has is never touched, because that
 * entry may be an admin's narrowing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Repair\GenericInitializeActions;
use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\AppHost\Repair\GenericInitializeActions
 * @uses \OCA\OpenRegister\AppHost\Service\GenericActionAuthService
 */
class GenericInitializeActionsTest extends TestCase {

	/**
	 * The in-memory app config store, keyed "app/key".
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * A temporary app directory holding a hand-written seed, or '' when unused.
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
	 * A REAL action-auth service over an in-memory app config.
	 *
	 * @param bool $admin Whether the user the service judges is an admin.
	 *
	 * @return GenericActionAuthService
	 */
	private function actionAuth(bool $admin=false): GenericActionAuthService {
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
		$groupManager->method('getUserGroupIds')->willReturn([]);

		return new GenericActionAuthService('openregister', $appConfig, $groupManager);

	}//end actionAuth()

	/**
	 * The repair step, reading its seed from the given app directory.
	 *
	 * @param GenericActionAuthService $actionAuth The action-auth service.
	 * @param string $appPath The app directory holding lib/actions.seed.json.
	 *
	 * @return GenericInitializeActions
	 */
	private function repair(GenericActionAuthService $actionAuth, string $appPath): GenericInitializeActions {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn($appPath);

		return new GenericInitializeActions(
			'openregister',
			$actionAuth,
			$appManager,
			$this->createMock(LoggerInterface::class)
		);

	}//end repair()

	/**
	 * Write a seed file into a fresh temporary app directory.
	 *
	 * @param array<string, array<int, string>> $actions The seeded actions.
	 *
	 * @return string The app directory.
	 */
	private function seedDir(array $actions): string {
		$this->appDir = sys_get_temp_dir() . '/or-seed-test-' . bin2hex(random_bytes(6));
		mkdir($this->appDir . '/lib', 0777, true);
		file_put_contents($this->appDir . '/lib/actions.seed.json', json_encode(['actions' => $actions]));

		return $this->appDir;

	}//end seedDir()

	/**
	 * A signed-in non-admin.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		return $user;

	}//end user()

	/**
	 * Control: an empty matrix is seeded from the file, as it always was.
	 *
	 * @return void
	 */
	public function testAnEmptyMatrixIsSeededFromTheFile(): void {
		$actionAuth = $this->actionAuth();
		$dir        = $this->seedDir(['item.publish' => ['@authenticated'], 'item.purge' => ['admin']]);

		$this->repair($actionAuth, $dir)->run($this->createMock(IOutput::class));

		$this->assertSame(
			['item.publish' => ['@authenticated'], 'item.purge' => ['admin']],
			$actionAuth->getMatrix()
		);

	}//end testAnEmptyMatrixIsSeededFromTheFile()

	/**
	 * THE DEFECT: an action added to the seed after the first run never arrived.
	 *
	 * @return void
	 */
	public function testAnExistingMatrixGainsASeededActionItLacks(): void {
		$actionAuth = $this->actionAuth();
		$actionAuth->setMatrix(['item.publish' => ['@authenticated']]);
		$dir = $this->seedDir(['item.publish' => ['@authenticated'], 'item.read' => ['@authenticated']]);

		$this->repair($actionAuth, $dir)->run($this->createMock(IOutput::class));

		$this->assertSame(['@authenticated'], ($actionAuth->getMatrix()['item.read'] ?? null));

	}//end testAnExistingMatrixGainsASeededActionItLacks()

	/**
	 * An entry the stored matrix already has is never overwritten by the seed.
	 *
	 * An admin who narrowed a right to a group, or to admin-only, keeps that.
	 *
	 * @return void
	 */
	public function testAnEntryAlreadyStoredIsNeverOverwritten(): void {
		$actionAuth = $this->actionAuth();
		$actionAuth->setMatrix(['item.publish' => ['editors'], 'item.purge' => ['admin']]);
		$dir = $this->seedDir(
			[
				'item.publish' => ['@authenticated'],
				'item.purge'   => ['@authenticated'],
				'item.read'    => ['@authenticated'],
			]
		);

		$this->repair($actionAuth, $dir)->run($this->createMock(IOutput::class));

		$matrix = $actionAuth->getMatrix();
		$this->assertSame(['editors'], $matrix['item.publish']);
		$this->assertSame(['admin'], $matrix['item.purge']);
		$this->assertSame(['@authenticated'], $matrix['item.read']);

	}//end testAnEntryAlreadyStoredIsNeverOverwritten()

	/**
	 * The shipped seed gives an instance that predates `flow.read` the right.
	 *
	 * The stored matrix is exactly what the first seeding wrote: the four flow
	 * rights and `object.correct`. After the repair a non-admin flow author may
	 * read a flow's versions, as the flow-engine spec promises.
	 *
	 * @return void
	 */
	public function testTheShippedSeedGrantsFlowReadToAnInstanceThatPredatesIt(): void {
		$actionAuth = $this->actionAuth();
		$actionAuth->setMatrix(
			[
				'flow.create'    => ['@authenticated'],
				'flow.update'    => ['@authenticated'],
				'flow.delete'    => ['@authenticated'],
				'flow.run'       => ['@authenticated'],
				'object.correct' => ['admin'],
			]
		);

		$this->repair($actionAuth, dirname(__DIR__, 3))->run($this->createMock(IOutput::class));

		$this->assertTrue(
			$actionAuth->can(user: $this->user(), action: 'flow.read'),
			'a non-admin flow author must be able to read a flow\'s versions after the upgrade'
		);
		$this->assertSame(['admin'], $actionAuth->getMatrix()['object.correct']);

	}//end testTheShippedSeedGrantsFlowReadToAnInstanceThatPredatesIt()

}//end class
