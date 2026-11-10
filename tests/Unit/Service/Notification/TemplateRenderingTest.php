<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry;
use OCA\OpenRegister\Service\Notification\TemplateCatalogue;
use OCA\OpenRegister\Service\Notification\TemplateRendering;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * A rendering request renders the same text as the three-argument render().
 *
 * @covers \OCA\OpenRegister\Service\Notification\TemplateRendering
 * @covers \OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry
 */
class TemplateRenderingTest extends TestCase {

	/**
	 * A registry over one event with Dutch and English text, and one with none.
	 *
	 * @return NotificationTemplateRegistry
	 */
	private function registry(): NotificationTemplateRegistry {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		return new NotificationTemplateRegistry(
			$config,
			new TemplateCatalogue(
				[
					'e' => ['group' => 'test', 'variables' => ['who' => 'Who']],
					'silent' => ['group' => 'test', 'variables' => []],
				],
				['e' => ['nl' => ['subject' => 'Hallo {{who}}', 'body' => 'Tekst'], 'en' => ['subject' => 'Hello {{who}}', 'body' => 'Text']]]
			)
		);
	}

	public function testTheRequestCarriesWhatItWasGiven(): void {
		$rendering = new TemplateRendering('e', 'nl', ['who' => 'Ada']);

		$this->assertSame('e', $rendering->event);
		$this->assertSame('nl', $rendering->locale);
		$this->assertSame(['who' => 'Ada'], $rendering->variables);
		$this->assertSame([], (new TemplateRendering('e', null))->variables);
	}

	public function testRenderForAnswersLikeRender(): void {
		$registry = $this->registry();

		$this->assertSame(['subject' => 'Hallo Ada', 'body' => 'Tekst'], $registry->renderFor(new TemplateRendering('e', 'nl', ['who' => 'Ada'])));
		$this->assertSame($registry->render('e', 'fr', ['who' => 'Ada']), $registry->renderFor(new TemplateRendering('e', 'fr', ['who' => 'Ada'])));
		$this->assertSame(['subject' => 'Hello {{who}}', 'body' => 'Text'], $registry->renderFor(new TemplateRendering('e', null)));
		$this->assertNull($registry->renderFor(new TemplateRendering('silent', 'en')));
	}
}//end class
