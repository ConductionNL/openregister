<?php

/**
 * UniqueHintWarningsInterface: the part of UniqueHintWarnings that the objects controller uses through its governance bundle.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
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

namespace OCA\OpenRegister\Service\Quality;

/**
 * The methods of UniqueHintWarnings that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface UniqueHintWarningsInterface {

	/**
	 * Take everything collected so far and forget it.
	 *
	 * @return array<int, array{property: string, matches: array<int, string>, visible: bool}> The warnings.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function drain(): array;
}//end interface
