<?php

/**
 * Regression: an object notification carried no link.
 *
 * Seen on cloud.conduction.nl on 8 October 2026: pipelinq's "Client changed"
 * notification arrived with `link: ""`, so clicking it went nowhere.
 * `AnnotationNotifier::prepare()` never called setLink(). It now links to the
 * owning app's detail page through the deep link registry, else to
 * OpenRegister's object view, and the implicit View action follows it.
 *
 * The registry here is the real DeepLinkRegistryService, fed through its own
 * register() call; only the container that resolves slugs is a double. The
 * notification double refuses a relative link the way Nextcloud's
 * Notification::setLink() and Action::setLink() do.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/order-filters-and-notification-links/specs/notificatie-engine/spec.md#requirement-an-object-notification-must-link-to-the-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notification;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Notification\AnnotationNotifier;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Locks the link an object notification carries.
 */
class AnnotationNotifierLinkTest extends TestCase {

	private const BASE = 'https://cloud.example.org';

	/**
	 * The link the notification was given, or null.
	 */
	private ?string $notificationLink = null;

	/**
	 * Every action link set, as [label, link].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $actionLinks = [];

	protected function setUp(): void {
		DeepLinkRegistryService::reset();
		$this->notificationLink = null;
		$this->actionLinks = [];
	}//end setUp()

	protected function tearDown(): void {
		DeepLinkRegistryService::reset();
	}//end tearDown()

	/**
	 * A real registry whose slug lookups answer register 7 = pipelinq, schema 12 = client.
	 *
	 * @return DeepLinkRegistryService The registry.
	 */
	private function makeRegistry(): DeepLinkRegistryService {
		$register = new Register();
		$register->setId(7);
		$register->setSlug('pipelinq');
		$schema = new Schema();
		$schema->setId(12);
		$schema->setSlug('client');

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([$register]);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn([$schema]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[RegisterMapper::class, $registerMapper],
				[SchemaMapper::class, $schemaMapper],
			]
		);

		return new DeepLinkRegistryService($container, $this->createMock(LoggerInterface::class));
	}//end makeRegistry()

	/**
	 * Build the notifier on a URL generator that answers like a real instance.
	 *
	 * @param DeepLinkRegistryService|null $registry The registry, or null for none.
	 *
	 * @return AnnotationNotifier The notifier.
	 */
	private function makeNotifier(?DeepLinkRegistryService $registry): AnnotationNotifier {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturn('/custom_apps/openregister/img/app.svg');
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => self::BASE . $url);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route): string => $route === 'openregister.dashboard.page'
				? self::BASE . '/index.php/apps/openregister/'
				: self::BASE . '/index.php/apps/openregister/route/' . $route
		);

		return new AnnotationNotifier($factory, $urlGenerator, $registry);
	}//end makeNotifier()

	/**
	 * A notification double that refuses a relative link, as Nextcloud does.
	 *
	 * @param array<string, mixed> $params The subject parameters.
	 *
	 * @return INotification The notification double.
	 */
	private function makeNotification(array $params): INotification {
		$refuseRelative = static function (string $link): void {
			if (str_starts_with($link, 'http://') === false && str_starts_with($link, 'https://') === false) {
				throw new InvalidArgumentException('link');
			}
		};

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('openregister');
		$notification->method('getSubject')->willReturn('object_updated');
		$notification->method('getSubjectParameters')->willReturn($params);
		$notification->method('setParsedSubject')->willReturnSelf();
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('addAction')->willReturnSelf();
		$notification->method('setLink')->willReturnCallback(
			function (string $link) use ($notification, $refuseRelative) {
				$refuseRelative($link);
				$this->notificationLink = $link;
				return $notification;
			}
		);
		$notification->method('createAction')->willReturnCallback(
			function () use ($refuseRelative): IAction {
				$label = '';
				$action = $this->createMock(IAction::class);
				$action->method('setLabel')->willReturnCallback(
					function (string $value) use ($action, &$label) {
						$label = $value;
						return $action;
					}
				);
				$action->method('setPrimary')->willReturnSelf();
				$action->method('setLink')->willReturnCallback(
					function (string $link) use ($action, $refuseRelative, &$label) {
						$refuseRelative($link);
						$this->actionLinks[] = [$label, $link];
						return $action;
					}
				);
				return $action;
			}
		);

		return $notification;
	}//end makeNotification()

	public function testAClaimedSchemaLinksToTheOwningApp(): void {
		$registry = $this->makeRegistry();
		$registry->register(
			appId: 'pipelinq',
			registerSlug: 'pipelinq',
			schemaSlug: 'client',
			urlTemplate: '/apps/pipelinq/clients/{uuid}'
		);

		$this->makeNotifier($registry)->prepare(
			$this->makeNotification(
				[
					'_text' => 'Client changed: Acme',
					'registerId' => 7,
					'schemaId' => 12,
					'objectUuid' => 'c-1',
				]
			),
			'en'
		);

		$this->assertSame(self::BASE . '/apps/pipelinq/clients/c-1', $this->notificationLink);
		$this->assertSame([['View', self::BASE . '/apps/pipelinq/clients/c-1']], $this->actionLinks);
	}//end testAClaimedSchemaLinksToTheOwningApp()

	public function testAnUnclaimedSchemaLinksToTheOpenRegisterObjectView(): void {
		$this->makeNotifier($this->makeRegistry())->prepare(
			$this->makeNotification(
				[
					'_text' => 'Schema item changed',
					'registerId' => 3,
					'schemaId' => 4,
					'objectUuid' => 'o-9',
				]
			),
			'en'
		);

		$expected = self::BASE . '/index.php/apps/openregister/#/registers/3/schemas/4/objects/o-9';
		$this->assertSame($expected, $this->notificationLink);
		$this->assertSame([['View', $expected]], $this->actionLinks);
	}//end testAnUnclaimedSchemaLinksToTheOpenRegisterObjectView()

	public function testWithoutARegistryTheLinkStillPointsAtTheObject(): void {
		$this->makeNotifier(null)->prepare(
			$this->makeNotification(['registerId' => '3', 'schemaId' => '4', 'objectUuid' => 'o-9', 'objectTitle' => 'X']),
			'en'
		);

		$this->assertSame(
			self::BASE . '/index.php/apps/openregister/#/registers/3/schemas/4/objects/o-9',
			$this->notificationLink
		);
	}//end testWithoutARegistryTheLinkStillPointsAtTheObject()

	public function testANotificationWithoutAnObjectGetsNoLink(): void {
		$this->makeNotifier($this->makeRegistry())->prepare(
			$this->makeNotification(['_text' => 'Flow message']),
			'en'
		);

		$this->assertNull($this->notificationLink);
		$this->assertSame([], $this->actionLinks);
	}//end testANotificationWithoutAnObjectGetsNoLink()

	public function testADeclaredActionWithARelativeDeepLinkIsMadeAbsolute(): void {
		// The dispatcher hands a registry deep link over as a path. Nextcloud
		// refuses a relative action link, which made prepare() throw.
		$this->makeNotifier($this->makeRegistry())->prepare(
			$this->makeNotification(
				[
					'_text' => 'Lead changed',
					'registerId' => 7,
					'schemaId' => 99,
					'objectUuid' => 'l-1',
					'_actions' => [
						['label' => ['en' => 'Open client'], 'primary' => true, 'url' => '/apps/pipelinq/clients/c-1'],
					],
				]
			),
			'en'
		);

		$this->assertSame([['Open client', self::BASE . '/apps/pipelinq/clients/c-1']], $this->actionLinks);
	}//end testADeclaredActionWithARelativeDeepLinkIsMadeAbsolute()
}//end class
