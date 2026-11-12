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
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one seam both external send paths ask before they mail.
 */
class OptOutAuthority implements OptOutAuthorityInterface {

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
	 * The exempt floor. A contract constant, not config: with integriq absent
	 * there is nothing to read config from.
	 */
	public const EXEMPT_CATEGORIES = ['besluit', 'statutory', 'account', 'security'];

	public const CODE_ALLOWED = 'allowed';

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
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-step-declares-a-message-category-req-ero-002
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
	 * Ask once for the batch of addresses a question names.
	 *
	 * The addresses are cleaned and the category normalised before integriq is asked.
	 *
	 * @param OptOutQuestion $question The channel, category, addresses and correlation id.
	 *
	 * @return array<string, array{send: bool, code: string, unsubscribe: array<string, mixed>|null}> By address.
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
	 */
	public function askQuestion(OptOutQuestion $question): array {
		$addresses = $this->cleanAddresses(addresses: $question->addresses);
		if ($addresses === []) {
			return [];
		}

		if ($this->isCheckEnabled() === false) {
			return $this->uniform(addresses: $addresses, code: self::CODE_CHECK_OFF);
		}

		return $this->answer(
			question: new OptOutQuestion(
				channel: $question->channel,
				category: self::normaliseCategory(category: $question->category),
				addresses: $addresses,
				correlationId: $question->correlationId
			)
		);
	}//end askQuestion()

	/**
	 * The addresses as strings, without empties and duplicates.
	 *
	 * @param array<int, mixed> $addresses The addresses as given.
	 *
	 * @return array<int, string>
	 */
	private function cleanAddresses(array $addresses): array {
		return array_values(array_unique(array_filter(array_map('strval', $addresses), static fn (string $a): bool => $a !== '')));
	}//end cleanAddresses()

	/**
	 * Integriq's decisions for a question; addresses it leaves unanswered get the answer without integriq.
	 *
	 * A handled event names every recipient. One it does not name has no
	 * answer, and no answer is refused like an absent integriq.
	 *
	 * @param OptOutQuestion $question The question.
	 *
	 * @return array<string, array{send: bool, code: string, unsubscribe: array<string, mixed>|null}> By address.
	 */
	private function answer(OptOutQuestion $question): array {
		$decisions = $this->decisionsFrom(event: $this->dispatch(question: $question), addresses: $question->addresses);
		if ($decisions === null) {
			return $this->absent(question: $question);
		}

		$missing   = array_values(array_diff($question->addresses, array_keys($decisions)));
		if ($missing === []) {
			return $decisions;
		}

		return array_merge($decisions, $this->absent(question: $question->forAddresses(addresses: $missing)));
	}//end answer()

	/**
	 * The decisions a handled event carries, for the addresses it names; null when there is no answer.
	 *
	 * @param Event|null         $event     The handled event, or null when integriq gave no answer.
	 * @param array<int, string> $addresses The addresses asked about.
	 *
	 * @return array<string, array{send: bool, code: string, unsubscribe: array<string, mixed>|null}>|null By address.
	 */
	private function decisionsFrom(?Event $event, array $addresses): ?array {
		if ($event === null || method_exists($event, 'getDecision') === false) {
			return null;
		}

		$decisions = [];
		foreach ($addresses as $address) {
			$decision = $event->getDecision($address);
			if (is_array($decision) === true) {
				$decisions[$address] = $this->decision(decision: $decision);
			}
		}

		return $decisions;
	}//end decisionsFrom()

	/**
	 * One decision as integriq gave it, in the shape askQuestion() returns.
	 *
	 * @param array<string, mixed> $decision Integriq's decision.
	 *
	 * @return array{send: bool, code: string, unsubscribe: array<string, mixed>|null}
	 */
	private function decision(array $decision): array {
		$unsubscribe = null;
		if (is_array($decision['unsubscribe'] ?? null) === true) {
			$unsubscribe = $decision['unsubscribe'];
		}

		return [
			'send' => (($decision['send'] ?? false) === true),
			'code' => (string)($decision['code'] ?? self::CODE_ALLOWED),
			'unsubscribe' => $unsubscribe,
		];
	}//end decision()

	/**
	 * One address's decision; none means no answer, and no answer is refused.
	 *
	 * @param array<string, array{send: bool, code: string, unsubscribe: array|null}> $decisions What askQuestion() returned.
	 * @param string $address The address.
	 *
	 * @return array{send: bool, code: string, unsubscribe: array|null} The decision.
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
	 */
	public function decisionFor(array $decisions, string $address): array {
		return ($decisions[$address] ?? ['send' => false, 'code' => self::CODE_AUTHORITY_UNAVAILABLE, 'unsubscribe' => null]);
	}//end decisionFor()

	/**
	 * A body with integriq's link line appended, or the body unchanged when there is no link.
	 *
	 * @param string $body The body.
	 * @param array<string, mixed>|null $unsubscribe Integriq's material, null for none.
	 *
	 * @return string The body.
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-external-mail-carries-the-unsubscribe-link-req-ero-004
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
	 * @param OptOutQuestion $question The question.
	 *
	 * @return Event|null The handled event, or null.
	 */
	private function dispatch(OptOutQuestion $question): ?Event {
		$class = $this->resolveEventClass();
		if ($class === null) {
			return null;
		}

		$event = $this->dispatchEvent(class: $class, question: $question);
		if ($event === null || method_exists($event, 'isHandled') === false || $event->isHandled() !== true) {
			return null;
		}

		return $event;
	}//end dispatch()

	/**
	 * Build the event and dispatch it; null when it is no event or integriq threw.
	 *
	 * @param string         $class    The event class.
	 * @param OptOutQuestion $question The question.
	 *
	 * @return Event|null The dispatched event, handled or not, or null.
	 */
	private function dispatchEvent(string $class, OptOutQuestion $question): ?Event {
		try {
			$event = $this->newEvent(class: $class, question: $question);
			if (($event instanceof Event) === true) {
				$this->eventDispatcher->dispatchTyped($event);
				return $event;
			}
		} catch (Throwable $e) {
			$context = ['category' => $question->category, 'channel' => $question->channel, 'exception' => $e->getMessage()];
			$this->logger->warning('[OptOutAuthority] integriq threw while deciding; treating it as absent', $context);
		}

		return null;
	}//end dispatchEvent()

	/**
	 * A new instance of integriq's decision event for a question.
	 *
	 * @param string         $class    The event class.
	 * @param OptOutQuestion $question The question.
	 *
	 * @return mixed The event.
	 */
	private function newEvent(string $class, OptOutQuestion $question): mixed {
		return new $class(
			sourceApp: self::SOURCE_APP,
			channel: $question->channel,
			category: $question->category,
			recipients: array_map(static fn (string $address): array => ['address' => $address], $question->addresses),
			correlationId: $question->correlationId,
			baseUrl: '',
			requiresConsent: ($question->category === 'marketing'),
		);
	}//end newEvent()

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @return string|null The class name.
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
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
	 * @param OptOutQuestion $question The question.
	 *
	 * @return array<string, array{send: bool, code: string, unsubscribe: array<string, mixed>|null}> By address.
	 */
	private function absent(OptOutQuestion $question): array {
		if (self::isExempt(category: $question->category) === true) {
			return $this->uniform(addresses: $question->addresses, code: self::CODE_EXEMPT);
		}

		$this->logger->warning(
			'[OptOutAuthority] no answer from integriq; refusing a non-exempt send (authority-unavailable)',
			$this->absentContext(question: $question)
		);

		return $this->uniform(addresses: $question->addresses, code: self::CODE_AUTHORITY_UNAVAILABLE);
	}//end absent()

	/**
	 * The log context of a send refused for want of an answer.
	 *
	 * @param OptOutQuestion $question The question.
	 *
	 * @return array<string, mixed>
	 */
	private function absentContext(OptOutQuestion $question): array {
		return [
			'category' => $question->category,
			'channel' => $question->channel,
			'addresses' => count($question->addresses),
			'code' => self::CODE_AUTHORITY_UNAVAILABLE,
			'correlationId' => $question->correlationId,
		];
	}//end absentContext()

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
