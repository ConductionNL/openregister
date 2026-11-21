<?php

/**
 * ExternalRegisterLookupInterface — what the external-register endpoint and leaf ask of the lookup.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Reads one external-register record by key, with the reason when there is none.
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */
interface ExternalRegisterLookupInterface {

	/**
	 * The record of a sourced schema by key, or null when the caller may not
	 * read that schema or it does not exist (the two are not told apart).
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The sourced schema id or slug.
	 * @param string $key      The key, as the host object holds it.
	 * @param bool   $refresh  Read the source again, ignoring a cached answer.
	 *
	 * @return array{state:string,adminActionable:bool,record:array<string,mixed>|null,fetchedAt:string,source:string|null,cacheSeconds:int}|null
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md#requirement-an-external-register-record-renders-on-another-object
	 */
	public function read(string $register, string $schema, string $key, bool $refresh=false): ?array;
}//end interface
