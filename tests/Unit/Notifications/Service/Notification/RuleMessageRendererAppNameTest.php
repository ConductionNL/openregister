<?php

/**
 * Unit tests for RuleMessageRenderer's default body: the app it names, read from the app's info.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Notifications\Service\Notification\MessageTarget;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating;
use OCA\OpenRegister\Notifications\Service\Notification\RuleFiring;
use OCA\OpenRegister\Notifications\Service\Notification\RuleMessageRenderer;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\RuleMessageRenderer
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\MessageTarget
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\RuleFiring
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\NotificationRunTally
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class RuleMessageRendererAppNameTest extends TestCase {

	/**
	 * What reached the debug log.
	 *
	 * @var array<int, string>
	 */
	private array $debugged = [];

	private function body(?IAppManager $appManager, string $originApp = 'decidiq'): string {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(function (string $message): void {
			$this->debugged[] = $message;
		});
		$renderer = new RuleMessageRenderer(new NotificationTemplating(logger: $logger), ['nl'], $appManager, $logger);
		$firing = new RuleFiring('rule-1', [], new ObjectEntity(), new Schema(), [], 'object_created');
		$firing->originApp = $originApp;
		$firing->actions = [['url' => '/apps/decidiq/x']];

		return $renderer->body(new MessageTarget($firing, 'nl'));
	}

	public function testTheBodyNamesTheAppByItsDeclaredName(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->once())->method('getAppInfo')->with('decidiq')->willReturn(['name' => 'DecidIQ']);

		$this->assertSame('Open in DecidIQ.', $this->body($apps));
	}

	public function testWithoutAnAppManagerTheIdIsCapitalised(): void {
		$this->assertSame('Open in Decidiq.', $this->body(null));
	}

	public function testANameThatIsNotAStringFallsBackToTheId(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppInfo')->willReturn(['name' => ['en' => 'DecidIQ']]);

		$this->assertSame('Open in Decidiq.', $this->body($apps));
	}

	public function testAFailingLookupFallsBackToTheIdAndIsLogged(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppInfo')->willThrowException(new RuntimeException('no info.xml'));

		$this->assertSame('Open in Decidiq.', $this->body($apps));
		$this->assertSame(['[AnnotationNotificationDispatcher] app display-name lookup failed: no info.xml'], $this->debugged);
	}

	public function testAnEmptyOriginAppIsNotLookedUp(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->never())->method('getAppInfo');

		$this->assertSame('Open in .', $this->body($apps, ''));
	}
}
