<?php
/**
 * ObjectUnreadMarkerInterface: whether the reader has seen an object, as a render tells it.
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

/**
 * The `@self.unread` marker a render puts on an object.
 *
 * The render (Core) asks; the read-state service, which keeps what each user has
 * seen, answers and is bound to this contract in its own area's wiring.
 *
 * @spec openspec/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
 */
interface ObjectUnreadMarkerInterface {

	/**
	 * The uid of the caller of this request.
	 *
	 * @return string|null The uid, or null when anonymous.
	 *
	 * @spec openspec/specs/object-read-state/spec.md
	 */
	public function callerUid(): ?string;

	/**
	 * Whether the caller has not seen the object since its last change.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return bool True when the object is unread for the caller.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	public function isUnreadForCaller(string $objectUuid): bool;
}//end interface
