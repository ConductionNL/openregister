<?php

/**
 * One walk down the tree below a root object.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

/**
 * The state of one descent: the walk it follows, the nodes found so far, the
 * uuids already in the answer, the frontier to read below and the level being read.
 *
 * Built and used by ObjectTreeService only; it never leaves that class.
 *
 * @spec openspec/changes/object-tree-read/specs/referential-integrity/spec.md
 */
final class TreeDescentRun {

	/**
	 * The nodes found so far, in walk order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $nodes = [];

	/**
	 * The uuids already in the answer, the root included.
	 *
	 * @var array<string, bool>
	 */
	public array $seen;

	/**
	 * The uuids whose children the next level reads.
	 *
	 * @var array<int, string>
	 */
	public array $frontier;

	/**
	 * The level being read; the root is level 0.
	 *
	 * @var int
	 */
	public int $level = 0;

	/**
	 * Start a descent at the root.
	 *
	 * @param array<string, mixed> $walk     The walk, with `table`, `column` and `cap`.
	 * @param string               $rootUuid The root.
	 */
	public function __construct(
		public readonly array $walk,
		string $rootUuid,
	) {
		$this->seen     = [$rootUuid => true];
		$this->frontier = [$rootUuid];
	}//end __construct()
}//end class
