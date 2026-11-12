<?php

/**
 * OpenRegister NamedAggregationRef
 *
 * A reference to an aggregation a schema declares by name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;

/**
 * The register, the schema and the name of an aggregation the schema declares, as a request names them.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class NamedAggregationRef {

	/**
	 * Constructor.
	 *
	 * @param string $register Register slug/uuid/id: the boundary, resolved first.
	 * @param string $schema   Schema slug/uuid/id, matched among the register's schemas.
	 * @param string $name     The aggregation's name in the schema's declaration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
		public readonly string $name,
	) {
	}//end __construct()

	/**
	 * The register and schema without the aggregation name.
	 *
	 * @return RegisterSchemaRef
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function registerSchema(): RegisterSchemaRef {
		return new RegisterSchemaRef(register: $this->register, schema: $this->schema);
	}//end registerSchema()
}//end class
