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
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
class ReferenceValidationException extends ValidationException {

	/**
	 * Constructor.
	 *
	 * @param BrokenReference $reference The property, UUID, target schema and register
	 *                                   of the reference that did not resolve.
	 * @param string|null     $message   Optional override for the human-readable message;
	 *                                   defaults to a structured sentence built from the reference.
	 * @param int             $code      HTTP status code (default 422 — Unprocessable Entity).
	 * @param Throwable|null  $previous  The previous exception that triggered this one.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-exception-all/tasks.md#task-1
	 */
	public function __construct(
		private readonly BrokenReference $reference,
		?string $message=null,
		int $code=422,
		?Throwable $previous=null,
	) {
		parent::__construct(message: self::describe(reference: $reference, message: $message), code: $code, previous: $previous);
	}//end __construct()

	/**
	 * The caller's message, or the structured default sentence when none was given.
	 *
	 * @param BrokenReference $reference The reference that did not resolve.
	 * @param string|null     $message   The caller's override, if any.
	 *
	 * @return string The message.
	 */
	private static function describe(BrokenReference $reference, ?string $message): string {
		if ($message !== null && $message !== '') {
			return $message;
		}

		return sprintf(
			"Referenced object '%s' not found in schema '%s' for property '%s'",
			$reference->referencedUuid,
			$reference->targetSchemaSlug,
			$reference->propertyName
		);
	}//end describe()

	/**
	 * Schema property name that holds the broken reference.
	 *
	 * @return string Property name as declared on the schema.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-exception-all/tasks.md#task-1
	 */
	public function getPropertyName(): string {
		return $this->reference->propertyName;
	}//end getPropertyName()

	/**
	 * The UUID value that failed to resolve.
	 *
	 * @return string The unresolved UUID.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-exception-all/tasks.md#task-1
	 */
	public function getReferencedUuid(): string {
		return $this->reference->referencedUuid;
	}//end getReferencedUuid()

	/**
	 * Slug (or raw `$ref`) of the schema the reference targets.
	 *
	 * @return string Target schema slug or raw `$ref`.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-exception-all/tasks.md#task-1
	 */
	public function getTargetSchemaSlug(): string {
		return $this->reference->targetSchemaSlug;
	}//end getTargetSchemaSlug()

	/**
	 * Register the reference was searched in.
	 *
	 * @return string|null Register identifier or null when no register
	 *                     context applied to the lookup.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-exception-all/tasks.md#task-1
	 */
	public function getTargetRegister(): ?string {
		return $this->reference->targetRegister;
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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-exception-all/tasks.md#task-1
	 */
	public function toArray(): array {
		return [
			'propertyName' => $this->reference->propertyName,
			'referencedUuid' => $this->reference->referencedUuid,
			'targetSchemaSlug' => $this->reference->targetSchemaSlug,
			'targetRegister' => $this->reference->targetRegister,
			'message' => $this->getMessage(),
			'code' => $this->getCode(),
		];

	}//end toArray()
}//end class
