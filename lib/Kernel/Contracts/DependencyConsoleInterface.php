<?php

/**
 * DependencyConsoleInterface: the administrator's view of the dependency breakers.
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

/**
 * Lists the dependency breakers and resets one.
 *
 * The operations console (Configuration) serves it; load shedding (Platform)
 * answers it.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
interface DependencyConsoleInterface {

	/**
	 * Every breaker that has state, the database pressure, and whether the state is shared.
	 *
	 * @return array{dependencies: list<array<string, mixed>>, pressure: array<string, mixed>, shared: bool}
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function overview(): array;

	/**
	 * Close a breaker and audit it, naming the administrator.
	 *
	 * @param string $key The breaker key.
	 *
	 * @return array<string, mixed>|null The breaker's state after the reset, or null for an unknown key.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function reset(string $key): ?array;
}//end interface
