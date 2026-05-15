<?php

/**
 * OpenRegister ReferenceValidationException
 *
 * Validation failure raised when a `validateReference: true` property
 * points at a UUID that does not exist in its target schema. Subclasses
 * `ValidationException` so existing 422 handlers route it correctly,
 * while exposing structured fields (`propertyName`, `referencedUuid`,
 * `targetSchemaSlug`, `targetRegister`) so API clients can render
 * actionable error messages without parsing the human-readable string.
 *
 * Closes the reference-existence-validation spec requirement:
 * "Validation error reporting includes structured diagnostic information."
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Throwable;

/**
 * Structured-diagnostic variant of `ValidationException` for the
 * reference-existence path.
 */
class ReferenceValidationException extends ValidationException
{
    /**
     * Constructor.
     *
     * @param string         $propertyName     The schema property name
     *                                         carrying the broken
     *                                         reference.
     * @param string         $referencedUuid   The UUID that did not resolve.
     * @param string         $targetSchemaSlug Slug of the target schema
     *                                         (or the raw `$ref` value
     *                                         if the slug couldn't be
     *                                         resolved).
     * @param string|null    $targetRegister   The register the reference
     *                                         was searched in, or null
     *                                         when no register context
     *                                         applies.
     * @param string|null    $message          Optional override for the
     *                                         human-readable message;
     *                                         defaults to a structured
     *                                         sentence built from the
     *                                         fields above.
     * @param int            $code             HTTP status code (default
     *                                         422 — Unprocessable
     *                                         Entity).
     * @param Throwable|null $previous         The previous exception
     *                                         that triggered this
     *                                         one.
     */
    public function __construct(
        private readonly string $propertyName,
        private readonly string $referencedUuid,
        private readonly string $targetSchemaSlug,
        private readonly ?string $targetRegister=null,
        ?string $message=null,
        int $code=422,
        ?Throwable $previous=null
    ) {
        if ($message === null || $message === '') {
            $message = sprintf(
                "Referenced object '%s' not found in schema '%s' for property '%s'",
                $referencedUuid,
                $targetSchemaSlug,
                $propertyName
            );
        }

        parent::__construct(
            message: $message,
            code: $code,
            previous: $previous
        );

    }//end __construct()

    /**
     * Schema property name that holds the broken reference.
     *
     * @return string Property name as declared on the schema.
     */
    public function getPropertyName(): string
    {
        return $this->propertyName;

    }//end getPropertyName()

    /**
     * The UUID value that failed to resolve.
     *
     * @return string The unresolved UUID.
     */
    public function getReferencedUuid(): string
    {
        return $this->referencedUuid;

    }//end getReferencedUuid()

    /**
     * Slug (or raw `$ref`) of the schema the reference targets.
     *
     * @return string Target schema slug or raw `$ref`.
     */
    public function getTargetSchemaSlug(): string
    {
        return $this->targetSchemaSlug;

    }//end getTargetSchemaSlug()

    /**
     * Register the reference was searched in.
     *
     * @return string|null Register identifier or null when no register
     *                     context applied to the lookup.
     */
    public function getTargetRegister(): ?string
    {
        return $this->targetRegister;

    }//end getTargetRegister()

    /**
     * Render the diagnostic data as a structured array.
     *
     * Useful for controllers that surface the exception as a JSON 422
     * envelope — clients can render actionable error UI without parsing
     * the human-readable message.
     *
     * @return array{
     *     propertyName: string,
     *     referencedUuid: string,
     *     targetSchemaSlug: string,
     *     targetRegister: string|null,
     *     message: string,
     *     code: int
     * }
     */
    public function toArray(): array
    {
        return [
            'propertyName'     => $this->propertyName,
            'referencedUuid'   => $this->referencedUuid,
            'targetSchemaSlug' => $this->targetSchemaSlug,
            'targetRegister'   => $this->targetRegister,
            'message'          => $this->getMessage(),
            'code'             => $this->getCode(),
        ];

    }//end toArray()
}//end class
