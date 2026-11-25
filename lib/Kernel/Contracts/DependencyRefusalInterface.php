<?php

/**
 * DependencyRefusalInterface: an open breaker's refusal, as callers in every area catch it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use Throwable;

/**
 * Thrown while a dependency's breaker is open; the call was not made.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
interface DependencyRefusalInterface extends Throwable {

	/**
	 * The dependency class: `database`, `llm`, `source`, `outbound`, `webhook`.
	 *
	 * @return string The class.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function dependency(): string;

	/**
	 * Seconds until the dependency may be tried again (at least 1).
	 *
	 * @return int The seconds.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function retryAfter(): int;
}//end interface
