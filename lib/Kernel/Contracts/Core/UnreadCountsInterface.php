<?php
/**
 * UnreadCountsInterface: how many entries of each sub-resource one reader has not seen.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectReadState;

/**
 * The unread count per sub-resource of an object, as the read state (Core) asks it for the tab badges.
 *
 * Core asks; the counter that knows an object's declared sub-resources
 * (Processes) answers and binds itself outside Core.
 *
 * @spec openspec/specs/object-read-state/spec.md
 */
interface UnreadCountsInterface {

	/**
	 * How many entries of each sub-resource are unread for one reader.
	 *
	 * A sub-resource the schema does not declare is absent from the map rather than zero.
	 *
	 * @param ObjectEntity         $object The object being rendered.
	 * @param ObjectReadState|null $state  The reader's row, or null when never seen.
	 *
	 * @return array<string, int> Sub-resource name to its unread count.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	public function countsFor(ObjectEntity $object, ?ObjectReadState $state): array;
}//end interface
