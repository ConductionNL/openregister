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
	 * Construct a new DeletionAnalysis value object.
	 *
	 * @param bool                 $deletable  Whether the object may be deleted without violating RESTRICT constraints.
	 * @param DeletionEffects|null $effects    The cascade, set-null and set-default targets; none when null.
	 * @param array                $blockers   Dependent objects that block deletion (onDelete = RESTRICT).
	 * @param array                $chainPaths The full dependency chain path for each blocker or cascade target.
	 */
	public function __construct(
		public readonly bool $deletable,
		?DeletionEffects $effects = null,
		public readonly array $blockers = [],
		public readonly array $chainPaths = [],
	) {
		$effects ??= new DeletionEffects();

		$this->cascadeTargets = $effects->cascadeTargets;
		$this->nullifyTargets = $effects->nullifyTargets;
		$this->defaultTargets = $effects->defaultTargets;
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
		return new self(deletable: true);
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
