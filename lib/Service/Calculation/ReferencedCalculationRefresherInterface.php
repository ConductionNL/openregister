<?php

/**
 * ReferencedCalculationRefresherInterface: what callers outside the component use of ReferencedCalculationRefresher.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Refresh the calculations that read a changed object through a reference.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ReferencedCalculationRefresherInterface {

	/**
	 * Re-save the objects in the updated object's register whose materialised calculations read a changed field of it.
	 *
	 * @param ObjectEntity      $updated The object as saved.
	 * @param ObjectEntity|null $old     The object before the save, null when unknown (every field counts as changed).
	 *
	 * @return integer The number of objects re-saved.
	 *
	 * @spec openspec/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
	 */
	public function refresh(ObjectEntity $updated, ?ObjectEntity $old): int;
}//end interface
