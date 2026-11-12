<?php

/**
 * The object a public case token is bound to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The object uuid, its register and schema ids and the token's human label.
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */
final class CaseTokenTarget
{

	/**
	 * Constructor.
	 *
	 * @param string      $objectUuid The object uuid to bind the token to.
	 * @param int|null    $registerId The register id of the object.
	 * @param int|null    $schemaId   The schema id of the object.
	 * @param string|null $label      An optional human label.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly ?int $registerId = null,
		public readonly ?int $schemaId = null,
		public readonly ?string $label = null,
	) {
	}//end __construct()
}//end class
