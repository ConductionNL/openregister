<?php

/**
 * OpenRegister CircularReferenceException
 *
 * Validation failure raised when a save operation contains a circular
 * reference chain — for example object A's payload nests object B
 * which nests object A. Subclasses `ValidationException` so existing
 * 422 handlers route it correctly while exposing the visited cycle
 * (the in-flight `(register, schema, uuid)` chain that triggered the
 * detection) for actionable client error UI.
 *
 * Closes the `reference-existence-validation` spec requirement:
 * "Circular reference chains detected during validation."
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reference-existence-validation/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Throwable;

/**
 * Cycle-detection variant of `ValidationException` for the
 * reference-existence path.
 */
class CircularReferenceException extends ValidationException
{
    /**
     * Constructor.
     *
     * @param string         $referencedUuid   The UUID whose re-entry triggered detection.
     * @param string         $targetSchemaSlug Slug of the target schema (or raw `$ref`).
     * @param array          $cycle            Chain of (register, schema, uuid) entries that caused the cycle.
     * @param string|null    $message          Optional override for the human-readable message.
     * @param int            $code             HTTP status code (default 422).
     * @param Throwable|null $previous         Previous exception in the chain.
     */
    public function __construct(
        private readonly string $referencedUuid,
        private readonly string $targetSchemaSlug,
        private readonly array $cycle=[],
        ?string $message=null,
        int $code=422,
        ?Throwable $previous=null
    ) {
        if ($message === null || $message === '') {
            $message = sprintf(
                "Circular reference detected for object '%s' in schema '%s'",
                $referencedUuid,
                $targetSchemaSlug
            );
        }

        parent::__construct(
            message: $message,
            code: $code,
            previous: $previous
        );

    }//end __construct()

    /**
     * The UUID whose re-entry triggered the cycle detection.
     *
     * @return string The UUID at the closing edge of the cycle.
     */
    public function getReferencedUuid(): string
    {
        return $this->referencedUuid;

    }//end getReferencedUuid()

    /**
     * Slug (or raw `$ref`) of the schema involved in the cycle.
     *
     * @return string Target schema slug or raw `$ref`.
     */
    public function getTargetSchemaSlug(): string
    {
        return $this->targetSchemaSlug;

    }//end getTargetSchemaSlug()

    /**
     * The cycle chain that triggered the detection.
     *
     * @return array<int, array{register?:string|null,schema:string,uuid:string}> Visited stack.
     */
    public function getCycle(): array
    {
        return $this->cycle;

    }//end getCycle()

    /**
     * Render the diagnostic data as a structured array.
     *
     * @return array{
     *     referencedUuid: string,
     *     targetSchemaSlug: string,
     *     cycle: array<int, array<string, mixed>>,
     *     message: string,
     *     code: int
     * }
     */
    public function toArray(): array
    {
        return [
            'referencedUuid'   => $this->referencedUuid,
            'targetSchemaSlug' => $this->targetSchemaSlug,
            'cycle'            => $this->cycle,
            'message'          => $this->getMessage(),
            'code'             => $this->getCode(),
        ];

    }//end toArray()
}//end class
