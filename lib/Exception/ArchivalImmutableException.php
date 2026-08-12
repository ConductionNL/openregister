<?php

/**
 * OpenRegister ArchivalImmutableException
 *
 * Exception thrown when a user-driven DELETE is attempted on an object
 * whose schema declares the `x-openregister-archival` annotation. The
 * only legitimate delete path is the `ArchivalRetentionTask` cron, which
 * sets a private `_retentionSweep: true` flag on the delete call so the
 * gate is bypassed.
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
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-3-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Throwable;

/**
 * Exception thrown when a DELETE hits an archival-annotated schema.
 *
 * Archival schemas are immutable by virtue of their declared retention rules:
 * the system itself decides when each row expires, not the caller. Operators
 * needing to clean specific rows must either (a) wait for the cron's sweep
 * or (b) drop the annotation from the schema first.
 *
 * Callers should translate this to HTTP 403 Forbidden with the structured
 * error body:
 *   {
 *     "error": "SCHEMA_ARCHIVAL_IMMUTABLE",
 *     "message": "...",
 *     "schema": "...",
 *     "operation": "delete",
 *     "hint": "..."
 *   }
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-3
 */
class ArchivalImmutableException extends \Exception {

	/**
	 * The schema slug or identifier that triggered this exception.
	 *
	 * @var string
	 */
	private readonly string $schemaIdentifier;

	/**
	 * The blocked operation name (always 'delete' in v1, reserved for future ops).
	 *
	 * @var string
	 */
	private readonly string $operation;

	/**
	 * Constructor.
	 *
	 * @param string $schemaIdentifier The schema slug, UUID, or ID.
	 * @param string $operation The blocked operation name (e.g. 'delete').
	 * @param Throwable|null $previous Previous exception.
	 */
	public function __construct(
		string $schemaIdentifier,
		string $operation = 'delete',
		?Throwable $previous = null,
	) {
		$this->schemaIdentifier = $schemaIdentifier;
		$this->operation = $operation;

		$message = sprintf(
			'SCHEMA_ARCHIVAL_IMMUTABLE: Schema "%s" declares x-openregister-archival; '
			. 'user-driven %s operations are not permitted. Rows expire automatically '
			. 'via the ArchivalRetentionTask cron.',
			$schemaIdentifier,
			$operation
		);

		parent::__construct(message: $message, code: 403, previous: $previous);

	}//end __construct()

	/**
	 * Get the schema identifier that triggered this exception.
	 *
	 * @return string The schema slug, UUID, or ID.
	 *
	 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-3
	 */
	public function getSchemaIdentifier(): string {
		return $this->schemaIdentifier;
	}//end getSchemaIdentifier()

	/**
	 * Build the structured JSON error body for HTTP 403 responses.
	 *
	 * @return array{error: string, message: string, schema: string, operation: string, hint: string}
	 *
	 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-3
	 */
	public function toResponseBody(): array {
		return [
			'error' => 'SCHEMA_ARCHIVAL_IMMUTABLE',
			'message' => $this->getMessage(),
			'schema' => $this->schemaIdentifier,
			'operation' => $this->operation,
			'hint' => 'Rows on archival schemas are removed automatically by '
				. 'OCA\\OpenRegister\\Cron\\ArchivalRetentionTask when they pass their '
				. 'effective retention. To force-delete, drop x-openregister-archival '
				. 'from the schema definition first.',
		];

	}//end toResponseBody()
}//end class
