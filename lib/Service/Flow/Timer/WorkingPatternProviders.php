<?php

/**
 * The working-pattern providers installed apps registered, asked in turn.
 *
 * Providers register lazily, on the first question, through
 * {@see RegisterWorkingPatternProvidersEvent}. The first provider with a
 * well-formed pattern for the person answers. A provider that throws or
 * answers with something malformed is logged and passed over, so a broken app
 * leaves the scope calendar to decide instead of failing the term (design
 * D-C67-2). Nothing here is stored.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-persons-working-pattern-is-read-from-the-app-that-owns-it-req-wca-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Asks the registered providers for a person's working pattern.
 */
class WorkingPatternProviders {

	/**
	 * The registered providers, keyed by app id.
	 *
	 * @var array<string, WorkingPatternProviderInterface>
	 */
	private array $providers = [];

	/**
	 * Whether the registration event was dispatched.
	 *
	 * @var boolean
	 */
	private bool $loaded = false;

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher Dispatches the registration event.
	 * @param LoggerInterface  $logger     Logs a provider that fails or answers malformed.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Register a provider; one per app.
	 *
	 * @param WorkingPatternProviderInterface $provider The provider.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the provider names no app, or its app already registered one.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-persons-working-pattern-is-read-from-the-app-that-owns-it-req-wca-006
	 */
	public function register(WorkingPatternProviderInterface $provider): void {
		$appId = trim($provider->appId());
		if ($appId === '') {
			throw new UnexpectedValueException('A working-pattern provider must name the app it belongs to.');
		}

		if (array_key_exists($appId, $this->providers) === true) {
			throw new UnexpectedValueException(sprintf('The app "%s" already registered a working-pattern provider.', $appId));
		}

		$this->providers[$appId] = $provider;
	}//end register()

	/**
	 * The person's working pattern from the first provider that has one, or null when nobody answers.
	 *
	 * @param string $userId The Nextcloud user id.
	 *
	 * @return array<string, mixed>|null `{source, workingWeekdays: int[]|null, hoursPerWorkingDay: float|null, absences: {date, name}[]}`, or null.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-persons-working-pattern-is-read-from-the-app-that-owns-it-req-wca-006
	 */
	public function patternFor(string $userId): ?array {
		$userId = trim($userId);
		if ($userId === '') {
			return null;
		}

		$this->load();
		foreach ($this->providers as $appId => $provider) {
			$pattern = $this->askOne(appId: $appId, provider: $provider, userId: $userId);
			if ($pattern !== null) {
				return $pattern;
			}
		}

		return null;
	}//end patternFor()

	/**
	 * One provider's normalised answer, or null when it has none, throws or answers malformed.
	 *
	 * @param string                          $appId    The provider's app.
	 * @param WorkingPatternProviderInterface $provider The provider.
	 * @param string                          $userId   The user.
	 *
	 * @return array<string, mixed>|null `{source, workingWeekdays: int[]|null, hoursPerWorkingDay: float|null, absences: {date, name}[]}`, or null.
	 */
	private function askOne(string $appId, WorkingPatternProviderInterface $provider, string $userId): ?array {
		try {
			$answer = $provider->patternFor(userId: $userId);
			if ($answer === null) {
				return null;
			}

			return ['source' => $appId] + self::normalised(answer: $answer);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[WorkingPatternProviders] The working pattern from "' . $appId . '" was not used: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'app' => $appId]
			);
			return null;
		}
	}//end askOne()

	/**
	 * Validate and normalise a provider's answer.
	 *
	 * @param array<string, mixed> $answer The answer.
	 *
	 * @return array{workingWeekdays: array<int, int>|null, hoursPerWorkingDay: float|null, absences: array<int, array{date: string, name: string}>}
	 *
	 * @throws UnexpectedValueException When a key is malformed.
	 */
	public static function normalised(array $answer): array {
		return [
			'workingWeekdays' => self::weekdays(value: ($answer['workingWeekdays'] ?? null)),
			'hoursPerWorkingDay' => self::hours(value: ($answer['hoursPerWorkingDay'] ?? null)),
			'absences' => self::absences(value: ($answer['absences'] ?? [])),
		];
	}//end normalised()

	/**
	 * ISO weekdays, or null when not given.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<int, int>|null The weekdays, sorted.
	 *
	 * @throws UnexpectedValueException When not a list of 1 to 7.
	 */
	private static function weekdays(mixed $value): ?array {
		if ($value === null) {
			return null;
		}

		if (is_array($value) === false) {
			throw new UnexpectedValueException('workingWeekdays must be a list of ISO weekdays.');
		}

		$days = [];
		foreach ($value as $day) {
			if (is_int($day) === false || $day < 1 || $day > 7) {
				throw new UnexpectedValueException(sprintf('workingWeekdays holds %s, which is not an ISO weekday 1 to 7.', var_export($day, true)));
			}

			$days[$day] = $day;
		}

		ksort($days);

		return array_values($days);
	}//end weekdays()

	/**
	 * Hours per working day, or null when not given.
	 *
	 * @param mixed $value The value.
	 *
	 * @return float|null The hours.
	 *
	 * @throws UnexpectedValueException When not a number above 0 and at most 24.
	 */
	private static function hours(mixed $value): ?float {
		if ($value === null) {
			return null;
		}

		if ((is_int($value) === false && is_float($value) === false) || $value <= 0 || $value > 24) {
			throw new UnexpectedValueException(sprintf('hoursPerWorkingDay %s is not a number of hours above 0 and at most 24.', var_export($value, true)));
		}

		return (float)$value;
	}//end hours()

	/**
	 * Absence days as `{date, name}`.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<int, array{date: string, name: string}> The absences.
	 *
	 * @throws UnexpectedValueException When an entry is not a `Y-m-d` date.
	 */
	private static function absences(mixed $value): array {
		if (is_array($value) === false) {
			throw new UnexpectedValueException('absences must be a list of dates.');
		}

		$absences = [];
		foreach ($value as $entry) {
			$date = $entry;
			$name = 'absence';
			if (is_array($entry) === true) {
				$date = ($entry['date'] ?? null);
				$name = trim((string)($entry['name'] ?? 'absence'));
			}

			if (is_string($date) === false || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
				throw new UnexpectedValueException(sprintf('absences holds %s, which is not a Y-m-d date.', var_export($date, true)));
			}

			if ($name === '') {
				$name = 'absence';
			}

			$absences[] = ['date' => $date, 'name' => $name];
		}

		return $absences;
	}//end absences()

	/**
	 * Let installed apps register, once.
	 *
	 * @return void
	 */
	private function load(): void {
		if ($this->loaded === true) {
			return;
		}

		// Set BEFORE dispatching, like the principal registry: a listener that
		// touches this registry again must not re-dispatch.
		$this->loaded = true;
		$this->dispatcher->dispatchTyped(new RegisterWorkingPatternProvidersEvent(registry: $this));
	}//end load()
}//end class
