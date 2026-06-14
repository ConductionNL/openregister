<?php

/**
 * SchemaChangeSet value object.
 *
 * An immutable, pure-PHP description of the structural difference between
 * two schema definitions: the typed list of property/constraint changes,
 * the overall classification (`compatible` | `breaking`) and the derived
 * semantic-version bump level (`major` | `minor` | `patch` | `none`).
 *
 * This object carries no database, framework or service dependencies so
 * the diff + classification logic in {@see SchemaDiffService} can be unit
 * tested without a live Nextcloud container.
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

use JsonSerializable;

/**
 * Immutable structural diff of two schema definitions.
 *
 * @phpstan-type Change array{property: string, kind: string, old?: mixed, new?: mixed}
 */
final class SchemaChangeSet implements JsonSerializable
{

    /**
     * Classification: no definition change at all (metadata-only save).
     *
     * @var string
     */
    public const CLASS_NONE = 'none';

    /**
     * Classification: additive / relaxing change, safe for existing data.
     *
     * @var string
     */
    public const CLASS_COMPATIBLE = 'compatible';

    /**
     * Classification: removes/renames/tightens — may invalidate existing data.
     *
     * @var string
     */
    public const CLASS_BREAKING = 'breaking';

    /**
     * The typed list of changes.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $changes;

    /**
     * The overall classification.
     *
     * @var string
     */
    private string $classification;

    /**
     * The derived version bump level (`major` | `minor` | `patch` | `none`).
     *
     * @var string
     */
    private string $bump;


    /**
     * Constructor.
     *
     * @param array<int, array<string, mixed>> $changes        Typed change list.
     * @param string                           $classification One of the CLASS_* constants.
     * @param string                           $bump           One of major|minor|patch|none.
     */
    public function __construct(array $changes, string $classification, string $bump)
    {
        $this->changes        = array_values($changes);
        $this->classification = $classification;
        $this->bump           = $bump;

    }//end __construct()


    /**
     * Get the typed change list.
     *
     * @return array<int, array<string, mixed>> The changes.
     */
    public function getChanges(): array
    {
        return $this->changes;

    }//end getChanges()


    /**
     * Get the classification.
     *
     * @return string The classification (CLASS_* constant).
     */
    public function getClassification(): string
    {
        return $this->classification;

    }//end getClassification()


    /**
     * Get the derived semantic-version bump level.
     *
     * @return string One of major|minor|patch|none.
     */
    public function getBump(): string
    {
        return $this->bump;

    }//end getBump()


    /**
     * Whether the change set is classified breaking.
     *
     * @return bool True when breaking.
     */
    public function isBreaking(): bool
    {
        return $this->classification === self::CLASS_BREAKING;

    }//end isBreaking()


    /**
     * Whether there is any structural change at all.
     *
     * @return bool True when the definitions differ structurally.
     */
    public function hasChanges(): bool
    {
        return count($this->changes) > 0;

    }//end hasChanges()


    /**
     * JSON serialisation.
     *
     * @return array<string, mixed> The serialised change set.
     *
     * @spec openspec/changes/schema-versioning-and-object-migration/specs/schema-migration/spec.md
     */
    public function jsonSerialize(): array
    {
        return [
            'classification' => $this->classification,
            'bump'           => $this->bump,
            'changes'        => $this->changes,
        ];

    }//end jsonSerialize()


}//end class
