<?php

/**
 * The relation a link step writes: the property on the item's object and the
 * uuid of the object it now points at.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

/**
 * The relation a link step writes.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
 */
final class RelationLink {
	/**
	 * Constructor.
	 *
	 * @param string $relation The relation property on the item's object.
	 * @param string $uuid     The related object's uuid.
	 */
	public function __construct(
		public readonly string $relation,
		public readonly string $uuid,
	) {
	}//end __construct()
}//end class
