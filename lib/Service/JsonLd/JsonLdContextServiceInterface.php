<?php

/**
 * JsonLdContextServiceInterface: what callers outside the component use of JsonLdContextService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\JsonLd
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\JsonLd;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Build and check the JSON-LD contexts of registers and schemas.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface JsonLdContextServiceInterface {

	/**
	 * Build the register-wide context document: a merged term block covering
	 * every schema in the register (later schemas win on term collisions, which
	 * is acceptable for a register-level convenience document; per-schema
	 * contexts remain authoritative).
	 *
	 * @param Register $register The register.
	 * @param array<Schema> $schemas The register's schema entities.
	 *
	 * @return array<string, mixed> The merged JSON-LD context map.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function buildRegisterContext(Register $register, array $schemas): array;

	/**
	 * Build the JSON-LD context map for a single schema.
	 *
	 * The returned array is the *value* of a `@context` key, i.e.
	 * `{"or": "...", "name": {...}, ...}`. It always defines the `or:` prefix,
	 * an `xsd:` prefix, a `@vocab` when configured, the schema class term, and
	 * one term per schema property (mapped IRI or zero-config fragment term).
	 *
	 * Result is request-cached per (schema id, updated timestamp).
	 *
	 * @param Register $register The register the schema belongs to.
	 * @param Schema $schema The schema.
	 *
	 * @return array<string, mixed> The JSON-LD context map.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function buildSchemaContext(Register $register, Schema $schema): array;

	/**
	 * Validate a schema's `jsonld` mapping block. Term values must be absolute
	 * IRIs, or compact terms resolvable against a declared `@vocab`. Unknown
	 * property names are tolerated (schemas evolve).
	 *
	 * @param array<string, mixed> $jsonld The `jsonld` configuration block.
	 *
	 * @return array<int, string> A list of human-readable validation errors (empty when valid).
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function validateMapping(array $jsonld): array;
}//end interface
