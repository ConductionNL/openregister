<?php

/**
 * OpenRegister connection reporter.
 *
 * Tells integriq's connection registry what OpenRegister observed about one of
 * its outside connections, and asks for a fresh resolve after a settings save.
 * Integriq owns the rows the Connections page lists and works out each status
 * itself (hydra change connection-registry, design D4). OpenRegister only
 * reports what it alone can see.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Connection;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends connection reports and refresh requests to integriq.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
 */
class ConnectionReporter {

	/**
	 * The app id integriq keys the rows by.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * Integriq's report event (ADR-041). Named by string so OpenRegister stays
	 * installable without integriq: the class only exists when integriq does.
	 *
	 * @var string
	 */
	public const STATUS_EVENT = 'OCA\Integriq\Event\ConnectionStatusReportedEvent';

	/**
	 * Integriq's refresh event. Named by string for the same reason.
	 *
	 * @var string
	 */
	public const REFRESH_EVENT = 'OCA\Integriq\Event\ConnectionRefreshRequestedEvent';

	/**
	 * The keys `lib/Settings/connections.json` declares, in declared order.
	 *
	 * A key outside this set is a caller's typo, not a new connection. A unit
	 * test keeps the two equal.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = [
		'llm',
		'anonymiser',
		'translation',
		'dsar-identity',
		'dsar-regulator',
		'edepot',
		'github',
		'gitlab',
		'brp',
		'kvk',
		'opencorporates',
		'openproject',
		'xwiki',
		'message-dispatch',
		'pdok',
		'office-converter',
	];

	/**
	 * The statuses integriq accepts in a report (design D6), `limited` included.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = [
		'configured',
		'limited',
		'unconfigured',
		'simulated',
		'unavailable',
		'error',
	];

	/**
	 * App-config keys per connection whose save asks integriq to resolve again.
	 *
	 * Each list is the connection's `requiredConfig` plus its
	 * `adapter.configKey` in `lib/Settings/connections.json`. A unit test keeps
	 * the two equal.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const REFRESH_KEYS = [
		'github' => ['github_api_token'],
		'gitlab' => ['gitlab_api_token'],
	];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Sends the integriq events.
	 * @param LoggerInterface  $logger          Records what could not be sent.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Report a connection's status to integriq.
	 *
	 * Never throws: this runs beside a test or a save whose own answer is what
	 * the caller returns. Without integriq nothing is sent and nothing is
	 * logged, because a missing optional app is not a fault.
	 *
	 * @param string $key     One of {@see self::KEYS}.
	 * @param string $status  One of {@see self::STATUSES}.
	 * @param string $message What OpenRegister observed.
	 *
	 * @return bool True when the report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
	 */
	public function report(string $key, string $status, string $message = ''): bool {
		if (in_array($key, self::KEYS, true) === false) {
			$this->logger->warning(
				message: '[ConnectionReporter] Refusing to report an unknown connection key',
				context: ['key' => $key]
			);
			return false;
		}

		if (in_array($status, self::STATUSES, true) === false) {
			$this->logger->warning(
				message: '[ConnectionReporter] Refusing to report an unknown connection status',
				context: ['key' => $key, 'status' => $status]
			);
			return false;
		}

		$eventClass = $this->resolveEventClass(eventClass: self::STATUS_EVENT);
		if ($eventClass === null) {
			return false;
		}

		return $this->send(
			key: $key,
			build: static fn (): object => new $eventClass(
				app: self::APP_ID,
				key: $key,
				status: $status,
				message: $message,
			)
		);
	}//end report()

	/**
	 * Report which LLM providers a settings save left chosen.
	 *
	 * With no provider nothing answers: chat throws a 503. So an empty choice is
	 * `unconfigured`, never `simulated`. One of the two is `limited`, both is
	 * `configured`. A save tests nothing, and the message says so
	 * (adopt-connection-registry design D2).
	 *
	 * @param mixed $chatProvider      The saved `chatProvider` value.
	 * @param mixed $embeddingProvider The saved `embeddingProvider` value.
	 *
	 * @return bool True when the report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
	 */
	public function reportLlmProviders(mixed $chatProvider, mixed $embeddingProvider): bool {
		$chat = $this->chosenProvider(value: $chatProvider);
		$embedding = $this->chosenProvider(value: $embeddingProvider);

		if ($chat === '' && $embedding === '') {
			return $this->report(
				key: 'llm',
				status: 'unconfigured',
				message: 'No chat or embedding provider is chosen. Chat answers 503 until one is.'
			);
		}

		if ($chat === '') {
			return $this->report(
				key: 'llm',
				status: 'limited',
				message: 'Embeddings use ' . $embedding . '. No chat provider is chosen, so chat answers 503. Saved, not tested.'
			);
		}

		if ($embedding === '') {
			return $this->report(
				key: 'llm',
				status: 'limited',
				message: 'Chat uses ' . $chat . '. No embedding provider is chosen. Saved, not tested.'
			);
		}

		return $this->report(
			key: 'llm',
			status: 'configured',
			message: 'Chat uses ' . $chat . ' and embeddings use ' . $embedding . '. Saved, not tested.'
		);
	}//end reportLlmProviders()

	/**
	 * Ask integriq to resolve every connection whose config keys a save wrote.
	 *
	 * A save that names none of a connection's keys leaves that connection
	 * alone. Integriq reads the saved values itself and decides the status.
	 *
	 * @param array<int, string> $savedKeys The app-config keys the save wrote.
	 *
	 * @return array<int, string> The connection keys a refresh was sent for.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
	 */
	public function refreshFromSave(array $savedKeys): array {
		$eventClass = $this->resolveEventClass(eventClass: self::REFRESH_EVENT);
		if ($eventClass === null) {
			return [];
		}

		$refreshed = [];
		foreach (self::REFRESH_KEYS as $key => $configKeys) {
			if (array_intersect($configKeys, $savedKeys) === []) {
				continue;
			}

			$sent = $this->send(
				key: $key,
				build: static fn (): object => new $eventClass(
					app: self::APP_ID,
					key: $key,
				)
			);
			if ($sent === true) {
				$refreshed[] = $key;
			}
		}

		return $refreshed;
	}//end refreshFromSave()

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @param string $eventClass The fully qualified class name, without a leading backslash.
	 *
	 * @return string|null The class name to instantiate, or null when absent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
	 */
	protected function resolveEventClass(string $eventClass): ?string {
		$qualified = '\\' . $eventClass;
		if (class_exists($qualified) === false) {
			return null;
		}

		return $qualified;
	}//end resolveEventClass()

	/**
	 * A provider id, or '' when nothing is chosen.
	 *
	 * @param mixed $value The stored provider value.
	 *
	 * @return string The provider id, or '' for null, empty or `none`.
	 */
	private function chosenProvider(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		$value = trim($value);
		if (strtolower($value) === 'none') {
			return '';
		}

		return $value;
	}//end chosenProvider()

	/**
	 * Build and dispatch one event, swallowing anything a listener throws.
	 *
	 * @param string            $key   The connection the event is about, for the log.
	 * @param callable(): object $build Builds the event.
	 *
	 * @return bool True when the event was dispatched without an exception.
	 */
	private function send(string $key, callable $build): bool {
		try {
			$event = $build();
			if ($event instanceof Event === false) {
				return false;
			}

			$this->eventDispatcher->dispatchTyped($event);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ConnectionReporter] Could not send a connection event to integriq',
				context: ['key' => $key, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end send()
}//end class
