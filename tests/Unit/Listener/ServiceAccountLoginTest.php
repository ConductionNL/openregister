<?php

/**
 * A service account cannot sign in interactively (REQ-SAT-004).
 *
 * Driven with Nextcloud's real PostLoginEvent, the event Session::completeLogin()
 * dispatches for every sign-in before it decides whether an app cancelled it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\ServiceAccountLoginListener;
use OCA\OpenRegister\Service\Authorization\MachineCredentialService;
use OCP\EventDispatcher\Event;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use OCP\User\Events\PostLoginEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for {@see ServiceAccountLoginListener}.
 *
 * @covers \OCA\OpenRegister\Listener\ServiceAccountLoginListener
 * @uses   \OCA\OpenRegister\Service\Authorization\MachineCredentialService
 */
class ServiceAccountLoginTest extends TestCase {

	private IUserSession&MockObject $session;
	private LoggerInterface&MockObject $logger;
	private ServiceAccountLoginListener $listener;

	protected function setUp(): void {
		// The real service over a user config where only `svc-sync` carries the marker.
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $uid, string $app, string $key, string $default = ''): string => ($uid === 'svc-sync' && $app === 'openregister' && $key === 'service_account') ? '1' : $default
		);
		$accounts = new MachineCredentialService(
			users: $this->createMock(IUserManager::class),
			groups: $this->createMock(IGroupManager::class),
			config: $config,
			random: $this->createMock(ISecureRandom::class)
		);

		$this->session  = $this->createMock(IUserSession::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
		$this->listener = new ServiceAccountLoginListener(accounts: $accounts, userSession: $this->session, logger: $this->logger);
	}

	/**
	 * The real sign-in event for a user.
	 *
	 * @param string $uid          The user id.
	 * @param bool   $isTokenLogin Whether an app token was used.
	 *
	 * @return PostLoginEvent The event.
	 */
	private function signIn(string $uid, bool $isTokenLogin): PostLoginEvent {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return new PostLoginEvent($user, $uid, 'secret', $isTokenLogin);
	}

	/**
	 * 🔴 An interactive sign-in of a service account is cancelled, and reported.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public function testInteractiveLoginIsRefused(): void {
		$this->session->expects(self::once())->method('logout');
		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains('Refused an interactive sign-in'), ['uid' => 'svc-sync']);

		$this->listener->handle($this->signIn(uid: 'svc-sync', isTokenLogin: false));
	}

	/**
	 * A token sign-in of a service account is how its integration works, and is let through.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public function testATokenLoginIsLetThrough(): void {
		$this->session->expects(self::never())->method('logout');

		$this->listener->handle($this->signIn(uid: 'svc-sync', isTokenLogin: true));
	}

	/**
	 * An ordinary user's sign-in is none of this listener's business.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public function testAnOrdinaryUserSignsInAsBefore(): void {
		$this->session->expects(self::never())->method('logout');
		$this->logger->expects(self::never())->method('warning');

		$this->listener->handle($this->signIn(uid: 'jan', isTokenLogin: false));
	}

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public function testOtherEventsAreIgnored(): void {
		$this->session->expects(self::never())->method('logout');

		$this->listener->handle(new Event());
	}
}
