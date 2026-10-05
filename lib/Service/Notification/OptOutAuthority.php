<?php

/**
 * Asks integriq, once per batch, whether OpenRegister may mail these addresses.
 *
 * OpenRegister cannot depend on integriq. It asks through integriq's typed
 * ADR-041 event, named by string and guarded with `class_exists()`, the shape
 * ConnectionReporter already uses, so OpenRegister stays installable without
 * integriq and no integriq class appears in a type, a `use` or a class header
 * (ADR-083, gate-27).
 *
 * Integriq absent covers three cases with one answer: the class does not
 * exist, the event comes back unhandled, or a listener throws. An exempt
 * category is then sent without a link; everything else is refused with
 * `authority-unavailable` and logged at warning (hydra opt-out-before-send,
 * decision 1: fail closed).
 *
 * Both external send paths, the send-email flow step and a `parties`
 * notification, call this one service, so they cannot drift apart on the
 * fail mode.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
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

namespace OCA\OpenRegister\Service\Notification;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one seam both external send paths ask before they mail.
 */
class OptOutAuthority {

	/**
	 * Integriq's decision event (ADR-041). Named by string so OpenRegister
	 * stays installable without integriq: the class only exists when integriq does.
	 */
	public const DECISION_EVENT = 'OCA\Integriq\Event\OutboundSendDecisionRequestedEvent';

	/**
	 * The app id integriq records the question under.
	 */
	public const SOURCE_APP = 'openregister';

	/**
	 * The rollback switch. Security relevant (ADR-102): only the literal
	 * `false` turns the check off; any other value, or none, reads as on.
	 */
	public const CONFIG_KEY = 'outbound_optout_check';

	/**
	 * The fleet's message categories (hydra opt-out-before-send, section 4).
	 * `reply` is left out on purpose: OpenRegister answers no inbound message.
	 */
	public const CATEGORIES = ['besluit', 'statutory', 'account', 'security', 'case-update', 'reminder', 'service', 'marketing'];

	/**
	 * The exempt floor. A contract constant, not config: with integriq absent
	 * there is nothing to read config from.
	 */
	public const EXEMPT_CATEGORIES = ['besluit', 'statutory', 'account', 'security'];

	public const DEFAULT_CATEGORY = 'service';

	public const CODE_ALLOWED = 'allowed';

	public const CODE_AUTHORITY_UNAVAILABLE = 'authority-unavailable';

	public const CODE_EXEMPT = 'exempt';

	public const CODE_CHECK_OFF = 'check-off';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Carries the question to integriq.
	 * @param IAppConfig $appConfig Holds the rollback switch.
	 * @param LoggerInterface $logger Records every refusal for want of an answer.
	 * @param IL10N|null $l10n Renders the body line; null renders it in English.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ?IL10N $l10n = null,
	) {
	}//end __construct()

	/**
	 * Read a declared category: absent or unknown is `service`, never exempt.
	 *
	 * @param mixed $category The declared value.
	 *
	 * @return string One of CATEGORIES.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-step-declares-a-message-category-req-ero-002
	 */
	public static function normaliseCategory(mixed $category): string {
		if (is_string($category) === false) {
			return self::DEFAULT_CATEGORY;
		}

		$category = strtolower(trim($category));
		if (in_array($category, self::CATEGORIES, true) === false) {
			return self::DEFAULT_CATEGORY;
		}

		return $category;
	}//end normaliseCategory()

	/**
	 * Whether a declared category is one of the fleet's. Absent counts as valid.
	 *
	 * @param mixed $category The declared value.
	 *
	 * @return bool True for null, '' or a known category.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-step-declares-a-message-category-req-ero-002
	 */
	public static function isValidCategory(mixed $category): bool {
		if ($category === null || $category === '') {
			return true;
		}

		if (is_string($category) === false) {
			return false;
		}

		return in_array(strtolower(trim($category)), self::CATEGORIES, true);
	}//end isValidCategory()

	/**
	 * Ask once for a batch of addresses.
	 *
	 * @param string $channel The channel, `email` for both OpenRegister paths.
	 * @param string $category The message category; normalised first.
	 * @param array<int, string> $addresses The addresses, as they will be mailed.
	 * @param string $correlationId Ties integriq's log rows to this send.
	 *
	 * @return array<string, array{send: bool, code: string, unsubscribe: array<string, mixed>|null}> By address.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
	 */
	public function ask(string $channel, string $category, array $addresses, string $correlationId = ''): array {
		$addresses = array_values(array_unique(array_filter(array_map('strval', $addresses), static fn (string $a): bool => $a !== '')));
		if ($addresses === []) {
			return [];
		}

		$category = self::normaliseCategory(category: $category);

		if ($this->isCheckEnabled() === false) {
			return $this->uniform(addresses: $addresses, code: self::CODE_CHECK_OFF);
		}

		$event = $this->dispatch(channel: $channel, category: $category, addresses: $addresses, correlationId: $correlationId);
		if ($event === null || method_exists($event, 'getDecision') === false) {
			return $this->absent(channel: $channel, category: $category, addresses: $addresses, correlationId: $correlationId);
		}

		$decisions = [];
		$missing   = [];
		foreach ($addresses as $address) {
			$decision = $event->getDecision($address);
			if (is_array($decision) === false) {
				$missing[] = $address;
				continue;
			}

			$unsubscribe = null;
			if (is_array($decision['unsubscribe'] ?? null) === true) {
				$unsubscribe = $decision['unsubscribe'];
			}

			$decisions[$address] = [
				'send' => (($decision['send'] ?? false) === true),
				'code' => (string)($decision['code'] ?? self::CODE_ALLOWED),
				'unsubscribe' => $unsubscribe,
			];
		}

		if ($missing !== []) {
			// A handled event names every recipient. One it does not name has
			// no answer, and no answer is refused like an absent integriq.
			$decisions = array_merge(
				$decisions,
				$this->absent(channel: $channel, category: $category, addresses: $missing, correlationId: $correlationId)
			);
		}

		return $decisions;
	}//end ask()

	/**
	 * A body with integriq's link line appended, or the body unchanged when there is no link.
	 *
	 * @param string $body The body.
	 * @param array<string, mixed>|null $unsubscribe Integriq's material, null for none.
	 *
	 * @return string The body.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/external-recipient-opt-out/spec.md#requirement-an-external-mail-carries-the-unsubscribe-link-req-ero-004
	 */
	public function withLink(string $body, ?array $unsubscribe): string {
		$url = trim((string)($unsubscribe['url'] ?? ''));
		if ($url === '') {
			return $body;
		}

		$line = sprintf('Stop receiving these messages: %s', $url);
		if ($this->l10n !== null) {
			$line = $this->l10n->t('Stop receiving these messages: %s', [$url]);
		}

		return rtrim($body) . "\n\n" . $line;
	}//end withLink()

	/**
	 * Whether a category is in the exempt floor.
	 *
	 * @param string $category The category.
	 *
	 * @return bool True when exempt.
	 */
	public static function isExempt(string $category): bool {
		return in_array(self::normaliseCategory(category: $category), self::EXEMPT_CATEGORIES, true);
	}//end isExempt()

	/**
	 * Whether the rollback switch leaves the check on.
	 *
	 * @return bool False only for the literal `false`.
	 */
	private function isCheckEnabled(): bool {
		try {
			$value = $this->appConfig->getValueString(self::SOURCE_APP, self::CONFIG_KEY, 'true');
		} catch (Throwable $e) {
			// A key stored under another type is not a reason to stop checking.
			return true;
		}

		return strtolower(trim($value)) !== 'false';
	}//end isCheckEnabled()

	/**
	 * Build and dispatch the event; null when integriq gave no answer.
	 *
	 * @param string $channel The channel.
	 * @param string $category The category.
	 * @param array<int, string> $addresses The addresses.
	 * @param string $correlationId The correlation id.
	 *
	 * @return Event|null The handled event, or null.
	 */
	private function dispatch(string $channel, string $category, array $addresses, string $correlationId): ?Event {
		$class = $this->resolveEventClass();
		if ($class === null) {
			return null;
		}

		try {
			$event = new $class(
				sourceApp: self::SOURCE_APP,
				channel: $channel,
				category: $category,
				recipients: array_map(static fn (string $address): array => ['address' => $address], $addresses),
				correlationId: $correlationId,
				baseUrl: '',
				requiresConsent: ($category === 'marketing'),
			);
			if (($event instanceof Event) === false) {
				return null;
			}

			$this->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[OptOutAuthority] integriq threw while deciding; treating it as absent',
				['category' => $category, 'channel' => $channel, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if (method_exists($event, 'isHandled') === false || $event->isHandled() !== true) {
			return null;
		}

		return $event;
	}//end dispatch()

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @return string|null The class name.
	 */
	protected function resolveEventClass(): ?string {
		$qualified = '\\' . self::DECISION_EVENT;
		if (class_exists($qualified) === false) {
			return null;
		}

		return $qualified;
	}//end resolveEventClass()

	/**
	 * The answer without integriq: exempt is sent, everything else refused and logged.
	 *
	 * @param string $channel The channel.
	 * @param string $category The category.
	 * @param array<int, string> $addresses The addresses.
	 * @param string $correlationId The correlation id.
	 *
	 * @return array<string, array{send: bool, code: string, unsubscribe: array<string, mixed>|null}> By address.
	 */
	private function absent(string $channel, string $category, array $addresses, string $correlationId): array {
		if (self::isExempt(category: $category) === true) {
			return $this->uniform(addresses: $addresses, code: self::CODE_EXEMPT);
		}

		$this->logger->warning(
			'[OptOutAuthority] no answer from integriq; refusing a non-exempt send (authority-unavailable)',
			[
				'category' => $category,
				'channel' => $channel,
				'addresses' => count($addresses),
				'code' => self::CODE_AUTHORITY_UNAVAILABLE,
				'correlationId' => $correlationId,
			]
		);

		return $this->uniform(addresses: $addresses, code: self::CODE_AUTHORITY_UNAVAILABLE);
	}//end absent()

	/**
	 * The same decision for every address. Only `authority-unavailable` refuses.
	 *
	 * @param array<int, string> $addresses The addresses.
	 * @param string $code The code.
	 *
	 * @return array<string, array{send: bool, code: string, unsubscribe: null}> By address.
	 */
	private function uniform(array $addresses, string $code): array {
		$send      = ($code !== self::CODE_AUTHORITY_UNAVAILABLE);
		$decisions = [];
		foreach ($addresses as $address) {
			$decisions[$address] = ['send' => $send, 'code' => $code, 'unsubscribe' => null];
		}

		return $decisions;
	}//end uniform()
}//end class
