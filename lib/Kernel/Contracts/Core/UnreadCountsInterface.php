<?php
/**
 * UnreadCountsInterface: what the signed-in caller has not yet seen of an object.
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

/**
 * The unread badge the object API (Core) puts on a single-object read (`@self.unreadCounts`).
 *
 * Processes keeps the per-user read state and answers this contract; its
 * ReadStateServiceInterface extends it and its own wiring binds it.
 *
 * @spec openspec/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
 */
interface UnreadCountsInterface {

	/**
	 * How many entries of each sub-resource are unread for the calling user.
	 *
	 * One map, from one read state row plus the object's own body, so a page
	 * renders every tab badge without a call per tab. A sub-resource the schema
	 * does not declare is absent from the map rather than zero, because "no
	 * badge" and "a badge reading nought" are different claims. An anonymous
	 * caller, or an object without a uuid, has nothing unread: the map is empty.
	 *
	 * @param ObjectEntity $object The object being read.
	 *
	 * @return array<string, int> Sub-resource name to its unread count.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	public function unreadCounts(ObjectEntity $object): array;
}//end interface
