<?php

/**
 * The seam both external send paths ask before they mail.
 *
 * The event is integriq's contract class (tests/stubs/Integriq/Event, a
 * verbatim copy of integriq#2533), dispatched through a dispatcher that runs a
 * stub listener, so the question and its answer cross the real event object.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/opt-out-before-send/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\Integriq\Event\OutboundSendDecisionRequestedEvent;
use OCA\OpenRegister\Service\Notification\OptOutAuthority;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Notification\OptOutAuthority
 */
class OptOutAuthorityTest extends TestCase {

	/**
	 * What the stub listener does: 'answer', 'ignore' or 'throw'.
	 */
	private string $listener = 'answer';

	/**
	 * Addresses the stub listener reports as opted out.
	 *
	 * @var array<int, string>
	 */
	private array $optedOut = [];

	/**
	 * Every event the dispatcher saw.
	 *
	 * @var array<int, Event>
	 */
	private array $events = [];

	private string $checkValue = 'true';

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function dispatcher(): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->events[] = $event;
				if (($event instanceof OutboundSendDecisionRequestedEvent) === false) {
					return;
				}

				if ($this->listener === 'throw') {
					throw new RuntimeException('integriq table unreadable');
				}

				if ($this->listener === 'ignore') {
					return;
				}

				foreach ($event->getRecipients() as $recipient) {
					$address = (string)$recipient['address'];
					if (in_array($address, $this->optedOut, true) === true) {
						$event->setDecision($address, ['send' => false, 'overridden' => false, 'code' => 'opted-out', 'reason' => 'x', 'unsubscribe' => null]);
						continue;
					}

					$event->setDecision(
						$address,
						[
							'send' => true,
							'overridden' => false,
							'code' => 'allowed',
							'reason' => '',
							'unsubscribe' => ['url' => 'https://nc.example/u/' . md5($address), 'oneClickUrl' => 'https://nc.example/u/' . md5($address), 'smsText' => null, 'headers' => []],
						]
					);
				}

				$event->setHandled(true);
			}
		);

		return $dispatcher;
	}

	private function authority(bool $integriqInstalled = true): OptOutAuthority {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($key === OptOutAuthority::CONFIG_KEY ? $this->checkValue : $default)
		);

		if ($integriqInstalled === true) {
			return new OptOutAuthority(eventDispatcher: $this->dispatcher(), appConfig: $appConfig, logger: $this->logger);
		}

		return new class($this->dispatcher(), $appConfig, $this->logger) extends OptOutAuthority {
			protected function resolveEventClass(): ?string {
				return null;
			}
		};
	}

	public function testAnOptedOutAddressIsRefusedAndTheOtherCarriesItsLink(): void {
		$this->optedOut = ['jan@example.nl'];

		$decisions = $this->authority()->ask('email', 'service', ['jan@example.nl', 'piet@example.nl'], 'flow-run:7');

		$this->assertFalse($decisions['jan@example.nl']['send']);
		$this->assertSame('opted-out', $decisions['jan@example.nl']['code']);
		$this->assertTrue($decisions['piet@example.nl']['send']);
		$this->assertSame('https://nc.example/u/' . md5('piet@example.nl'), $decisions['piet@example.nl']['unsubscribe']['oneClickUrl']);

		// One question for the batch, carrying the contract fields.
		$this->assertCount(1, $this->events);
		$event = $this->events[0];
		$this->assertInstanceOf(OutboundSendDecisionRequestedEvent::class, $event);
		$this->assertSame('openregister', $event->getSourceApp());
		$this->assertSame('email', $event->getChannel());
		$this->assertSame('service', $event->getCategory());
		$this->assertSame('flow-run:7', $event->getCorrelationId());
		$this->assertFalse($event->requiresConsent());
		$this->assertSame([['address' => 'jan@example.nl'], ['address' => 'piet@example.nl']], $event->getRecipients());
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function absentCases(): array {
		return [
			'class missing' => ['missing', false],
			'event unhandled' => ['ignore', true],
			'listener throws' => ['throw', true],
		];
	}

	/**
	 * @dataProvider absentCases
	 */
	public function testWithoutAnAnswerAServiceMailIsRefused(string $listener, bool $installed): void {
		$this->listener = $listener;
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$decisions = $this->authority(integriqInstalled: $installed)->ask('email', 'service', ['jan@example.nl']);

		$this->assertSame(['jan@example.nl' => ['send' => false, 'code' => 'authority-unavailable', 'unsubscribe' => null]], $decisions);
	}

	/**
	 * @dataProvider absentCases
	 */
	public function testWithoutAnAnswerABesluitIsSentWithoutALink(string $listener, bool $installed): void {
		$this->listener = $listener;

		$decisions = $this->authority(integriqInstalled: $installed)->ask('email', 'besluit', ['jan@example.nl']);

		$this->assertSame(['jan@example.nl' => ['send' => true, 'code' => 'exempt', 'unsubscribe' => null]], $decisions);
	}

	public function testAnUnknownCategoryIsAskedAsServiceAndIsNeverExempt(): void {
		$this->listener = 'ignore';

		$decisions = $this->authority()->ask('email', 'nieuwsbrief', ['jan@example.nl']);

		$this->assertSame('service', $this->events[0]->getCategory());
		$this->assertFalse($decisions['jan@example.nl']['send']);
	}

	public function testMarketingAsksForConsent(): void {
		$this->authority()->ask('email', 'marketing', ['jan@example.nl']);

		$this->assertTrue($this->events[0]->requiresConsent());
	}

	public function testOnlyTheLiteralFalseTurnsTheCheckOff(): void {
		$this->checkValue = 'false';
		$this->listener = 'ignore';

		$decisions = $this->authority()->ask('email', 'service', ['jan@example.nl']);

		$this->assertSame([], $this->events);
		$this->assertTrue($decisions['jan@example.nl']['send']);

		$this->checkValue = 'no';
		$this->assertFalse($this->authority()->ask('email', 'service', ['jan@example.nl'])['jan@example.nl']['send']);
	}

	public function testNoAddressesAsksNothing(): void {
		$this->assertSame([], $this->authority()->ask('email', 'service', []));
		$this->assertSame([], $this->events);
	}

	public function testTheLinkLineIsAppendedOnlyWhenThereIsALink(): void {
		$authority = $this->authority();

		$this->assertSame("Hello\n\nStop receiving these messages: https://nc.example/u/x", $authority->withLink("Hello\n", ['url' => 'https://nc.example/u/x']));
		$this->assertSame('Hello', $authority->withLink('Hello', null));
	}
}
