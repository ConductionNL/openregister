<?php

/**
 * One pass of CacheHandler over the magic tables for the names of a set of UUIDs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/schema-driven-read-coercion/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * The UUIDs asked, the schemas of the registers walked, and the names found so far.
 *
 * Built by CacheHandler::batchLoadNamesFromMagicTables() and never handed out of it (ADR-011 Rule 3).
 *
 * @spec openspec/specs/schema-driven-read-coercion/spec.md
 */
final class MagicNameLoadRun {

	/**
	 * The schemas of every register walked, keyed by id.
	 *
	 * @var array
	 */
	public array $schemaMap = [];

	/**
	 * Map of UUID to name, owning organisation and name source found so far.
	 *
	 * @var array<string, array{name: string, organisation: string|null, source: string|null}>
	 */
	public array $results = [];

	/**
	 * Constructor for MagicNameLoadRun.
	 *
	 * @param array $uuidList The UUID-like identifiers to look up.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $uuidList,
	) {
	}//end __construct()
}//end class
