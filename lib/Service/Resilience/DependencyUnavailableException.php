<?php

/**
 * The refusal an open circuit breaker answers with: which dependency, and when to retry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Resilience
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Resilience;

use RuntimeException;

/**
 * Thrown instead of calling a dependency whose breaker is open.
 *
 * The message names only the dependency's class (`database`, `llm`, `source`,
 * `outbound`, `webhook`), never the key's host or source id: the refusal can
 * reach an anonymous caller on a public route.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
class DependencyUnavailableException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $key        The breaker key, for example `source:12` or `outbound:brp.example`.
	 * @param int    $retryAfter Seconds until the dependency may be tried again (at least 1).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $key,
		private readonly int $retryAfter,
	) {
		parent::__construct(message: sprintf('The %s dependency is not answering. Try again in %d seconds.', self::classOf(key: $key), $this->retryAfter()));
	}//end __construct()

	/**
	 * The dependency class of a breaker key: the part before the first colon.
	 *
	 * @param string $key The breaker key.
	 *
	 * @return string One of `database`, `llm`, `source`, `outbound`, `webhook`, or the bare key.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public static function classOf(string $key): string {
		return explode(':', $key, 2)[0];
	}//end classOf()

	/**
	 * The dependency class this refusal is about.
	 *
	 * @return string The class.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function dependency(): string {
		return self::classOf(key: $this->key);
	}//end dependency()

	/**
	 * Seconds until the dependency may be tried again, never less than 1.
	 *
	 * @return int The seconds.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function retryAfter(): int {
		return max(1, $this->retryAfter);
	}//end retryAfter()
}//end class
