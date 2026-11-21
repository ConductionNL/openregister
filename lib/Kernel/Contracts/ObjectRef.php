<?php

/**
 * ObjectRef: one object as a request names it, with its register and schema.
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
 * The register, schema and object id of one request, before any is resolved.
 *
 * Replaces the loose `string $register, string $schema, string $id` triple
 * (ADR-011 rule 1), which travelled in two different orders.
 *
 * @spec exclude ADR-011 shared parameter object, not a capability of its own
 */
final class ObjectRef {

	/**
	 * Constructor
	 *
	 * @param string|int $register The register id, uuid or slug.
	 * @param string|int $schema   The schema id, uuid or slug.
	 * @param string     $id       The object id or uuid.
	 */
	public function __construct(
		public readonly string|int $register,
		public readonly string|int $schema,
		public readonly string $id,
	) {
	}//end __construct()

	/**
	 * The register and schema without the object id.
	 *
	 * @return RegisterSchemaRef The pair.
	 *
	 * @spec exclude ADR-011 shared parameter object, not a capability of its own
	 */
	public function registerSchema(): RegisterSchemaRef {
		return new RegisterSchemaRef(register: $this->register, schema: $this->schema);
	}//end registerSchema()
}//end class
