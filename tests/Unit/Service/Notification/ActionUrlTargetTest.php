<?php

/**
 * A `url` action target keeps to web and mail links.
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
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher
 */
final class ActionUrlTargetTest extends TestCase {

	public static function targets(): array {
		return [
			'https' => ['https://example.test/call', 'https://example.test/call'],
			'http' => ['http://example.test/call', 'http://example.test/call'],
			'upper case scheme' => ['HTTPS://example.test/call', 'HTTPS://example.test/call'],
			'mail' => ['mailto:info@example.test', 'mailto:info@example.test'],
			'other scheme' => ['custom://example.test/call', null],
			'file transfer' => ['ftp://example.test/call', null],
			'script' => ['javascript://example.test/call', null],
			'not a url' => ['call', null],
			'no host' => ['http://', null],
			'broken host' => ['http://exa mple.test/call', null],
			'phone is no url' => ['tel:+31201234567', null],
		];
	}//end targets()

	#[DataProvider('targets')]
	public function testAUrlTargetKeepsToWebAndMailLinks(string $href, ?string $expected): void {
		$dispatcher = (new ReflectionClass(AnnotationNotificationDispatcher::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod(AnnotationNotificationDispatcher::class, 'resolveActionTarget');

		$this->assertSame($expected, $method->invoke($dispatcher, ['kind' => 'url', 'href' => $href], new ObjectEntity(), [], 'openregister'));
	}//end testAUrlTargetKeepsToWebAndMailLinks()
}//end class
