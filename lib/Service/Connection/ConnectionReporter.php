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
class ConnectionReporter implements ConnectionReporterInterface {

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
	 * Keeps the shape its callers use; builds the report and delegates to
	 * {@see self::reportStatus()} (ADR-011 Rule 5).
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
		return $this->reportStatus(report: new ConnectionStatusReport(key: $key, status: $status, message: $message));
	}//end report()

	/**
	 * Report a connection's status to integriq.
	 *
	 * Never throws: this runs beside a test or a save whose own answer is what
	 * the caller returns. Without integriq nothing is sent and nothing is
	 * logged, because a missing optional app is not a fault.
	 *
	 * @param ConnectionStatusReport $report The connection, its status and what was observed.
	 *
	 * @return bool True when the report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
	 */
	public function reportStatus(ConnectionStatusReport $report): bool {
		if ($this->hasKnownKey(report: $report) === false || $this->hasKnownStatus(report: $report) === false) {
			return false;
		}

		$eventClass = $this->resolveEventClass(eventClass: self::STATUS_EVENT);
		if ($eventClass === null) {
			return false;
		}

		return $this->send(key: $report->key, build: $this->statusEventBuilder(eventClass: $eventClass, report: $report));
	}//end reportStatus()

	/**
	 * Whether a report names one of the connection keys; an unknown key is refused with a warning.
	 *
	 * @param ConnectionStatusReport $report The report.
	 *
	 * @return bool True when the key is known.
	 */
	private function hasKnownKey(ConnectionStatusReport $report): bool {
		if (in_array($report->key, self::KEYS, true) === true) {
			return true;
		}

		$this->logger->warning(
			message: '[ConnectionReporter] Refusing to report an unknown connection key',
			context: ['key' => $report->key]
		);
		return false;
	}//end hasKnownKey()

	/**
	 * Whether a report carries one of the six statuses; an unknown status is refused with a warning.
	 *
	 * @param ConnectionStatusReport $report The report.
	 *
	 * @return bool True when the status is known.
	 */
	private function hasKnownStatus(ConnectionStatusReport $report): bool {
		if (in_array($report->status, self::STATUSES, true) === true) {
			return true;
		}

		$this->logger->warning(
			message: '[ConnectionReporter] Refusing to report an unknown connection status',
			context: ['key' => $report->key, 'status' => $report->status]
		);
		return false;
	}//end hasKnownStatus()

	/**
	 * The builder of a status event for a report.
	 *
	 * @param string                 $eventClass The resolved integriq status event class.
	 * @param ConnectionStatusReport $report     The report.
	 *
	 * @return callable(): object The builder.
	 */
	private function statusEventBuilder(string $eventClass, ConnectionStatusReport $report): callable {
		$key     = $report->key;
		$status  = $report->status;
		$message = $report->message;

		return static fn (): object => new $eventClass(
			app: self::APP_ID,
			key: $key,
			status: $status,
			message: $message,
		);
	}//end statusEventBuilder()

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
		$chat      = $this->chosenProvider(value: $chatProvider);
		$embedding = $this->chosenProvider(value: $embeddingProvider);

		return $this->report(
			key: 'llm',
			status: $this->llmStatus(chat: $chat, embedding: $embedding),
			message: $this->llmMessage(chat: $chat, embedding: $embedding)
		);
	}//end reportLlmProviders()

	/**
	 * The status of the chosen LLM providers: none is unconfigured, one is limited, both is configured.
	 *
	 * @param string $chat      The chosen chat provider, or ''.
	 * @param string $embedding The chosen embedding provider, or ''.
	 *
	 * @return string The connection status.
	 */
	private function llmStatus(string $chat, string $embedding): string {
		if ($chat === '' && $embedding === '') {
			return 'unconfigured';
		}

		if ($chat === '' || $embedding === '') {
			return 'limited';
		}

		return 'configured';
	}//end llmStatus()

	/**
	 * What the chosen LLM providers mean for chat and embeddings; a save tests nothing, and says so.
	 *
	 * @param string $chat      The chosen chat provider, or ''.
	 * @param string $embedding The chosen embedding provider, or ''.
	 *
	 * @return string The message.
	 */
	private function llmMessage(string $chat, string $embedding): string {
		if ($chat === '' && $embedding === '') {
			return 'No chat or embedding provider is chosen. Chat answers 503 until one is.';
		}

		if ($chat === '') {
			return 'Embeddings use ' . $embedding . '. No chat provider is chosen, so chat answers 503. Saved, not tested.';
		}

		if ($embedding === '') {
			return 'Chat uses ' . $chat . '. No embedding provider is chosen. Saved, not tested.';
		}

		return 'Chat uses ' . $chat . ' and embeddings use ' . $embedding . '. Saved, not tested.';
	}//end llmMessage()

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
			if (array_intersect($configKeys, $savedKeys) !== [] && $this->requestRefresh(key: $key, eventClass: $eventClass) === true) {
				$refreshed[] = $key;
			}
		}

		return $refreshed;
	}//end refreshFromSave()

	/**
	 * Ask integriq to resolve one connection again.
	 *
	 * @param string $key        The connection key.
	 * @param string $eventClass The resolved integriq refresh event class.
	 *
	 * @return bool True when the request was sent.
	 */
	private function requestRefresh(string $key, string $eventClass): bool {
		return $this->send(
			key: $key,
			build: static fn (): object => new $eventClass(
				app: self::APP_ID,
				key: $key,
			)
		);
	}//end requestRefresh()

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
			$this->logSendFailure(key: $key, failure: $e);
			return false;
		}
	}//end send()

	/**
	 * Log a connection event that could not be built or dispatched.
	 *
	 * @param string    $key     The connection key.
	 * @param Throwable $failure What went wrong.
	 *
	 * @return void
	 */
	private function logSendFailure(string $key, Throwable $failure): void {
		$this->logger->warning(
			message: '[ConnectionReporter] Could not send a connection event to integriq',
			context: ['key' => $key, 'exception' => $failure->getMessage()]
		);
	}//end logSendFailure()
}//end class
