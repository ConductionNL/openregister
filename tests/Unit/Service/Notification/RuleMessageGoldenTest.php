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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Notifications\Service\Notification\MessageTarget;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating;
use OCA\OpenRegister\Notifications\Service\Notification\RuleFiring;
use OCA\OpenRegister\Notifications\Service\Notification\RuleMessageRenderer;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Records each template and locale against the text it renders to, with and
 * without actions. Re-record with UPDATE_GOLDEN=1.
 *
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\RuleMessageRenderer
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\MessageTarget
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\RuleFiring
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
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
		return $this->renderer()->subject(new MessageTarget($this->firing($template, false), $locale));
	}//end subject()

	private function body(mixed $template, ?string $locale, bool $hasActions): string {
		return $this->renderer()->body(new MessageTarget($this->firing($template, $hasActions), $locale));
	}//end body()

	private function firing(mixed $template, bool $hasActions): RuleFiring {
		$object = new ObjectEntity();
		$object->setObject(self::DATA);
		$firing = new RuleFiring('rule-name', ['subject' => $template, 'message' => $template], $object, new Schema(), [], 'object_created');
		$firing->originApp = 'openregister';
		if ($hasActions === true) {
			$firing->actions = [['url' => 'x']];
		}

		return $firing;
	}//end firing()

	private function renderer(): RuleMessageRenderer {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppInfo')->willReturn(['name' => 'Open Register']);
		$logger = $this->createMock(LoggerInterface::class);

		return new RuleMessageRenderer(new NotificationTemplating(logger: $logger), ['nl', 'en'], $appManager, $logger);
	}//end renderer()
}//end class
