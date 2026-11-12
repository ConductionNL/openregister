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
use OCA\OpenRegister\Notifications\Service\Notification\ActionLinkResolver;
use OCA\OpenRegister\Notifications\Service\Notification\ActionTargetContext;
use OCA\OpenRegister\Notifications\Service\Notification\AppRouteBase;
use OCA\OpenRegister\Notifications\Service\Notification\ObjectDetailLinks;
use OCA\OpenRegister\Notifications\Service\Notification\TaskActionLinks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\ActionLinkResolver
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ActionTargetContext
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\AppRouteBase
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\LinkableUrl
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ObjectDetailLinks
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\TaskActionLinks
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
		$routes = new AppRouteBase();
		$resolver = new ActionLinkResolver(
			routes: $routes,
			tasks: new TaskActionLinks(isRejecting: static fn (string $outcome): bool => false),
			objects: new ObjectDetailLinks(logger: $this->createMock(LoggerInterface::class), routes: $routes)
		);
		$links = $resolver->resolve(
			spec: ['actions' => [['target' => ['kind' => 'url', 'href' => $href]]]],
			context: new ActionTargetContext(object: new ObjectEntity(), data: [], originApp: 'openregister')
		);

		$this->assertSame($expected, ($links[0]['url'] ?? null));
	}//end testAUrlTargetKeepsToWebAndMailLinks()
}//end class
