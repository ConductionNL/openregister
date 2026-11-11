<?php

/**
 * OpenRegister Example Set View Rows Interface
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows;

/**
 * Where the example-set engine finds the writer of a fragment's saved views.
 *
 * The engine drives every part's writer through ExampleSetEngineRows; this names the one that writes views,
 * so the engine does not have to name the Search class that does it.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
interface ExampleSetViewRowsInterface {
	/**
	 * The writer of the saved views a fragment declares.
	 *
	 * @return ExampleSetEngineRows
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function rows(): ExampleSetEngineRows;
}//end interface
