<?php

/**
 * Regression: a notification's Open action led to the pipelinq dashboard.
 *
 * Seen on cloud.conduction.nl on 9 October 2026: pipelinq's "Client changed"
 * notification carried the Open action
 * `/index.php/apps/pipelinq#/registers/20/schemas/42/objects/<uuid>`. The deep
 * link registry had no pipelinq route, so `buildObjectDetailLink()` fell back
 * to a hash route on the origin app, which pipelinq is not and redirects to
 * its dashboard. The fallback is now OpenRegister's object view, and the
 * registry fills itself when OpenRegister was never booted in the process
 * (a background worker), so the pipelinq route is found there too.
 *
 * The registry, the registration event and the ObjectEntity are real; only
 * the URL generator, the slug mappers and the event dispatcher are doubles.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/notificatie-engine/spec.md#requirement-an-object-notification-must-link-to-the-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCP\Activity\IManager as IActivityManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Locks where an `object-detail` action target leads.
 */
class DispatcherObjectDetailFallbackTest extends TestCase {

	private const BASE = 'https://cloud.example.org';

	protected function setUp(): void {
		DeepLinkRegistryService::reset();
	}//end setUp()

	protected function tearDown(): void {
		DeepLinkRegistryService::reset();
	}//end tearDown()

	/**
	 * A real registry: register 20 = pipelinq, schema 42 = client.
	 *
	 * @param IEventDispatcher|null $dispatcher What the container answers for the event dispatcher.
	 *
	 * @return DeepLinkRegistryService The registry.
	 */
	private function makeRegistry(?IEventDispatcher $dispatcher = null): DeepLinkRegistryService {
		$register = new Register();
		$register->setId(20);
		$register->setSlug('pipelinq');
		$schema = new Schema();
		$schema->setId(42);
		$schema->setSlug('client');

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([$register]);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn([$schema]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				RegisterMapper::class => $registerMapper,
				SchemaMapper::class => $schemaMapper,
				IEventDispatcher::class => $dispatcher,
				default => null,
			}
		);

		return new DeepLinkRegistryService($container, $this->createMock(LoggerInterface::class));
	}//end makeRegistry()

	/**
	 * The dispatcher with a URL generator that answers like a real instance.
	 *
	 * @param DeepLinkRegistryService $registry The registry.
	 *
	 * @return AnnotationNotificationDispatcher The dispatcher.
	 */
	private function makeDispatcher(DeepLinkRegistryService $registry): AnnotationNotificationDispatcher {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route): string => self::BASE . '/index.php/apps/' . explode('.', $route)[0] . '/'
		);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => self::BASE . $url);

		return new AnnotationNotificationDispatcher(
			schemaMapper: $this->createMock(SchemaMapper::class),
			notificationManager: $this->createMock(INotificationManager::class),
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->createMock(IGroupManager::class),
			userManager: $this->createMock(IUserManager::class),
			mailer: $this->createMock(IMailer::class),
			activityManager: $this->createMock(IActivityManager::class),
			httpClient: $this->createMock(IClientService::class),
			serverContainer: $this->createMock(IServerContainer::class),
			urlGenerator: $urlGenerator,
			deepLinkRegistry: $registry
		);
	}//end makeDispatcher()

	/**
	 * Resolve an `object-detail` target for a pipelinq-origin notification.
	 *
	 * @param AnnotationNotificationDispatcher $dispatcher The dispatcher.
	 * @param integer                          $schemaId   The object's schema id.
	 *
	 * @return string|null The link.
	 */
	private function resolveOpen(AnnotationNotificationDispatcher $dispatcher, int $schemaId): ?string {
		$object = new ObjectEntity();
		$object->setRegister('20');
		$object->setSchema((string) $schemaId);
		$object->setUuid('29a4dd68-71a3-40a8-860d-9b53f9701588');

		$method = new ReflectionMethod(AnnotationNotificationDispatcher::class, 'resolveActionTarget');

		return $method->invoke($dispatcher, ['kind' => 'object-detail'], $object, [], 'pipelinq');
	}//end resolveOpen()

	public function testAnUnclaimedSchemaOpensTheObjectInOpenRegister(): void {
		$link = $this->resolveOpen($this->makeDispatcher($this->makeRegistry()), 77);

		$this->assertSame(
			self::BASE . '/index.php/apps/openregister/objects/20/77/29a4dd68-71a3-40a8-860d-9b53f9701588',
			$link
		);
	}//end testAnUnclaimedSchemaOpensTheObjectInOpenRegister()

	public function testAClaimedSchemaOpensTheAppsDetailPage(): void {
		$registry = $this->makeRegistry();
		$registry->register(
			appId: 'pipelinq',
			registerSlug: 'pipelinq',
			schemaSlug: 'client',
			urlTemplate: '/apps/pipelinq/clients/{uuid}'
		);

		$link = $this->resolveOpen($this->makeDispatcher($registry), 42);

		$this->assertSame('/apps/pipelinq/clients/29a4dd68-71a3-40a8-860d-9b53f9701588', $link);
	}//end testAClaimedSchemaOpensTheAppsDetailPage()

	public function testInABackgroundJobTheRegistryAsksTheAppsItself(): void {
		// No boot() ran in this process, so nobody dispatched the event. The
		// dispatcher double hands the real event to pipelinq's registration,
		// as Nextcloud's dispatcher hands it to a listener registered in
		// pipelinq's register().
		$dispatched = 0;
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use (&$dispatched): void {
				$dispatched++;
				if ($event instanceof DeepLinkRegistrationEvent === true) {
					$event->register(
						appId: 'pipelinq',
						registerSlug: 'pipelinq',
						schemaSlug: 'client',
						urlTemplate: '/apps/pipelinq/clients/{uuid}'
					);
				}
			}
		);

		$dispatcher = $this->makeDispatcher($this->makeRegistry($events));

		$this->assertSame('/apps/pipelinq/clients/29a4dd68-71a3-40a8-860d-9b53f9701588', $this->resolveOpen($dispatcher, 42));
		$this->assertSame('/apps/pipelinq/clients/29a4dd68-71a3-40a8-860d-9b53f9701588', $this->resolveOpen($dispatcher, 42));
		$this->assertSame(1, $dispatched, 'the apps are asked once per process');
	}//end testInABackgroundJobTheRegistryAsksTheAppsItself()
}//end class
