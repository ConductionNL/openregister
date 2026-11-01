<?php

/**
 * An object the deletion graph walk enters.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\DeletionGraph
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\DeletionGraph;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * An object the walk enters, with the chain that led to it and its depth below the root.
 */
final readonly class DeletionGraphNode {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity              $object The object that would be deleted.
	 * @param DeletionGraphWalk         $walk   The walk this node belongs to.
	 * @param array<int, string>        $chain  The steps from the root to this object.
	 * @param int                       $depth  The depth below the root.
	 */
	public function __construct(
		public ObjectEntity $object,
		public DeletionGraphWalk $walk,
		public array $chain = [],
		public int $depth = 0,
	) {
	}//end __construct()
}//end class
