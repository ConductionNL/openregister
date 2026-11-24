<?php

/**
 * OpenRegister Load Shedding Settings
 *
 * The administered thresholds of the dependency breakers and of database
 * pressure shedding, read and written as one form.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Resilience
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Resilience;

use InvalidArgumentException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes the `load_shedding_*` appconfig keys (design D-8).
 *
 * Reading answers the value in force: a stored value that cannot be read or
 * is out of range is reported as its default, the same fallback
 * `BreakerRegistry` and `DatabasePressureMeter` apply. Writing checks every
 * field before storing any, so a refused form changes nothing. Dependency
 * breakers can only be tuned; only database-pressure shedding can be
 * switched off.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
class LoadSheddingSettings {

	/**
	 * The app whose config holds the keys.
	 */
	private const APP_ID = 'openregister';

	/**
	 * The highest value any numeric field may be set to.
	 */
	private const CEILING = 2147483647;

	/**
	 * The on/off switch of database-pressure shedding.
	 */
	private const SWITCH_KEY = 'load_shedding_pressure_enabled';

	/**
	 * The numeric fields: form name => appconfig key, default, lowest and highest value.
	 */
	private const FIELDS = [
		'breakerMinCalls' => ['key' => 'load_shedding_breaker_min_calls', 'default' => 10, 'min' => 1, 'max' => self::CEILING],
		'breakerMinFailures' => ['key' => 'load_shedding_breaker_min_failures', 'default' => 5, 'min' => 1, 'max' => self::CEILING],
		'breakerFailurePercent' => ['key' => 'load_shedding_breaker_failure_percent', 'default' => 50, 'min' => 1, 'max' => 100],
		'breakerCoolDown' => ['key' => 'load_shedding_breaker_cool_down', 'default' => 30, 'min' => 1, 'max' => self::CEILING],
		'breakerMaxCoolDown' => ['key' => 'load_shedding_breaker_max_cool_down', 'default' => 300, 'min' => 1, 'max' => self::CEILING],
		'pressureMinRequests' => ['key' => 'load_shedding_pressure_min_requests', 'default' => 50, 'min' => 1, 'max' => self::CEILING],
		'pressureSlowMs' => ['key' => 'load_shedding_pressure_slow_ms', 'default' => 2000, 'min' => 1, 'max' => self::CEILING],
		'pressureSlowPercent' => ['key' => 'load_shedding_pressure_slow_percent', 'default' => 20, 'min' => 1, 'max' => 100],
		'pressureErrorPercent' => ['key' => 'load_shedding_pressure_error_percent', 'default' => 10, 'min' => 1, 'max' => 100],
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig The store of the thresholds.
	 * @param LoggerInterface $logger    Where an unreadable value is reported.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The values in force, with the default and the bounds of every numeric field.
	 *
	 * @return array{values: array<string, int|bool>, defaults: array<string, int|bool>, bounds: array<string, array{min: int, max: int}>} The form.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function read(): array {
		$values   = ['pressureEnabled' => $this->readSwitch()];
		$defaults = ['pressureEnabled' => true];
		$bounds   = [];
		foreach (self::FIELDS as $name => $field) {
			$values[$name]   = $this->readInt(field: $field);
			$defaults[$name] = $field['default'];
			$bounds[$name]   = ['min' => $field['min'], 'max' => $field['max']];
		}

		return ['values' => $values, 'defaults' => $defaults, 'bounds' => $bounds];
	}//end read()

	/**
	 * Store the fields given; fields not named are left as they are.
	 *
	 * @param array<string, mixed> $input The form values by field name.
	 *
	 * @return array<string, array<string, mixed>> The form after storing, shaped as read() answers it.
	 *
	 * @throws InvalidArgumentException When a field is not a whole number within its bounds, or the switch is not a boolean; nothing is stored then.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function write(array $input): array {
		$numbers = [];
		foreach (self::FIELDS as $name => $field) {
			if (array_key_exists($name, $input) === false) {
				continue;
			}

			$numbers[$field['key']] = $this->wholeNumber(name: $name, value: $input[$name], field: $field);
		}

		$switch = null;
		if (array_key_exists('pressureEnabled', $input) === true) {
			if (is_bool($input['pressureEnabled']) === false) {
				throw new InvalidArgumentException('pressureEnabled must be true or false.');
			}

			$switch = $input['pressureEnabled'];
		}

		foreach ($numbers as $key => $value) {
			$this->appConfig->setValueInt(self::APP_ID, $key, $value);
		}

		if ($switch !== null) {
			$this->appConfig->setValueBool(self::APP_ID, self::SWITCH_KEY, $switch);
		}

		return $this->read();
	}//end write()

	/**
	 * A form value as a whole number within its field's bounds.
	 *
	 * @param string                                           $name  The field name, for the refusal.
	 * @param mixed                                            $value The value sent.
	 * @param array{key: string, default: int, min: int, max: int} $field The field.
	 *
	 * @return int The number.
	 *
	 * @throws InvalidArgumentException When it is not a whole number within the bounds.
	 */
	private function wholeNumber(string $name, mixed $value, array $field): int {
		$number = filter_var($value, FILTER_VALIDATE_INT);
		if (is_bool($value) === true || $number === false || $number < $field['min'] || $number > $field['max']) {
			throw new InvalidArgumentException(
				sprintf('%s must be a whole number from %d to %d.', $name, $field['min'], $field['max'])
			);
		}

		return $number;
	}//end wholeNumber()

	/**
	 * A stored number, or its default when unreadable or out of range.
	 *
	 * @param array{key: string, default: int, min: int, max: int} $field The field.
	 *
	 * @return int The value in force.
	 */
	private function readInt(array $field): int {
		try {
			$value = $this->appConfig->getValueInt(self::APP_ID, $field['key'], $field['default']);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('[LoadSheddingSettings] %s unreadable, reporting default %d: %s', $field['key'], $field['default'], $e->getMessage())
			);
			return $field['default'];
		}

		if ($value < $field['min'] || $value > $field['max']) {
			return $field['default'];
		}

		return $value;
	}//end readInt()

	/**
	 * Whether database-pressure shedding is on; unreadable means on, as the meter reads it.
	 *
	 * @return bool The switch.
	 */
	private function readSwitch(): bool {
		try {
			return $this->appConfig->getValueBool(self::APP_ID, self::SWITCH_KEY, true);
		} catch (Throwable $e) {
			$this->logger->warning(sprintf('[LoadSheddingSettings] %s unreadable, reporting on: %s', self::SWITCH_KEY, $e->getMessage()));
			return true;
		}
	}//end readSwitch()
}//end class
