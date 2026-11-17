<?php

/**
 * Tests for app-declared webhook events.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Event;

require_once __DIR__ . '/Fixtures/AppWebhookEventFixtures.php';

use OCA\AppWebhookFixture\NotAnEvent;
use OCA\AppWebhookFixture\ThingArchivedEvent;
use OCA\AppWebhookFixture\ThingSignedEvent;
use OCA\OpenRegister\Controller\WebhooksController;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Event\AppWebhookEvents;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Listener\WebhookEventListener;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\WebhooksControllerFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(AppWebhookEvents::class)]
#[UsesClass(WebhookEventListener::class)]
#[UsesClass(WebhooksController::class)]
class AppWebhookEventsTest extends TestCase {

	/**
	 * Counter that gives each test its own request token.
	 *
	 * @var integer
	 */
	private static int $request = 0;

	/**
	 * Whether REQUEST_TIME_FLOAT was set before the test.
	 *
	 * @var boolean
	 */
	private bool $hadRequestTime = false;

	/**
	 * REQUEST_TIME_FLOAT before the test.
	 *
	 * @var mixed
	 */
	private mixed $requestTime = null;

	protected function setUp(): void {
		parent::setUp();
		$this->hadRequestTime = array_key_exists('REQUEST_TIME_FLOAT', $_SERVER);
		$this->requestTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
		// Each test is its own request, so the registry starts empty.
		$_SERVER['REQUEST_TIME_FLOAT'] = 'app-webhook-events-' . (++self::$request);
	}

	protected function tearDown(): void {
		// Later tests read the request time as a number (WritePhaseProbe), so the token must not leak.
		if ($this->hadRequestTime === true) {
			$_SERVER['REQUEST_TIME_FLOAT'] = $this->requestTime;
		} else {
			unset($_SERVER['REQUEST_TIME_FLOAT']);
		}

		parent::tearDown();
	}

	private function declareSigned(IEventDispatcher $dispatcher, string $payload = 'getPayload'): bool {
		return AppWebhookEvents::declare(
			dispatcher: $dispatcher,
			event: ThingSignedEvent::class,
			name: 'Thing signed',
			description: 'A thing was signed',
			category: 'Things',
			payload: $payload,
		);
	}

	public function testADeclaredEventSubscribesTheWebhookListenerOnce(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())
			->method('addServiceListener')
			->with(ThingSignedEvent::class, WebhookEventListener::class);

		$this->assertTrue($this->declareSigned($dispatcher));
		$this->assertTrue($this->declareSigned($dispatcher));
		$this->assertCount(1, AppWebhookEvents::catalogue());
	}

	public function testTheCatalogueListsDeclarationsInOrder(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$this->declareSigned($dispatcher);
		AppWebhookEvents::declare(
			dispatcher: $dispatcher,
			event: ThingArchivedEvent::class,
			name: 'Thing archived',
			description: 'A thing was archived',
			category: 'Things',
		);

		$this->assertSame(
			[
				[
					'class' => ThingSignedEvent::class,
					'name' => 'Thing signed',
					'description' => 'A thing was signed',
					'category' => 'Things',
					'type' => 'after',
					'properties' => ['payload'],
				],
				[
					'class' => ThingArchivedEvent::class,
					'name' => 'Thing archived',
					'description' => 'A thing was archived',
					'category' => 'Things',
					'type' => 'after',
					'properties' => ['payload'],
				],
			],
			AppWebhookEvents::catalogue()
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refusedDeclarations(): array {
		return [
			'missing class' => ['OCA\\AppWebhookFixture\\NoSuchEvent', 'getPayload'],
			'not an event' => [NotAnEvent::class, 'getPayload'],
			'openregister namespace' => [ObjectCreatedEvent::class, 'getObject'],
			'leading backslash openregister' => ['\\' . ObjectCreatedEvent::class, 'getObject'],
			'missing method' => [ThingSignedEvent::class, 'getNothing'],
			'method needs an argument' => [ThingSignedEvent::class, 'payloadFor'],
			'protected method' => [ThingSignedEvent::class, 'hidden'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('refusedDeclarations')]
	public function testARefusedDeclarationSubscribesNothing(string $event, string $payload): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('addServiceListener');

		$declared = AppWebhookEvents::declare(
			dispatcher: $dispatcher,
			event: $event,
			name: 'x',
			description: 'x',
			category: 'x',
			payload: $payload,
		);

		$this->assertFalse($declared);
		$this->assertSame([], AppWebhookEvents::catalogue());
	}

	public function testPayloadOfReturnsTheDeclaredMethodsArray(): void {
		$this->declareSigned($this->createMock(IEventDispatcher::class));

		$event = new ThingSignedEvent(['event' => 'thing.signed', 'id' => 7]);

		$this->assertSame(['event' => 'thing.signed', 'id' => 7], AppWebhookEvents::payloadOf(event: $event));
	}

	public function testPayloadOfAnUndeclaredEventIsNull(): void {
		$this->declareSigned($this->createMock(IEventDispatcher::class));

		// A subclass is a different class, and is not declared.
		$this->assertNull(AppWebhookEvents::payloadOf(event: new ThingArchivedEvent(['id' => 1])));
	}

	public function testPayloadOfANonArrayReturnIsNull(): void {
		$this->declareSigned($this->createMock(IEventDispatcher::class), 'describe');

		$this->assertNull(AppWebhookEvents::payloadOf(event: new ThingSignedEvent(['id' => 1])));
	}

	public function testANewRequestStartsWithoutDeclarations(): void {
		$this->declareSigned($this->createMock(IEventDispatcher::class));
		$_SERVER['REQUEST_TIME_FLOAT'] = 'app-webhook-events-next-request';

		$this->assertSame([], AppWebhookEvents::catalogue());
		$this->assertNull(AppWebhookEvents::payloadOf(event: new ThingSignedEvent(['id' => 1])));
	}

	public function testTheListenerDeliversADeclaredEventWithItsOwnPayload(): void {
		$this->declareSigned($this->createMock(IEventDispatcher::class));
		$webhookService = $this->createMock(WebhookService::class);
		$webhookService->method('hasWebhooksFor')->with(ThingSignedEvent::class)->willReturn(true);
		$webhookService->expects($this->once())
			->method('dispatchEvent')
			->with(ThingSignedEvent::class, ['event' => 'thing.signed', 'id' => 7]);
		$listener = new WebhookEventListener($webhookService, $this->createMock(LoggerInterface::class));

		$listener->handle(new ThingSignedEvent(['event' => 'thing.signed', 'id' => 7]));
	}

	public function testTheListenerDropsAnUndeclaredAppEventWithAWarning(): void {
		$webhookService = $this->createMock(WebhookService::class);
		$webhookService->method('hasWebhooksFor')->willReturn(true);
		$webhookService->expects($this->never())->method('dispatchEvent');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$listener = new WebhookEventListener($webhookService, $logger);

		$listener->handle(new ThingSignedEvent(['id' => 7]));
	}

	public function testTheEventsEndpointAppendsDeclaredEventsToTheBuiltInOnes(): void {
		$controller = WebhooksControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(WebhookMapper::class),
			$this->createMock(WebhookLogMapper::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);
		$builtIn = $controller->events()->getData()['events'];

		$this->declareSigned($this->createMock(IEventDispatcher::class));
		$data = $controller->events()->getData();

		$this->assertSame(count($builtIn) + 1, $data['total']);
		$this->assertSame($builtIn, array_slice($data['events'], 0, count($builtIn)));
		$this->assertSame(ThingSignedEvent::class, $data['events'][count($builtIn)]['class']);
		$this->assertSame('Thing signed', $data['events'][count($builtIn)]['name']);
	}
}
