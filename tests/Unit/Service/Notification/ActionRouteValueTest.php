<?php

/**
 * A value in a route action arrives as the object holds it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

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
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ObjectDetailLinks
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\TaskActionLinks
 */
final class ActionRouteValueTest extends TestCase {

	public static function values(): array {
		return [
			'ampersand' => ['a&b'],
			'quote' => ["o'brien"],
			'markup' => ['<b>x</b>'],
			'slash' => ['a/b'],
			'space' => ['a b'],
		];
	}//end values()

	#[DataProvider('values')]
	public function testAValueDecodesToWhatTheObjectHolds(string $value): void {
		$routes = new AppRouteBase();
		$resolver = new ActionLinkResolver(
			routes: $routes,
			tasks: new TaskActionLinks(isRejecting: static fn (string $outcome): bool => false),
			objects: new ObjectDetailLinks(logger: $this->createMock(LoggerInterface::class), routes: $routes)
		);

		$links = $resolver->resolve(
			spec: ['actions' => [['target' => ['kind' => 'route', 'route' => '/clients/{{name}}']]]],
			context: new ActionTargetContext(object: new ObjectEntity(), data: ['name' => $value], originApp: 'pipelinq')
		);

		$segment = substr($links[0]['url'], strlen('/index.php/apps/pipelinq/clients/'));
		$this->assertStringNotContainsString('/', $segment);
		$this->assertSame($value, rawurldecode($segment));
	}//end testAValueDecodesToWhatTheObjectHolds()
}//end class
