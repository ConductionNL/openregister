<?php

/**
 * OpenRegister SavedScopeIds
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The register and schema ids a write ran under; either may be unknown.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class SavedScopeIds {

	/**
	 * Hold the ids.
	 *
	 * @param int|null $registerId The register id.
	 * @param int|null $schemaId   The schema id.
	 */
	public function __construct(
		public readonly ?int $registerId,
		public readonly ?int $schemaId
	) {
	}//end __construct()

	/**
	 * The ids as log context.
	 *
	 * @return array{registerId: int|null, schemaId: int|null}
	 */
	public function logContext(): array {
		return ['registerId' => $this->registerId, 'schemaId' => $this->schemaId];
	}//end logContext()
}//end class
