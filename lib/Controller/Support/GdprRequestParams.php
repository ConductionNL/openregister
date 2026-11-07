<?php

/**
 * Optional query parameters of the AVG register endpoints.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use DateTime;
use OCP\IRequest;
use Throwable;

/**
 * Reads optional string, date and bounded integer parameters from a request.
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */
final class GdprRequestParams {
	/**
	 * Wrap the request.
	 *
	 * @param IRequest $request The active request.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function __construct(
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * A parameter as a string, or null when absent or the empty string.
	 *
	 * @param string $key The parameter name.
	 *
	 * @return string|null The value cast to string, or null.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function nonEmpty(string $key): ?string {
		$value = $this->request->getParam(key: $key);
		if ($value === null || $value === '') {
			return null;
		}

		return (string)$value;
	}//end nonEmpty()

	/**
	 * A string parameter trimmed, or null when absent, not a string, or blank.
	 *
	 * @param string $key The parameter name.
	 *
	 * @return string|null The trimmed value, or null.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function trimmed(string $key): ?string {
		$value = $this->request->getParam(key: $key);
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end trimmed()

	/**
	 * Parse a value as a date.
	 *
	 * @param string|null $value The value, null when absent.
	 *
	 * @return DateTime|null The parsed date, or null when absent or unparseable.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function date(?string $value): ?DateTime {
		if ($value === null) {
			return null;
		}

		$date = null;
		try {
			$date = new DateTime($value);
		} catch (Throwable $e) {
			// An unparseable date filters nothing, as an absent one does.
		}

		return $date;
	}//end date()

	/**
	 * A non-negative integer parameter, clamped to a maximum.
	 *
	 * @param string $key     The parameter name.
	 * @param int    $default Used when absent, not numeric, or negative.
	 * @param int    $max     The upper clamp.
	 *
	 * @return int The value.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function boundedInt(string $key, int $default, int $max): int {
		$value = $this->request->getParam(key: $key);
		if ($value === null || is_numeric($value) === false) {
			return $default;
		}

		$int = (int)$value;
		if ($int < 0) {
			return $default;
		}

		return min($int, $max);
	}//end boundedInt()

	/**
	 * Every request parameter, as an array.
	 *
	 * @return array<string, mixed> The parameters.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function payload(): array {
		return (array)$this->request->getParams();
	}//end payload()
}//end class
