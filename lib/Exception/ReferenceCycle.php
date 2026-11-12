<?php

/**
 * The cycle a CircularReferenceException reports
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
 * @spec openspec/specs/reference-existence-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * The object whose re-entry closed a reference cycle, its schema, and the
 * chain of frames that led there.
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */
final class ReferenceCycle {
	/**
	 * Constructor.
	 *
	 * @param string $referencedUuid   The UUID whose re-entry triggered detection.
	 * @param string $targetSchemaSlug Slug of the target schema (or raw `$ref`).
	 * @param array<int, array{register?:string|null,schema:string,uuid:string}> $cycle Chain of (register, schema, uuid)
	 *                                                                                 entries that caused the cycle.
	 */
	public function __construct(
		public readonly string $referencedUuid,
		public readonly string $targetSchemaSlug,
		public readonly array $cycle = [],
	) {
	}//end __construct()
}//end class
