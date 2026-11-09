<?php

/**
 * RegisterSchemaRef: a register and a schema as a request names them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The register and schema of one request, before either is resolved to an entity.
 *
 * Each is the id, uuid or slug exactly as the route or caller carries it
 * (ADR-011 rule 1). Resolving it is the job of the mappers, not of this object.
 *
 * @spec exclude ADR-011 shared parameter object, not a capability of its own
 */
final class RegisterSchemaRef {

	/**
	 * Constructor
	 *
	 * @param string|int $register The register id, uuid or slug.
	 * @param string|int $schema   The schema id, uuid or slug.
	 */
	public function __construct(
		public readonly string|int $register,
		public readonly string|int $schema,
	) {
	}//end __construct()
}//end class
