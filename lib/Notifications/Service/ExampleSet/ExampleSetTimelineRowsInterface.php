<?php

/**
 * OpenRegister Example Set Timeline Rows Interface
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-timeline-entries-by-named-people
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows;

/**
 * Where the example-set engine finds the writer of a fragment's timeline entries.
 *
 * The engine drives every part's writer through ExampleSetEngineRows; this names the one that writes
 * timeline entries, so the engine does not have to name the Notifications class that does it.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-timeline-entries-by-named-people
 */
interface ExampleSetTimelineRowsInterface {
	/**
	 * The writer of the timeline entries a fragment gives its objects.
	 *
	 * @return ExampleSetEngineRows
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-timeline-entries-by-named-people
	 */
	public function rows(): ExampleSetEngineRows;
}//end interface
