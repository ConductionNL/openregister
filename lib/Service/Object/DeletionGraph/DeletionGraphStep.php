<?php

/**
 * One reference from a dependent object to an object the walk entered.
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
 * A referencing object found for one relation of a node, with the chain extended by this step.
 */
final readonly class DeletionGraphStep {

	/**
	 * The node's chain plus this step.
	 *
	 * @var array<int, string>
	 */
	public array $chain;

	/**
	 * Constructor.
	 *
	 * @param DeletionGraphNode    $node        The node whose object is referenced.
	 * @param array<string, mixed> $dep         The relation index entry.
	 * @param ObjectEntity         $referencing The object that holds the reference.
	 */
	public function __construct(
		public DeletionGraphNode $node,
		public array $dep,
		public ObjectEntity $referencing,
	) {
		$this->chain = array_merge(
			$node->chain,
			["{$node->object->getUuid()} → {$referencing->getUuid()} ({$dep['onDelete']})"]
		);
	}//end __construct()
}//end class
