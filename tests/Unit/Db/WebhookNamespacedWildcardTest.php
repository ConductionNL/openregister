<?php

/**
 * A wildcard subscription on namespaced event classes matches, as
 * docs/Features/webhooks.md documents ("OCA\\OpenRegister\\Event\\Object*").
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Backslashes in a namespaced pattern are literal, not escapes.
 */
class WebhookNamespacedWildcardTest extends TestCase {

	/**
	 * Patterns, event classes and whether the webhook receives the event.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public static function cases(): array {
		return [
			'documented prefix wildcard' => ['OCA\\OpenRegister\\Event\\Object*', 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent', true],
			'documented suffix wildcard' => ['OCA\\OpenRegister\\Event\\*CreatedEvent', 'OCA\\OpenRegister\\Event\\SchemaCreatedEvent', true],
			'namespace wildcard' => ['OCA\\OpenRegister\\Event\\*', 'OCA\\OpenRegister\\Event\\RegisterDeletedEvent', true],
			'other namespace is refused' => ['OCA\\OpenRegister\\Event\\Object*', 'OCA\\OtherApp\\Event\\ObjectCreatedEvent', false],
			'other prefix is refused' => ['OCA\\OpenRegister\\Event\\Object*', 'OCA\\OpenRegister\\Event\\SchemaCreatedEvent', false],
		];
	}//end cases()

	/**
	 * Whether a namespaced wildcard subscription receives the event.
	 *
	 * @param string $pattern    The subscribed pattern.
	 * @param string $eventClass The event class.
	 * @param bool   $receives   Whether it is delivered.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testNamespacedWildcard(string $pattern, string $eventClass, bool $receives): void {
		$webhook = new Webhook();
		$webhook->setEventsArray([$pattern]);
		$this->assertSame($receives, $webhook->matchesEvent($eventClass));
	}//end testNamespacedWildcard()
}//end class
