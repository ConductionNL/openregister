<?php

/**
 * SchemaCatalogInterface: the register, schema and magic-table mappers as one collaborator.
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
 * The named aggregate of ADR-011 rule 4 for the three mappers that are
 * injected together: registers, schemas and their magic tables.
 *
 * PHP 8.3 interfaces cannot declare properties, so the contract is three
 * accessor methods. The implementation also exposes the same members as
 * public readonly properties of the same names; a consumer that types
 * against this interface calls the accessors.
 *
 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
 */
interface SchemaCatalogInterface {

	/**
	 * The register mapper.
	 *
	 * @return RegisterMapperInterface The mapper.
	 *
	 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
	 */
	public function registers(): RegisterMapperInterface;

	/**
	 * The schema mapper.
	 *
	 * @return SchemaMapperInterface The mapper.
	 *
	 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
	 */
	public function schemas(): SchemaMapperInterface;

	/**
	 * The magic-table mapper.
	 *
	 * @return MagicMapperInterface The mapper.
	 *
	 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
	 */
	public function tables(): MagicMapperInterface;
}//end interface
