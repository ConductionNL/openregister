<?php

/**
 * OpenRegister ActionParameters
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec exclude ADR-011 request reading of route actions, not a capability of its own
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Exception\ActionParameterTypeException;

/**
 * Reads request parameters the way Nextcloud binds them to typed action arguments.
 *
 * An absent or null parameter is the default. An int or bool is cast with
 * settype(), and the string `'false'` is false. A string or array parameter
 * of another type is refused, as the strict call of the action refuses it.
 *
 * @spec exclude ADR-011 request reading of route actions, not a capability of its own
 */
final class ActionParameters {
	/**
	 * Wrap the parameters.
	 *
	 * @param array<array-key, mixed> $params The request parameters.
	 *
	 * @spec exclude ADR-011 request reading of route actions, not a capability of its own
	 */
	public function __construct(
		private readonly array $params,
	) {
	}//end __construct()

	/**
	 * A string parameter.
	 *
	 * @param string      $key     The parameter name.
	 * @param string|null $default The value when absent.
	 *
	 * @return string|null The value.
	 *
	 * @throws ActionParameterTypeException When the value is not a string.
	 *
	 * @spec exclude ADR-011 request reading of route actions, not a capability of its own
	 */
	public function string(string $key, ?string $default=null): ?string {
		$value = $this->params[$key] ?? $default;
		if ($value !== null && is_string($value) === false) {
			throw new ActionParameterTypeException(message: 'Parameter `'.$key.'` must be a string');
		}

		return $value;
	}//end string()

	/**
	 * An array parameter, or null when absent.
	 *
	 * @param string $key The parameter name.
	 *
	 * @return array<array-key, mixed>|null The value.
	 *
	 * @throws ActionParameterTypeException When the value is not an array.
	 *
	 * @spec exclude ADR-011 request reading of route actions, not a capability of its own
	 */
	public function list(string $key): ?array {
		$value = $this->params[$key] ?? null;
		if ($value !== null && is_array($value) === false) {
			throw new ActionParameterTypeException(message: 'Parameter `'.$key.'` must be an array');
		}

		return $value;
	}//end list()

	/**
	 * An integer parameter, cast with settype().
	 *
	 * @param string $key     The parameter name.
	 * @param int    $default The value when absent.
	 *
	 * @return int The value.
	 *
	 * @spec exclude ADR-011 request reading of route actions, not a capability of its own
	 */
	public function int(string $key, int $default): int {
		$value = $this->params[$key] ?? $default;
		settype($value, 'int');

		return $value;
	}//end int()

	/**
	 * A boolean parameter: `'false'` is false, anything else is cast with settype().
	 *
	 * @param string $key     The parameter name.
	 * @param bool   $default The value when absent.
	 *
	 * @return bool The value.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The default of an optional boolean route parameter.
	 *
	 * @spec exclude ADR-011 request reading of route actions, not a capability of its own
	 */
	public function bool(string $key, bool $default): bool {
		$value = $this->params[$key] ?? $default;
		if ($value === 'false') {
			return false;
		}

		settype($value, 'bool');

		return $value;
	}//end bool()
}//end class
