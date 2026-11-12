<?php

/**
 * OpenRegister GraphWalk
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

namespace OCA\OpenRegister\Service\Relation;

/**
 * One RelationGraphService::graph() walk: what it reached so far, and the level it is on.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class GraphWalk {

	/**
	 * The nodes reached, by UUID.
	 *
	 * @var array
	 */
	public array $nodes = [];

	/**
	 * The edges walked, in order.
	 *
	 * @var array
	 */
	public array $edges = [];

	/**
	 * The edges already walked, by from, to, label and direction.
	 *
	 * @var array
	 */
	public array $seenEdges = [];

	/**
	 * The UUIDs already reached.
	 *
	 * @var array
	 */
	public array $visited = [];

	/**
	 * The UUIDs the next level walks out from.
	 *
	 * @var array
	 */
	public array $next = [];

	/**
	 * The root UUID.
	 *
	 * @var string
	 */
	public string $root = '';

	/**
	 * The UUIDs the current level walks out from.
	 *
	 * @var array
	 */
	public array $frontier = [];

	/**
	 * The language edge labels are read in.
	 *
	 * @var string
	 */
	public string $language = 'nl';

	/**
	 * The administered maximum depth.
	 *
	 * @var integer
	 */
	public int $maxDepth = 1;

	/**
	 * The depth the caller asked for, at least one.
	 *
	 * @var integer
	 */
	public int $requestedDepth = 1;

	/**
	 * The depth walked: the requested depth, capped at the maximum.
	 *
	 * @var integer
	 */
	public int $depth = 1;

	/**
	 * The administered node cap.
	 *
	 * @var integer
	 */
	public int $maxNodes = 0;

	/**
	 * The level being walked, from zero.
	 *
	 * @var integer
	 */
	public int $level = 0;

	/**
	 * What stopped the walk early, if anything.
	 *
	 * @var string|null
	 */
	public ?string $truncatedBy = null;
}//end class
