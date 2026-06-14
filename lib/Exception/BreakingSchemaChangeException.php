<?php

/**
 * BreakingSchemaChangeException.
 *
 * Thrown by the schema-update path when a definition change is classified
 * `breaking` and the request did not carry `acknowledgeBreaking: true`.
 * Carries the classification, the typed change list and (when known) the
 * count of objects currently invalid under the latest revalidation, so the
 * controller can return the structured HTTP 409 contract.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
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

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Signals an unacknowledged breaking schema change.
 */
class BreakingSchemaChangeException extends Exception
{

    /**
     * The typed change list that triggered the gate.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $changes;

    /**
     * Count of objects invalid under the latest revalidation (or null).
     *
     * @var int|null
     */
    private ?int $invalidCount;


    /**
     * Constructor.
     *
     * @param array<int, array<string, mixed>> $changes      The typed change list.
     * @param int|null                         $invalidCount Latest invalid-object count, or null.
     */
    public function __construct(array $changes, ?int $invalidCount=null)
    {
        parent::__construct('Schema change classified breaking; acknowledgeBreaking required.');
        $this->changes      = $changes;
        $this->invalidCount = $invalidCount;

    }//end __construct()


    /**
     * Get the structured 409 response body.
     *
     * @return array<string, mixed> The response body.
     */
    public function toResponse(): array
    {
        $body = [
            'error'          => $this->getMessage(),
            'classification' => 'breaking',
            'changes'        => $this->changes,
        ];

        if ($this->invalidCount !== null) {
            $body['invalidCount'] = $this->invalidCount;
        }

        return $body;

    }//end toResponse()


    /**
     * Get the typed change list.
     *
     * @return array<int, array<string, mixed>> The changes.
     */
    public function getChanges(): array
    {
        return $this->changes;

    }//end getChanges()


}//end class
