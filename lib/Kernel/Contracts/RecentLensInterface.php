<?php

/**
 * RecentLensInterface: the "recently opened" lens of the read history, for callers outside Core to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The read history's `_recent` lens, on its own: whether it can answer, why not, and the history.
 *
 * @spec openspec/specs/object-interactions/spec.md#requirement-the-recent-lens-says-why-it-is-empty
 */
interface RecentLensInterface {

	/**
	 * The `_recent` lens for one caller: whether it can answer, why not, and the history.
	 *
	 * @param string|null $userId The caller's uid, or null when anonymous.
	 *
	 * @return array{available: bool, reason: string|null, views: array<string, string>}
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-the-recent-lens-says-why-it-is-empty
	 */
	public function resolveRecentLens(?string $userId): array;
}//end interface
