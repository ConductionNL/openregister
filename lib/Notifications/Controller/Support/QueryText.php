<?php

/**
 * OpenRegister query text
 *
 * One optional, non-empty string read off a request's query parameters.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller\Support;

/**
 * Reads optional, trimmed, non-empty strings off a request's parameters.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
final class QueryText {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function __construct(
		private readonly array $params,
	) {
	}//end __construct()

	/**
	 * The trimmed value of one key, or null when absent, empty or not a string.
	 *
	 * @param string $key The key.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function value(string $key): ?string {
		if (isset($this->params[$key]) === false || is_string($this->params[$key]) === false) {
			return null;
		}

		$value = trim($this->params[$key]);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end value()
}//end class
