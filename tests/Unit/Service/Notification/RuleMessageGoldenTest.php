<?php

/**
 * Golden of how a rule's subject and message are rendered.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating;
use OCP\Activity\IManager as IActivityManager;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Records each template and locale against the text it renders to, with and
 * without actions. Re-record with UPDATE_GOLDEN=1.
 *
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating
 */
final class RuleMessageGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/rule-message-golden.json';

	private const DATA = ['title' => 'Offerte', 'subject' => ['en' => 'Call client', 'nl' => 'Bel klant']];

	/**
	 * The templates.
	 *
	 * @return array<string, mixed>
	 */
	private static function templates(): array {
		return [
			'null' => null,
			'number' => 7,
			'empty string' => '',
			'empty map' => [],
			'plain' => 'Hello {{title}}',
			'translated placeholder' => 'Task {{subject}}',
			'nl en' => ['nl' => 'NL {{title}}', 'en' => 'EN {{title}}'],
			'en only' => ['en' => 'EN only'],
			'nl only' => ['nl' => 'NL only'],
			'default de' => ['defaultLocale' => 'de', 'de' => 'DE', 'nl' => 'NL', 'fr' => 'FR'],
			'default missing' => ['defaultLocale' => 'de', 'fr' => 'FR', 'en' => 'EN'],
			'default not a string' => ['defaultLocale' => 5, 'fr' => 'FR'],
			'only other locale' => ['fr' => 'FR', 'es' => 'ES'],
			'only the meta key' => ['defaultLocale' => 'nl'],
			'locale not a string' => ['fr' => ['x'], 'nl' => 'NL'],
			'nothing usable' => ['fr' => ['x'], 'es' => 5],
			'empty text under a locale' => ['fr' => '', 'nl' => 'NL'],
			'numeric keys' => ['first', 'second'],
		];
	}//end templates()

	public function testRenderingMatchesGolden(): void {
		$actual = [];
		foreach (self::templates() as $name => $template) {
			foreach ([null, 'fr', 'nl', 'en', 'de'] as $locale) {
				$key = $name . ' / ' . ($locale ?? 'broadcast');
				$actual[$key] = [
					'subject' => $this->subject($template, $locale),
					'body' => $this->body($template, $locale, false),
					'body with actions' => $this->body($template, $locale, true),
				];
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertNotEmpty($actual['plain / nl']['subject']);
		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), $actual);
	}//end testRenderingMatchesGolden()

	private function subject(mixed $template, ?string $locale): string {
		$method = new ReflectionMethod(AnnotationNotificationDispatcher::class, 'resolveLocalizedSubject');
		return $method->invoke($this->dispatcher(), $template, $locale, self::DATA, [], 'rule-name');
	}//end subject()

	private function body(mixed $template, ?string $locale, bool $hasActions): string {
		$method = new ReflectionMethod(AnnotationNotificationDispatcher::class, 'resolveMessageBody');
		return $method->invoke($this->dispatcher(), $template, $locale, self::DATA, [], $hasActions, 'openregister');
	}//end body()

	private function dispatcher(): AnnotationNotificationDispatcher {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppInfo')->willReturn(['name' => 'Open Register']);
		$logger = $this->createMock(LoggerInterface::class);

		$dispatcher = new AnnotationNotificationDispatcher(
			schemaMapper: $this->createMock(SchemaMapper::class),
			notificationManager: $this->createMock(INotificationManager::class),
			logger: $logger,
			groupManager: $this->createMock(IGroupManager::class),
			userManager: $this->createMock(IUserManager::class),
			mailer: $this->createMock(IMailer::class),
			activityManager: $this->createMock(IActivityManager::class),
			httpClient: $this->createMock(IClientService::class),
			serverContainer: $this->createMock(IServerContainer::class),
			appManager: $appManager,
			templating: new NotificationTemplating(logger: $logger)
		);
		(new ReflectionProperty(AnnotationNotificationDispatcher::class, 'registerLanguages'))->setValue($dispatcher, ['nl', 'en']);

		return $dispatcher;
	}//end dispatcher()
}//end class
