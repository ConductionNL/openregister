<?php

/**
 * A write to a read-only record type.
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
 *
 * @spec openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Throwable;

/**
 * Thrown when a client writes to a type whose objects are the rows of a saved view.
 *
 * It is the strictest member of the append-only family: it refuses create as
 * well as update and delete. Extending AppendOnlyException means every
 * controller and the exception trait already answer it with 405.
 *
 * @spec openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md
 */
class ReadOnlyTypeException extends AppendOnlyException {

	/**
	 * Constructor.
	 *
	 * @param string         $schemaIdentifier The schema slug or id.
	 * @param string         $operation        The refused operation (create, update, delete).
	 * @param Throwable|null $previous         The previous exception.
	 *
	 * @spec openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md#requirement-req-qtype-001-a-saved-view-can-back-a-read-only-record-type
	 */
	public function __construct(
		string $schemaIdentifier,
		string $operation = 'create',
		?Throwable $previous = null,
	) {
		parent::__construct(schemaIdentifier: $schemaIdentifier, operation: $operation, previous: $previous);
		$this->message = sprintf(
			'SCHEMA_READ_ONLY: Schema "%s" lists the rows of a saved view; %s operations are not permitted.',
			$schemaIdentifier,
			$operation
		);
	}//end __construct()

	/**
	 * The 405 body.
	 *
	 * @return array{error: string, message: string, schema: string, operation: string}
	 *
	 * @spec openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md#requirement-req-qtype-001-a-saved-view-can-back-a-read-only-record-type
	 */
	public function toResponseBody(): array {
		return [
			'error' => 'SCHEMA_READ_ONLY',
			'message' => $this->getMessage(),
			'schema' => $this->getSchemaIdentifier(),
			'operation' => $this->getOperation(),
		];
	}//end toResponseBody()
}//end class
