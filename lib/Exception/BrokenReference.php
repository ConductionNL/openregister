<?php

/**
 * The reference a ReferenceValidationException reports
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
 * Which property pointed at which object, in which schema and register,
 * when the reference did not resolve.
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */
final class BrokenReference {
	/**
	 * Constructor.
	 *
	 * @param string      $propertyName     The schema property carrying the broken reference.
	 * @param string      $referencedUuid   The UUID that did not resolve.
	 * @param string      $targetSchemaSlug Slug of the target schema (or the raw `$ref`
	 *                                      value if the slug could not be resolved).
	 * @param string|null $targetRegister   The register the reference was searched in, or
	 *                                      null when no register context applies.
	 */
	public function __construct(
		public readonly string $propertyName,
		public readonly string $referencedUuid,
		public readonly string $targetSchemaSlug,
		public readonly ?string $targetRegister = null,
	) {
	}//end __construct()
}//end class
