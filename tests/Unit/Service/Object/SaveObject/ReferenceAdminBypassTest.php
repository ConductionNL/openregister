<?php

/**
 * Who skips reference validation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Service\Object\SaveObject\ReferenceAdminBypass;
use OCA\OpenRegister\Service\Rbac\AdminGate;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Every condition of the bypass, one at a time.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ReferenceAdminBypass
 * @uses   \OCA\OpenRegister\Service\Rbac\AdminGate
 */
final class ReferenceAdminBypassTest extends TestCase {

	/**
	 * A session holding one user, or none.
	 *
	 * @param string|null $uid The user id.
	 *
	 * @return IUserSession The session.
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return $session;
	}//end session()

	/**
	 * The app config holding the flag.
	 *
	 * @param string $value The stored value.
	 *
	 * @return IAppConfig The config, answering only for the bypass key and its default.
	 */
	private function config(string $value): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')
			->with('openregister', 'reference_validation_admin_bypass', 'true')
			->willReturn($value);

		return $config;
	}//end config()

	/**
	 * A group manager knowing one administrator.
	 *
	 * @return IGroupManager The manager.
	 */
	private function admins(): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');

		return $groups;
	}//end admins()

	/**
	 * An administrator skips validation while the flag is on.
	 *
	 * @return void
	 */
	public function testAnAdministratorSkipsValidation(): void {
		$bypass = new ReferenceAdminBypass(new AdminGate($this->session('admin'), $this->admins()), $this->config('true'));

		$this->assertTrue($bypass->applies());
	}//end testAnAdministratorSkipsValidation()

	/**
	 * Any other user is validated.
	 *
	 * @return void
	 */
	public function testAnotherUserIsValidated(): void {
		$bypass = new ReferenceAdminBypass(new AdminGate($this->session('alice'), $this->admins()), $this->config('true'));

		$this->assertFalse($bypass->applies());
	}//end testAnotherUserIsValidated()

	/**
	 * With the flag off an administrator is validated too.
	 *
	 * @return void
	 */
	public function testTheFlagOffValidatesAnAdministrator(): void {
		$bypass = new ReferenceAdminBypass(new AdminGate($this->session('admin'), $this->admins()), $this->config('false'));

		$this->assertFalse($bypass->applies());
	}//end testTheFlagOffValidatesAnAdministrator()

	/**
	 * Without a user there is nobody to bypass for.
	 *
	 * @return void
	 */
	public function testNoUserIsValidated(): void {
		$bypass = new ReferenceAdminBypass(new AdminGate($this->session(null), $this->admins()), $this->config('true'));

		$this->assertFalse($bypass->applies());
	}//end testNoUserIsValidated()

	/**
	 * A missing group manager means validation runs.
	 *
	 * @return void
	 */
	public function testNoGroupManagerValidates(): void {
		$bypass = new ReferenceAdminBypass(new AdminGate($this->session('admin'), null), $this->config('true'));

		$this->assertFalse($bypass->applies());
	}//end testNoGroupManagerValidates()

	/**
	 * A missing app config means validation runs.
	 *
	 * @return void
	 */
	public function testNoAppConfigValidates(): void {
		$bypass = new ReferenceAdminBypass(new AdminGate($this->session('admin'), $this->admins()), null);

		$this->assertFalse($bypass->applies());
	}//end testNoAppConfigValidates()
}//end class
