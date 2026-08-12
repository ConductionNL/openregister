<?php

/**
 * Unit tests for TalkObjectSourceProvider.
 *
 * Talk (spreed) lives in another app's namespace, so its service classes are not
 * loadable under the CI runner (php:8.3-cli + OCP stubs, no Talk app). These tests
 * cover the contract that is observable without Talk installed:
 *  - getId() is the stable provider id
 *  - isEnabled() reflects Talk (spreed) app availability
 *  - reads fail closed to an empty list when no user is logged in
 *  - reads fail closed to an empty list when Talk's service classes cannot be
 *    resolved (degrade, never fatal)
 *
 * The live projection/mapping (real Talk conversations) is verified against the
 * deployed instance where the Talk app IS installed.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\TalkObjectSourceProvider;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Test class for TalkObjectSourceProvider.
 */
class TalkObjectSourceProviderTest extends TestCase {
	/**
	 * Build a provider with configurable app-availability and login state.
	 *
	 * @param bool $appThere Whether the Talk (spreed) app is installed.
	 * @param bool $loggedIn Whether a user is logged in.
	 *
	 * @return TalkObjectSourceProvider The provider under test.
	 */
	private function provider(bool $appThere = true, bool $loggedIn = true): TalkObjectSourceProvider {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($appThere);

		$userSession = $this->createMock(IUserSession::class);
		if ($loggedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$userSession->method('getUser')->willReturn($user);
		} else {
			$userSession->method('getUser')->willReturn(null);
		}

		$container = $this->createMock(ContainerInterface::class);

		return new TalkObjectSourceProvider($appManager, $userSession, $container, new NullLogger());
	}//end provider()

	/**
	 * The register/schema pair the provider is bound to.
	 *
	 * @return array{0: Register, 1: Schema} The register and schema.
	 */
	private function binding(): array {
		$register = new Register();
		$register->setId(31);
		$schema = new Schema();
		$schema->setId(310);
		return [$register, $schema];
	}//end binding()

	/**
	 * getId() is the stable provider id.
	 *
	 * @return void
	 */
	public function testGetId(): void {
		$this->assertSame('talk-source', $this->provider()->getId());
	}//end testGetId()

	/**
	 * isEnabled() reflects Talk (spreed) app install-state.
	 *
	 * @return void
	 */
	public function testIsEnabledReflectsApp(): void {
		$this->assertTrue($this->provider(true)->isEnabled());
		$this->assertFalse($this->provider(false)->isEnabled());
	}//end testIsEnabledReflectsApp()

	/**
	 * Reads fail closed to an empty list when no user is logged in.
	 *
	 * @return void
	 */
	public function testFailsClosedWithoutUser(): void {
		[$register, $schema] = $this->binding();
		$provider = $this->provider(true, false);

		$this->assertSame([], $provider->findAll($register, $schema));
		$this->assertSame(0, $provider->count($register, $schema));
		$this->assertNull($provider->find($register, $schema, 'any'));
	}//end testFailsClosedWithoutUser()

	/**
	 * Reads fail closed to an empty list when Talk's service classes cannot be
	 * resolved (the CI runner has no Talk app installed).
	 *
	 * @return void
	 */
	public function testFailsClosedWhenTalkAbsent(): void {
		if (class_exists('OCA\\Talk\\Manager') === true) {
			$this->markTestSkipped('Talk app is present in this environment; mapping is covered by live-verify.');
		}

		[$register, $schema] = $this->binding();
		$provider = $this->provider(true, true);

		$this->assertSame([], $provider->findAll($register, $schema));
		$this->assertSame(0, $provider->count($register, $schema));
		$this->assertNull($provider->find($register, $schema, 'any'));
	}//end testFailsClosedWhenTalkAbsent()
}//end class
