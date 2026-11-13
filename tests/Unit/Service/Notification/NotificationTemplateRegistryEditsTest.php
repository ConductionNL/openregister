<?php

/**
 * Unit tests for template edits, locale fallback and config keys of the notification template registry.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\TemplateRendering;
use OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry;
use OCA\OpenRegister\Service\Notification\TemplateCatalogue;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Restoring the shipped text, cleaning an edit, unreadable edits, the any-locale fallback and long keys.
 *
 * @covers \OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry
 * @uses \OCA\OpenRegister\Kernel\Contracts\TemplateRendering
 * @uses \OCA\OpenRegister\Service\Notification\TemplateCatalogue
 */
class NotificationTemplateRegistryEditsTest extends TestCase {

	/**
	 * The app values the mocked config holds.
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	/**
	 * A registry over an in-memory config.
	 *
	 * @param array<string, mixed>|null $events  The events.
	 * @param array<string, mixed>|null $shipped The shipped templates.
	 *
	 * @return NotificationTemplateRegistry
	 */
	private function registry(?array $events = null, ?array $shipped = null): NotificationTemplateRegistry {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->values[$key] ?? $default)
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->values[$key] = $value;
			}
		);
		$config->method('deleteAppValue')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->values[$key]);
			}
		);

		return new NotificationTemplateRegistry($config, new TemplateCatalogue($events, $shipped));

	}//end registry()

	/**
	 * Clearing an edit restores the shipped text.
	 *
	 * @return void
	 */
	public function testClearingAnEditRestoresTheShippedText(): void {
		$registry = $this->registry();
		$registry->edit('object_created', ['en' => ['subject' => 'Mine', 'body' => 'B']]);
		$this->assertSame('Mine', $registry->renderFor(rendering: new TemplateRendering(event: 'object_created', locale: 'en'))['subject']);
		$this->assertTrue($registry->hasEdit('object_created'));

		$registry->edit('object_created', null);

		$this->assertFalse($registry->hasEdit('object_created'));
		$this->assertSame([], $this->values);
		$this->assertSame('{{title}} was created', $registry->renderFor(rendering: new TemplateRendering(event: 'object_created', locale: 'en'))['subject']);

	}//end testClearingAnEditRestoresTheShippedText()

	/**
	 * An edit keeps only well-formed locales and fills a missing subject or body with ''.
	 *
	 * @return void
	 */
	public function testAnEditKeepsOnlyWellFormedLocales(): void {
		$registry = $this->registry();
		$registry->edit('object_created', ['' => ['subject' => 'x'], 'nl' => 'text', 'de' => ['subject' => 'S'], 3 => ['body' => 'b']]);

		$this->assertSame(['de' => ['subject' => 'S', 'body' => '']], $registry->templateFor('object_created'));

	}//end testAnEditKeepsOnlyWellFormedLocales()

	/**
	 * An edit without one usable locale is refused and stores nothing.
	 *
	 * @return void
	 */
	public function testAnEditWithoutAUsableLocaleIsRefused(): void {
		$registry = $this->registry();

		try {
			$registry->edit('object_created', ['nl' => 'text']);
			$this->fail('an edit without a locale was accepted');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('A template needs at least one locale with a subject and a body.', $e->getMessage());
		}

		$this->assertSame([], $this->values);

	}//end testAnEditWithoutAUsableLocaleIsRefused()

	/**
	 * A stored edit that is no JSON object, or an empty one, reads as no edit.
	 *
	 * @return void
	 */
	public function testAnUnreadableEditFallsBackToTheShippedText(): void {
		$registry = $this->registry();

		$this->values['notif_tpl/object_created'] = '{broken';
		$this->assertFalse($registry->hasEdit('object_created'));
		$this->assertSame('{{title}} was created', $registry->renderFor(rendering: new TemplateRendering(event: 'object_created', locale: 'en'))['subject']);

		$this->values['notif_tpl/object_created'] = '[]';
		$this->assertFalse($registry->hasEdit('object_created'));

	}//end testAnUnreadableEditFallsBackToTheShippedText()

	/**
	 * Without the reader's locale and without the default, the first locale there is renders.
	 *
	 * @return void
	 */
	public function testAnyLocaleRendersWhenNeitherTheReadersNorTheDefaultExists(): void {
		$registry = $this->registry(
			['e' => ['group' => 't', 'variables' => []]],
			['e' => ['de' => ['subject' => 'Hallo {{who}}', 'body' => 'Text']]]
		);

		$this->assertSame(['subject' => 'Hallo Ada', 'body' => 'Text'], $registry->renderFor(rendering: new TemplateRendering(event: 'e', locale: 'fr', variables: ['who' => 'Ada'])));
		$this->assertSame(['subject' => 'Hallo {{who}}', 'body' => 'Text'], $registry->renderFor(rendering: new TemplateRendering(event: 'e', locale: null)));

	}//end testAnyLocaleRendersWhenNeitherTheReadersNorTheDefaultExists()

	/**
	 * A template whose locales hold no text renders nothing.
	 *
	 * @return void
	 */
	public function testATemplateWithoutTextRendersNothing(): void {
		$registry = $this->registry(['e' => ['group' => 't', 'variables' => []]], ['e' => ['de' => 'Hallo']]);

		$this->assertNull($registry->renderFor(rendering: new TemplateRendering(event: 'e', locale: 'de')));

	}//end testATemplateWithoutTextRendersNothing()

	/**
	 * Every listed event names where its text came from: edited, shipped or none.
	 *
	 * @return void
	 */
	public function testListAllNamesTheSourceOfEachText(): void {
		$registry = $this->registry(
			[
				'a' => ['group' => 't', 'variables' => []],
				'b' => ['group' => 't', 'variables' => []],
				'c' => ['group' => 't', 'variables' => []],
			],
			['a' => ['en' => ['subject' => 's', 'body' => 'b']], 'b' => ['en' => ['subject' => 's', 'body' => 'b']]]
		);
		$registry->edit('b', ['en' => ['subject' => 'e', 'body' => 'e']]);

		$sources = array_column($registry->listAll(), 'source', 'event');
		$hasText = array_column($registry->listAll(), 'hasTemplate', 'event');

		$this->assertSame(['a' => 'shipped', 'b' => 'edited', 'c' => 'none'], $sources);
		$this->assertSame(['a' => true, 'b' => true, 'c' => false], $hasText);
		$this->assertSame(['c'], $registry->gaps());

	}//end testListAllNamesTheSourceOfEachText()

	/**
	 * A long event name is stored under a hashed key that fits the 64-character limit and stays distinct.
	 *
	 * @return void
	 */
	public function testALongEventNameGetsAHashedKeyWithinTheLimit(): void {
		$long  = str_repeat('very_long_event_name_', 4) . 'one';
		$other = str_repeat('very_long_event_name_', 4) . 'two';
		$registry = $this->registry(
			[$long => ['group' => 't', 'variables' => []], $other => ['group' => 't', 'variables' => []]],
			[]
		);

		$registry->edit($long, ['en' => ['subject' => '1', 'body' => '']]);
		$registry->edit($other, ['en' => ['subject' => '2', 'body' => '']]);

		$this->assertCount(2, $this->values);
		foreach (array_keys($this->values) as $key) {
			$this->assertSame(64, strlen($key));
			$this->assertStringStartsWith('notif_tpl/very_long_event_name_', $key);
			$this->assertMatchesRegularExpression('/~[0-9a-f]{16}$/', $key);
		}

		$this->assertSame('1', $registry->renderFor(rendering: new TemplateRendering(event: $long, locale: 'en'))['subject']);
		$this->assertSame('2', $registry->renderFor(rendering: new TemplateRendering(event: $other, locale: 'en'))['subject']);

	}//end testALongEventNameGetsAHashedKeyWithinTheLimit()
}//end class
