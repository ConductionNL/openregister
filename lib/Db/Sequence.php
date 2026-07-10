<?php

/**
 * Sequence entity — a single running-number counter scoped to a
 * (register, schema, scope_key) triple.
 *
 * Backs the declarative `sequence` calculation operator: a leaf app declares
 * `{ "sequence": { "scope": "yearly", "pad": 4 } }` and OpenRegister hands out
 * a stable, atomic, never-reused running number on object CREATE (e.g. the
 * `0042` in `2026-0042`). `next_value` always points at the number that will
 * be handed out next; it is incremented under a row lock by SequenceService.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
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
 * Class Sequence
 *
 * @method int|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string|null getScopeKey()
 * @method void setScopeKey(?string $scopeKey)
 * @method int|null getNextValue()
 * @method void setNextValue(?int $nextValue)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class Sequence extends Entity implements JsonSerializable
{

    /**
     * The register the sequence is scoped to.
     *
     * @var integer|null
     */
    protected ?int $registerId = null;

    /**
     * The schema the sequence is scoped to.
     *
     * @var integer|null
     */
    protected ?int $schemaId = null;

    /**
     * The scope discriminator (e.g. the year "2026", "2026-06" or "" for global).
     *
     * @var string|null
     */
    protected ?string $scopeKey = null;

    /**
     * The next value to hand out (always >= 1).
     *
     * @var integer|null
     */
    protected ?int $nextValue = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'registerId', type: 'integer');
        $this->addType(fieldName: 'schemaId', type: 'integer');
        $this->addType(fieldName: 'scopeKey', type: 'string');
        $this->addType(fieldName: 'nextValue', type: 'integer');
    }//end __construct()

    /**
     * JSON serialization.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->id,
            'registerId' => $this->registerId,
            'schemaId'   => $this->schemaId,
            'scopeKey'   => $this->scopeKey,
            'nextValue'  => $this->nextValue,
        ];
    }//end jsonSerialize()
}//end class
