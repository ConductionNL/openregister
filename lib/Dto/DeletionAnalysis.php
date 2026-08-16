<?php

/**
 * OpenRegister Deletion Analysis DTO
 *
 * Value object returned by the referential integrity service after walking the
 * deletion dependency graph for a given object. Contains the full analysis:
 * whether the object is deletable, which dependent objects will be cascaded /
 * nullified / set to their default, and which blockers prevent deletion.
 *
 * @category Dto
 * @package  OCA\OpenRegister\Dto
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openregister-legacy-quality-cleanup/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Dto;

/**
 * Value object representing the result of a deletion dependency-graph walk.
 *
 * @category Dto
 * @package  OCA\OpenRegister\Dto
 */
class DeletionAnalysis {

	/**
	 * Whether the object may be deleted without violating RESTRICT constraints.
	 *
	 * @var boolean
	 */
	public readonly bool $deletable;

	/**
	 * Dependent objects that will be cascade-deleted alongside the root.
	 *
	 * @var array
	 */
	public readonly array $cascadeTargets;

	/**
	 * Dependent objects whose reference property will be set to null.
	 *
	 * @var array
	 */
	public readonly array $nullifyTargets;

	/**
	 * Dependent objects whose reference property will be set to its schema default.
	 *
	 * @var array
	 */
	public readonly array $defaultTargets;

	/**
	 * Dependent objects that block deletion (onDelete = RESTRICT).
	 *
	 * @var array
	 */
	public readonly array $blockers;

	/**
	 * The full dependency chain path for each blocker or cascade target.
	 *
	 * @var array
	 */
	public readonly array $chainPaths;

	/**
	 * Construct a new DeletionAnalysis value object.
	 *
	 * @param bool $deletable Whether the object may be deleted without violating RESTRICT constraints.
	 * @param array $cascadeTargets Dependent objects that will be cascade-deleted alongside the root.
	 * @param array $nullifyTargets Dependent objects whose reference property will be set to null.
	 * @param array $defaultTargets Dependent objects whose reference property will be set to its schema default.
	 * @param array $blockers Dependent objects that block deletion (onDelete = RESTRICT).
	 * @param array $chainPaths The full dependency chain path for each blocker or cascade target.
	 */
	public function __construct(
		bool $deletable,
		array $cascadeTargets = [],
		array $nullifyTargets = [],
		array $defaultTargets = [],
		array $blockers = [],
		array $chainPaths = [],
	) {
		$this->deletable = $deletable;
		$this->cascadeTargets = $cascadeTargets;
		$this->nullifyTargets = $nullifyTargets;
		$this->defaultTargets = $defaultTargets;
		$this->blockers = $blockers;
		$this->chainPaths = $chainPaths;
	}//end __construct()

	/**
	 * Return an empty analysis indicating no dependencies were found.
	 *
	 * Used as a safe early-return value when no referential-integrity configuration
	 * exists for the object's schema, or when a recursion limit / cycle is hit.
	 *
	 * @return self
	 */
	public static function empty(): self {
		return new self(
			deletable: true,
			cascadeTargets: [],
			nullifyTargets: [],
			defaultTargets: [],
			blockers: [],
			chainPaths: []
		);
	}//end empty()

	/**
	 * Convert the analysis to an array suitable for JSON serialization.
	 *
	 * @return array<string,mixed> The analysis as an associative array.
	 */
	public function toArray(): array {
		return [
			'deletable' => $this->deletable,
			'cascadeTargets' => $this->cascadeTargets,
			'nullifyTargets' => $this->nullifyTargets,
			'defaultTargets' => $this->defaultTargets,
			'blockers' => $this->blockers,
			'chainPaths' => $this->chainPaths,
		];
	}//end toArray()
}//end class
