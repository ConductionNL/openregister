<?php

/**
 * The push client script is only loaded for a signed-in user on a rendered page.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\PushClientScriptListener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Util;
use ReflectionProperty;
use PHPUnit\Framework\TestCase;

/**
 * A signed-in page queues the client once; a foreign event or an anonymous page queues nothing.
 */
class PushClientScriptListenerTest extends TestCase {
	/**
	 * The page script queue before the test, restored afterwards.
	 *
	 * @var array<string, mixed>
	 */
	private array $savedScripts = [];

	protected function setUp(): void {
		$this->savedScripts = $this->scripts()->getValue();
	}

	protected function tearDown(): void {
		$this->scripts()->setValue(null, $this->savedScripts);
	}

	private function scripts(): ReflectionProperty {
		return new ReflectionProperty(Util::class, 'scripts');
	}

	/**
	 * The scripts queued for openregister.
	 *
	 * @return list<string> The queued paths.
	 */
	private function queued(): array {
		return ($this->scripts()->getValue()['openregister'] ?? []);
	}

	private function page(): BeforeTemplateRenderedEvent {
		return new BeforeTemplateRenderedEvent(true, $this->createMock(TemplateResponse::class));
	}

	public function testASignedInPageQueuesThePushClientOnce(): void {
		$before  = $this->queued();
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		(new PushClientScriptListener($session))->handle($this->page());

		$client = 'openregister/js/../js/openregister-push-client';
		$count  = fn (array $paths): int => count(array_keys($paths, $client, true));
		$this->assertSame($count($before) + 1, $count($this->queued()));
		$this->assertSame($client, $this->queued()[array_key_last($this->queued())]);
	}

	public function testAForeignEventDoesNotConsultTheSession(): void {
		$session = $this->createMock(IUserSession::class);
		$session->expects($this->never())->method('getUser');

		(new PushClientScriptListener($session))->handle(new Event());
	}

	public function testAnAnonymousPageAsksForTheUserAndStopsThere(): void {
		$session = $this->createMock(IUserSession::class);
		$session->expects($this->once())->method('getUser')->willReturn(null);
		$before = $this->queued();

		(new PushClientScriptListener($session))->handle($this->page());

		$this->assertSame($before, $this->queued());
	}
}
