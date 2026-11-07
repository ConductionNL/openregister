<?php

/**
 * Golden pins for OptOutAuthority::ask(): every answer integriq can give, and none.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

use OCA\Integriq\Event\OutboundSendDecisionRequestedEvent;
use OCA\OpenRegister\Notifications\Service\Notification\OptOutAuthority;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the decisions, the event sent and every log line for each scenario.
 */
class OptOutAuthorityAskGoldenTest extends TestCase {

	/**
	 * Events dispatched and lines logged, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * ask() answers each scenario exactly as pinned.
	 *
	 * @param array<string, mixed> $scenario The question and how integriq answers.
	 * @param array<string, mixed> $expected The pinned transcript.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testAskMatchesTheGolden(array $scenario, array $expected): void {
		self::assertSame($expected, $this->transcript(scenario: $scenario));
	}//end testAskMatchesTheGolden()

	/**
	 * Run one scenario and record the decisions and what happened.
	 *
	 * @param array<string, mixed> $scenario The question and how integriq answers.
	 *
	 * @return array<string, mixed>
	 */
	protected function transcript(array $scenario): array {
		$this->calls = [];
		$decisions   = $this->authority(scenario: $scenario)->ask(
			channel: ($scenario['channel'] ?? 'email'),
			category: $scenario['category'],
			addresses: $scenario['addresses'],
			correlationId: ($scenario['correlationId'] ?? '')
		);

		return ['decisions' => $decisions, 'calls' => $this->calls];
	}//end transcript()

	/**
	 * The authority, with or without integriq's event class.
	 *
	 * @param array<string, mixed> $scenario How collaborators answer.
	 *
	 * @return OptOutAuthority
	 */
	private function authority(array $scenario): OptOutAuthority {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function () use ($scenario): string {
				if (($scenario['config'] ?? 'true') === 'throws') {
					throw new RuntimeException('stored as bool');
				}

				return (string)($scenario['config'] ?? 'true');
			}
		);

		$dispatcher = $this->dispatcher(scenario: $scenario);
		$logger     = $this->logger();
		if (($scenario['installed'] ?? true) === true) {
			return new OptOutAuthority(eventDispatcher: $dispatcher, appConfig: $appConfig, logger: $logger);
		}

		return new class($dispatcher, $appConfig, $logger) extends OptOutAuthority {
			/**
			 * Integriq is not installed.
			 *
			 * @return string|null
			 */
			protected function resolveEventClass(): ?string {
				return null;
			}
		};
	}//end authority()

	/**
	 * A dispatcher that records the event and answers as the scenario says.
	 *
	 * @param array<string, mixed> $scenario How integriq answers.
	 *
	 * @return IEventDispatcher
	 */
	private function dispatcher(array $scenario): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($scenario): void {
				$this->calls[] = ['event', $this->describe(event: $event)];
				$this->answer(event: $event, scenario: $scenario);
			}
		);

		return $dispatcher;
	}//end dispatcher()

	/**
	 * Answer the event as the scenario's listener would.
	 *
	 * @param OutboundSendDecisionRequestedEvent $event    The event.
	 * @param array<string, mixed>               $scenario How integriq answers.
	 *
	 * @return void
	 */
	private function answer(OutboundSendDecisionRequestedEvent $event, array $scenario): void {
		$listener = ($scenario['listener'] ?? 'answer');
		if ($listener === 'throw') {
			throw new RuntimeException('integriq table unreadable');
		}

		if ($listener === 'ignore') {
			return;
		}

		$allowed = ['send' => true, 'code' => 'allowed', 'unsubscribe' => ['url' => 'https://nc.example/u/1']];
		foreach ($event->getRecipients() as $recipient) {
			$address = (string)$recipient['address'];
			$answer  = ($scenario['answers'][$address] ?? $allowed);
			if ($answer !== 'none') {
				$event->setDecision(address: $address, decision: $answer);
			}
		}

		$event->setHandled(handled: ($listener !== 'unhandled'));
	}//end answer()

	/**
	 * What the event carried.
	 *
	 * @param OutboundSendDecisionRequestedEvent $event The event.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(OutboundSendDecisionRequestedEvent $event): array {
		return [
			'sourceApp' => $event->getSourceApp(),
			'channel' => $event->getChannel(),
			'category' => $event->getCategory(),
			'recipients' => $event->getRecipients(),
			'correlationId' => $event->getCorrelationId(),
			'baseUrl' => $event->getBaseUrl(),
			'requiresConsent' => $event->requiresConsent(),
		];
	}//end describe()

	/**
	 * A logger that records every line.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->calls[] = ['log.' . $level, $message, $context];
				}
			);
		}

		return $logger;
	}//end logger()

	/**
	 * The scenarios, without their pins.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function scenarios(): array {
		$two = ['jan@example.nl', 'piet@example.nl'];
		return [
			'no addresses' => ['category' => 'service', 'addresses' => ['', '']],
			'check switched off' => ['category' => 'service', 'addresses' => $two, 'config' => 'FALSE '],
			'unreadable switch stays on' => ['category' => 'service', 'addresses' => $two, 'config' => 'throws'],
			'everyone allowed' => [
				'category' => 'service',
				'addresses' => ['jan@example.nl', 'jan@example.nl', '', 'piet@example.nl'],
				'correlationId' => 'flow-run:7',
			],
			'one opted out' => [
				'category' => 'Marketing',
				'addresses' => $two,
				'answers' => ['jan@example.nl' => ['send' => false, 'code' => 'opted-out', 'unsubscribe' => null]],
			],
			'odd decision fields' => [
				'category' => 'service',
				'addresses' => $two,
				'answers' => [
					'jan@example.nl' => ['send' => 1],
					'piet@example.nl' => ['send' => true, 'code' => 'override', 'unsubscribe' => 'x'],
				],
			],
			'one left unanswered, not exempt' => [
				'category' => 'service',
				'addresses' => $two,
				'correlationId' => 'c-1',
				'answers' => ['piet@example.nl' => 'none'],
			],
			'one left unanswered, exempt' => ['category' => 'besluit', 'addresses' => $two, 'answers' => ['piet@example.nl' => 'none']],
			'listener ignores' => ['category' => 'service', 'addresses' => $two, 'listener' => 'ignore'],
			'listener leaves it unhandled' => ['category' => 'statutory', 'addresses' => $two, 'listener' => 'unhandled'],
			'listener throws' => ['category' => 'unknown', 'addresses' => $two, 'listener' => 'throw', 'channel' => 'sms'],
			'integriq absent, exempt' => ['category' => 'security', 'addresses' => $two, 'installed' => false],
			'integriq absent, not exempt' => ['category' => 'service', 'addresses' => $two, 'installed' => false, 'correlationId' => 'c-2'],
		];
	}//end scenarios()

	/**
	 * Each scenario with its pinned transcript.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (self::scenarios() as $name => $scenario) {
			$cases[$name] = [$scenario, self::pins()[$name]];
		}

		return $cases;
	}//end cases()

	/**
	 * The pinned transcripts.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function pins(): array {
		return [
			'no addresses' => [
				'decisions' => [],
				'calls' => [],
			],
			'check switched off' => [
				'decisions' => [
					'jan@example.nl' => [
						'send' => true,
						'code' => 'check-off',
						'unsubscribe' => null,
					],
					'piet@example.nl' => [
						'send' => true,
						'code' => 'check-off',
						'unsubscribe' => null,
					],
				],
				'calls' => [],
			],
			'unreadable switch stays on' => [
				'decisions' => [
					'jan@example.nl' => [
						'send' => true,
						'code' => 'allowed',
						'unsubscribe' => [
							'url' => 'https://nc.example/u/1',
						],
					],
					'piet@example.nl' => [
						'send' => true,
						'code' => 'allowed',
						'unsubscribe' => [
							'url' => 'https://nc.example/u/1',
						],
					],
				],
				'calls' => [
					[
						'event',
						[
							'sourceApp' => 'openregister',
							'channel' => 'email',
							'category' => 'service',
							'recipients' => [
								[
									'address' => 'jan@example.nl',
								],
								[
									'address' => 'piet@example.nl',
								],
							],
							'correlationId' => '',
							'baseUrl' => '',
							'requiresConsent' => false,
						],
					],
				],
			],
			'everyone allowed' => [
				'decisions' => [
					'jan@example.nl' => [
						'send' => true,
						'code' => 'allowed',
						'unsubscribe' => [
							'url' => 'https://nc.example/u/1',
						],
					],
					'piet@example.nl' => [
						'send' => true,
						'code' => 'allowed',
						'unsubscribe' => [
							'url' => 'https://nc.example/u/1',
						],
					],
				],
				'calls' => [
					[
						'event',
						[
							'sourceApp' => 'openregister',
							'channel' => 'email',
							'category' => 'service',
							'recipients' => [
								[
									'address' => 'jan@example.nl',
								],
								[
									'address' => 'piet@example.nl',
								],
							],
							'correlationId' => 'flow-run:7',
							'baseUrl' => '',
							'requiresConsent' => false,
						],
					],
				],
			],
			'one opted out' => [
				'decisions' => [
					'jan@example.nl' => [
						'send' => false,
						'code' => 'opted-out',
						'unsubscribe' => null,
					],
					'piet@example.nl' => [
						'send' => true,
						'code' => 'allowed',
						'unsubscribe' => [
							'url' => 'https://nc.example/u/1',
						],
					],
				],
				'calls' => [
					[
						'event',
						[
							'sourceApp' => 'openregister',
							'channel' => 'email',
							'category' => 'marketing',
							'recipients' => [
								[
									'address' => 'jan@example.nl',
								],
								[
									'address' => 'piet@example.nl',
								],
							],
							'correlationId' => '',
							'baseUrl' => '',
							'requiresConsent' => true,
						],
					],
				],
			],
			'odd decision fields' => [
				'decisions' => [
					'jan@example.nl' => [
						'send' => false,
						'code' => 'allowed',
						'unsubscribe' => null,
					],
					'piet@example.nl' => [
						'send' => true,
						'code' => 'override',
						'unsubscribe' => null,
					],
				],
				'calls' => [
					[
						'event',
						[
							'sourceApp' => 'openregister',
							'channel' => 'email',
							'category' => 'service',
							'recipients' => [
								[
									'address' => 'jan@example.nl',
								],
								[
									'address' => 'piet@example.nl',
								],
							],
							'correlationId' => '',
							'baseUrl' => '',
							'requiresConsent' => false,
						],
					],
				],
			],
			'one left unanswered, not exempt' => [
				'decisions' => [
					'jan@example.nl' => [
						'send' => true,
						'code' => 'allowed',
						'unsubscribe' => [
							'url' => 'https://nc.example/u/1',
						],
					],
					'piet@example.nl' => [
						'send' => false,
						'code' => 'authority-unavailable',
						'unsubscribe' => null,
					],
				],
				'calls' => [
					[
						'event',
						[
							'sourceApp' => 'openregister',
							'channel' => 'email',
							'category' => 'service',
							'recipients' => [
								[
									'address' => 'jan@example.nl',
								],
								[
									'address' => 'piet@example.nl',
								],
							],
							'correlationId' => 'c-1',
							'baseUrl' => '',
							'requiresConsent' => false,
						],
					],
					[
						'log.warning',
						'[OptOutAuthority] no answer from integriq; refusing a non-exempt send (authority-unavailab'
							.'le)',
						[
							'category' => 'service',
							'channel' => 'email',
							'addresses' => 1,
							'code' => 'authority-unavailable',
							'correlationId' => 'c-1',
						],
					],
				],
			],
			'one left unanswered, exempt' => [
				'decisions' => [
					'jan@example.nl' => [
						'send' => true,
						'code' => 'allowed',
						'unsubscribe' => [
							'url' => 'https://nc.example/u/1',
						],
					],
					'piet@example.nl' => [
						'send' => true,
						'code' => 'exempt',
						'unsubscribe' => null,
					],
				],
				'calls' => [
					[
						'event',
						[
							'sourceApp' => 'openregister',
							'channel' => 'email',
							'category' => 'besluit',
							'recipients' => [
								[
									'address' => 'jan@example.nl',
								],
								[
									'address' => 'piet@example.nl',
								],
							],
							'correlationId' => '',
							'baseUrl' => '',
							'requiresConsent' => false,
						],
					],
				],
			],
			'listener ignores' => [
				'decisions' => [
					'jan@example.nl' => [
						'send' => false,
						'code' => 'authority-unavailable',
						'unsubscribe' => null,
					],
					'piet@example.nl' => [
						'send' => false,
						'code' => 'authority-unavailable',
						'unsubscribe' => null,
					],
				],
				'calls' => [
					[
						'event',
						[
							'sourceApp' => 'openregister',
							'channel' => 'email',
							'category' => 'service',
							'recipients' => [
								[
									'address' => 'jan@example.nl',
								],
								[
									'address' => 'piet@example.nl',
								],
							],
							'correlationId' => '',
							'baseUrl' => '',
							'requiresConsent' => false,
						],
					],
					[
						'log.warning',
						'[OptOutAuthority] no answer from integriq; refusing a non-exempt send (authority-unavailab'
							.'le)',
						[
							'category' => 'service',
							'channel' => 'email',
							'addresses' => 2,
							'code' => 'authority-unavailable',
							'correlationId' => '',
						],
					],
				],
			],
			'listener leaves it unhandled' => [
				'decisions' => [
					'jan@example.nl' => [
						'send' => true,
						'code' => 'exempt',
						'unsubscribe' => null,
					],
					'piet@example.nl' => [
						'send' => true,
						'code' => 'exempt',
						'unsubscribe' => null,
					],
				],
				'calls' => [
					[
						'event',
						[
							'sourceApp' => 'openregister',
							'channel' => 'email',
							'category' => 'statutory',
							'recipients' => [
								[
									'address' => 'jan@example.nl',
								],
								[
									'address' => 'piet@example.nl',
								],
							],
							'correlationId' => '',
							'baseUrl' => '',
							'requiresConsent' => false,
						],
					],
				],
			],
			'listener throws' => [
				'decisions' => [
					'jan@example.nl' => [
						'send' => false,
						'code' => 'authority-unavailable',
						'unsubscribe' => null,
					],
					'piet@example.nl' => [
						'send' => false,
						'code' => 'authority-unavailable',
						'unsubscribe' => null,
					],
				],
				'calls' => [
					[
						'event',
						[
							'sourceApp' => 'openregister',
							'channel' => 'sms',
							'category' => 'service',
							'recipients' => [
								[
									'address' => 'jan@example.nl',
								],
								[
									'address' => 'piet@example.nl',
								],
							],
							'correlationId' => '',
							'baseUrl' => '',
							'requiresConsent' => false,
						],
					],
					[
						'log.warning',
						'[OptOutAuthority] integriq threw while deciding; treating it as absent',
						[
							'category' => 'service',
							'channel' => 'sms',
							'exception' => 'integriq table unreadable',
						],
					],
					[
						'log.warning',
						'[OptOutAuthority] no answer from integriq; refusing a non-exempt send (authority-unavailab'
							.'le)',
						[
							'category' => 'service',
							'channel' => 'sms',
							'addresses' => 2,
							'code' => 'authority-unavailable',
							'correlationId' => '',
						],
					],
				],
			],
			'integriq absent, exempt' => [
				'decisions' => [
					'jan@example.nl' => [
						'send' => true,
						'code' => 'exempt',
						'unsubscribe' => null,
					],
					'piet@example.nl' => [
						'send' => true,
						'code' => 'exempt',
						'unsubscribe' => null,
					],
				],
				'calls' => [],
			],
			'integriq absent, not exempt' => [
				'decisions' => [
					'jan@example.nl' => [
						'send' => false,
						'code' => 'authority-unavailable',
						'unsubscribe' => null,
					],
					'piet@example.nl' => [
						'send' => false,
						'code' => 'authority-unavailable',
						'unsubscribe' => null,
					],
				],
				'calls' => [
					[
						'log.warning',
						'[OptOutAuthority] no answer from integriq; refusing a non-exempt send (authority-unavailab'
							.'le)',
						[
							'category' => 'service',
							'channel' => 'email',
							'addresses' => 2,
							'code' => 'authority-unavailable',
							'correlationId' => 'c-2',
						],
					],
				],
			],
		];
	}//end pins()
}//end class
