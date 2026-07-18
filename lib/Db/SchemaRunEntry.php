<?php

/**
 * SchemaRunEntry entity — one per-object result row of a schema run.
 *
 * Stored in a side table so a run's per-object detail (validation errors,
 * migration outcome, pre/post content-version ids for rollback) scales to
 * arbitrarily large populations without bloating the run row.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class SchemaRunEntry
 *
 * @method int getRunId()
 * @method void setRunId(int $runId)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string|null getOutcome()
 * @method void setOutcome(?string $outcome)
 * @method string|null getMessage()
 * @method void setMessage(?string $message)
 * @method string|null getPreVersion()
 * @method void setPreVersion(?string $preVersion)
 * @method string|null getPostVersion()
 * @method void setPostVersion(?string $postVersion)
 * @method array|null getPreData()
 * @method void setPreData(?array $preData)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class SchemaRunEntry extends Entity implements JsonSerializable
{

    /**
     * Outcome constants.
     *
     * @var string
     */
    public const OUTCOME_VALID     = 'valid';
    public const OUTCOME_INVALID   = 'invalid';
    public const OUTCOME_MIGRATED  = 'migrated';
    public const OUTCOME_UNCHANGED = 'unchanged';
    public const OUTCOME_FAILED    = 'failed';
    public const OUTCOME_RESTORED  = 'restored';
    public const OUTCOME_CONFLICT  = 'conflict';

    /**
     * The run this entry belongs to.
     *
     * @var integer|null
     */
    protected ?int $runId = null;

    /**
     * The object's UUID.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * The outcome for this object.
     *
     * @var string|null
     */
    protected ?string $outcome = null;

    /**
     * A human-readable message (validation errors, cast failure reason).
     *
     * @var string|null
     */
    protected ?string $message = null;

    /**
     * Pre-migration content version id (for rollback).
     *
     * @var string|null
     */
    protected ?string $preVersion = null;

    /**
     * Post-migration content version id (rollback conflict detection).
     *
     * @var string|null
     */
    protected ?string $postVersion = null;

    /**
     * Pre-migration object data snapshot (restored forward on rollback).
     *
     * @var array<string, mixed>|null
     */
    protected ?array $preData = null;

    /**
     * Constructor — registers field types for hydration.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'runId', type: 'integer');
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'outcome', type: 'string');
        $this->addType(fieldName: 'message', type: 'string');
        $this->addType(fieldName: 'preVersion', type: 'string');
        $this->addType(fieldName: 'postVersion', type: 'string');
        $this->addType(fieldName: 'preData', type: 'json');

    }//end __construct()

    /**
     * The field names registered with the 'json' type.
     *
     * @return array<int, string> The json-typed field names.
     */
    public function getJsonFields(): array
    {
        return array_keys(
            array_filter(
                $this->getFieldTypes(),
                static function ($field) {
                    return $field === 'json';
                }
            )
        );
    }//end getJsonFields()

    /**
     * Hydrate the entity from an array.
     *
     * Without this, SchemaRunEntryMapper's `$entity->hydrate()` call hit
     * Entity::__call and threw "hydrate does not exist", so every per-object
     * migration entry write failed.
     *
     * @param array<string, mixed> $object The source data.
     *
     * @return static This entity, hydrated.
     *
     * @spec openspec/changes/schema-versioning-and-object-migration/specs/schema-migration/spec.md
     */
    public function hydrate(array $object): static
    {
        $jsonFields = $this->getJsonFields();

        foreach ($object as $key => $value) {
            if (in_array($key, $jsonFields, true) === true && $value === []) {
                $value = null;
            }

            $method = 'set'.ucfirst($key);

            try {
                $this->$method($value);
            } catch (\Exception $exception) {
                // Silently ignore invalid properties.
            }
        }

        return $this;
    }//end hydrate()

    /**
     * JSON serialisation.
     *
     * @return array<string, mixed> The serialised entry.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'          => $this->id,
            'runId'       => $this->runId,
            'objectUuid'  => $this->objectUuid,
            'outcome'     => $this->outcome,
            'message'     => $this->message,
            'preVersion'  => $this->preVersion,
            'postVersion' => $this->postVersion,
            'preData'     => $this->preData,
        ];

    }//end jsonSerialize()
}//end class
