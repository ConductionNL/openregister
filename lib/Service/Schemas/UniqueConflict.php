<?php

/**
 * A submitted write and the object already holding its unique combination.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * The submitted object data that breaches a unique constraint, and who holds the combination already.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class UniqueConflict {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $object        The submitted object data.
	 * @param string|null          $conflictingId The uuid of an object already holding the combination.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function __construct(
		public readonly array $object,
		public readonly ?string $conflictingId,
	) {
	}//end __construct()
}//end class
