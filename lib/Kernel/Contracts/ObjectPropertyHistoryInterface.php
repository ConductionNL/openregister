<?php

/**
 * ObjectPropertyHistoryInterface: the per-property history of an object, for areas outside Core.
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
 * Counting and dropping the recorded intervals of an object's properties.
 *
 * Compliance (anonymisation) reaches the Core state-history projection through
 * this port; the Core registrar binds it to the state-history mapper.
 */
interface ObjectPropertyHistoryInterface {

	/**
	 * How many intervals of one object a set of properties holds.
	 *
	 * @param string   $objectUuid The object.
	 * @param string[] $properties The properties.
	 *
	 * @return int The number of intervals.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
	 */
	public function countForObjectProperties(string $objectUuid, array $properties): int;

	/**
	 * Drop every interval of one object for a set of properties, open or closed.
	 *
	 * Used by an anonymisation: the intervals hold the former values of the
	 * properties it changed, so they leave with the values.
	 *
	 * @param string   $objectUuid The object.
	 * @param string[] $properties The properties.
	 *
	 * @return int Rows removed.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
	 */
	public function deleteForObjectProperties(string $objectUuid, array $properties): int;
}//end interface
