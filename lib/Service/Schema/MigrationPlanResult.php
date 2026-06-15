<?php

/**
 * MigrationPlanResult value object.
 *
 * The pure-PHP outcome of applying a migration transform chain to a single
 * object's data: the resulting data array, whether anything changed, the
 * ordered list of applied transform descriptions, and any per-transform
 * failure (e.g. an uncastable value). Carries no framework dependencies so
 * {@see SchemaMigrationPlanner} stays unit-testable.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

/**
 * Outcome of applying a transform chain to one object's data.
 */
final class MigrationPlanResult
{

    /**
     * The resulting data after applying the transform chain.
     *
     * @var array<string, mixed>
     */
    private array $data;

    /**
     * Whether the data changed relative to the input.
     *
     * @var bool
     */
    private bool $changed;

    /**
     * The failure reason, or null when the chain applied cleanly.
     *
     * @var string|null
     */
    private ?string $failure;

    /**
     * Ordered descriptions of the transforms that were applied.
     *
     * @var array<int, string>
     */
    private array $applied;


    /**
     * Constructor.
     *
     * @param array<string, mixed> $data    Resulting data.
     * @param bool                 $changed Whether data changed.
     * @param string|null          $failure Failure reason, or null.
     * @param array<int, string>   $applied Applied transform descriptions.
     */
    public function __construct(array $data, bool $changed, ?string $failure=null, array $applied=[])
    {
        $this->data    = $data;
        $this->changed = $changed;
        $this->failure = $failure;
        $this->applied = $applied;

    }//end __construct()


    /**
     * Get the resulting data.
     *
     * @return array<string, mixed> The data.
     */
    public function getData(): array
    {
        return $this->data;

    }//end getData()


    /**
     * Whether the data changed.
     *
     * @return bool True when changed.
     */
    public function isChanged(): bool
    {
        return $this->changed;

    }//end isChanged()


    /**
     * Whether the chain failed for this object.
     *
     * @return bool True when a transform failed.
     */
    public function isFailed(): bool
    {
        return $this->failure !== null;

    }//end isFailed()


    /**
     * Get the failure reason, if any.
     *
     * @return string|null The failure reason.
     */
    public function getFailure(): ?string
    {
        return $this->failure;

    }//end getFailure()


    /**
     * Get the applied transform descriptions.
     *
     * @return array<int, string> The descriptions.
     */
    public function getApplied(): array
    {
        return $this->applied;

    }//end getApplied()


}//end class
