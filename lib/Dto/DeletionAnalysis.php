<?php

/**
 * OpenRegister DeletionAnalysis DTO
 *
 * Value object representing the result of a pre-flight deletion analysis.
 * Contains information about what would happen if an object were deleted,
 * including cascade targets, nullification targets, and blockers.
 *
 * @category Dto
 * @package  OCA\OpenRegister\Dto
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Dto;

/**
 * Value object representing the analysis of what would happen when an object is deleted.
 *
 * @category Dto
 * @package  OCA\OpenRegister\Dto
 */
class DeletionAnalysis
{
    /**
     * Constructor for DeletionAnalysis.
     *
     * @param bool  $deletable      Whether the object can be deleted without violating constraints.
     * @param array $cascadeTargets Objects that would be cascade-deleted.
     * @param array $nullifyTargets Objects that would have their reference set to null.
     * @param array $defaultTargets Objects that would have their reference set to default value.
     * @param array $blockers       Objects that block the deletion (RESTRICT).
     * @param array $chainPaths     Full graph paths for debugging.
     */
    public function __construct(
        public readonly bool $deletable,
        public readonly array $cascadeTargets=[],
        public readonly array $nullifyTargets=[],
        public readonly array $defaultTargets=[],
        public readonly array $blockers=[],
        public readonly array $chainPaths=[]
    ) {
    }//end __construct()

    /**
     * Create an empty deletable analysis with no targets or blockers.
     *
     * @return self A deletable analysis with empty target lists.
     */
    public static function empty(): self
    {
        return new self(deletable: true);
    }//end empty()

    /**
     * Convert the analysis to an array suitable for JSON serialization.
     *
     * @return array The analysis as an associative array.
     */
    public function toArray(): array
    {
        return [
            'deletable'      => $this->deletable,
            'cascadeTargets' => $this->cascadeTargets,
            'nullifyTargets' => $this->nullifyTargets,
            'defaultTargets' => $this->defaultTargets,
            'blockers'       => $this->blockers,
            'chainPaths'     => $this->chainPaths,
        ];
    }//end toArray()
}//end class
