<?php

/**
 * SchemaCatalog: the register, schema and magic-table mappers as one collaborator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The first named aggregate of ADR-011 rule 4: the three mappers that are
 * injected together into more than a hundred constructors.
 *
 * Autowired by the container; it holds no state of its own and no
 * permission or tenancy guard.
 *
 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
 */
final class SchemaCatalog implements SchemaCatalogInterface {

	/**
	 * Constructor
	 *
	 * @param RegisterMapperInterface $registers The register mapper.
	 * @param SchemaMapperInterface   $schemas   The schema mapper.
	 * @param MagicMapperInterface    $tables    The magic-table mapper.
	 */
	public function __construct(
		public readonly RegisterMapperInterface $registers,
		public readonly SchemaMapperInterface $schemas,
		public readonly MagicMapperInterface $tables,
	) {
	}//end __construct()

	/**
	 * The register mapper.
	 *
	 * @return RegisterMapperInterface The mapper.
	 *
	 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
	 */
	public function registers(): RegisterMapperInterface {
		return $this->registers;
	}//end registers()

	/**
	 * The schema mapper.
	 *
	 * @return SchemaMapperInterface The mapper.
	 *
	 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
	 */
	public function schemas(): SchemaMapperInterface {
		return $this->schemas;
	}//end schemas()

	/**
	 * The magic-table mapper.
	 *
	 * @return MagicMapperInterface The mapper.
	 *
	 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
	 */
	public function tables(): MagicMapperInterface {
		return $this->tables;
	}//end tables()
}//end class
