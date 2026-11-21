<?php

/**
 * MailAppScriptListenerGoldenTest
 *
 * Pins the order of MailAppScriptListener's checks and the line it logs for
 * an eligible user, and that a non-Mail page asks nothing further.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/mail-sidebar/spec.md#requirement-mail-app-script-injection-via-event-listener
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Listener\MailAppScriptListener;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden check order and log line of MailAppScriptListener::handle().
 */
final class MailAppScriptListenerGoldenTest extends TestCase {

	/**
	 * Checks made and lines logged, in order.
	 *
	 * @var list<array>
	 */
	private array $events = [];

	/**
	 * Handle a page rendered by the given app for an eligible user.
	 *
	 * @param string $app The app that rendered the page.
	 *
	 * @return void
	 */
	private function handle(string $app): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function () use ($user): IUser {
				$this->events[] = ['getUser'];
				return $user;
			}
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(
			function (string $appId): bool {
				$this->events[] = ['isEnabledForUser', $appId];
				return true;
			}
		);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('findAll')->willReturnCallback(
			function (...$args): array {
				$this->events[] = ['findAll', $args[0], $args[1]];
				return [new Register()];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->events[] = ['debug', $message, $context];
			}
		);
		$response = $this->createMock(TemplateResponse::class);
		$response->method('getApp')->willReturn($app);
		$event = $this->createMock(BeforeTemplateRenderedEvent::class);
		$event->method('getResponse')->willReturn($response);

		(new MailAppScriptListener($apps, $session, $registers, $logger))->handle($event);
	}//end handle()

	/**
	 * An eligible Mail page: user, Mail enabled, a register, then the log line.
	 *
	 * @return void
	 */
	public function testEligibleMailPage(): void {
		$this->handle(app: 'mail');

		self::assertSame(
			[
				['getUser'],
				['isEnabledForUser', 'mail'],
				['findAll', 1, 0],
				['debug', 'Mail sidebar script injected for user {user}', ['user' => 'anna']],
			],
			$this->events
		);
	}//end testEligibleMailPage()

	/**
	 * Another app's page asks nothing further.
	 *
	 * @return void
	 */
	public function testOtherAppsPage(): void {
		$this->handle(app: 'files');

		self::assertSame([], $this->events);
	}//end testOtherAppsPage()
}//end class
