<?php

/**
 * JsonLdSerializerInterface: what callers outside the component use of JsonLdSerializer.
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
use OCP\IRequest;

/**
 * Serialise objects as JSON-LD documents when the request asks for it.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface JsonLdSerializerInterface {

	/**
	 * Decide whether the client wants JSON-LD, based on the `Accept` header.
	 *
	 * True only when `application/ld+json` is the highest-weighted matching
	 * media type. Absent headers, `application/json`, and wildcard-only matches
	 * (`*` / `application/*`) resolve to plain JSON (default unchanged).
	 *
	 * @param IRequest $request The current request.
	 *
	 * @return bool True when JSON-LD output is requested.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function wantsJsonLd(IRequest $request): bool;

	/**
	 * Serialize a single rendered object array as a JSON-LD node document.
	 *
	 * @param array<string, mixed> $renderedObject The rendered object (top-level data + `@self`).
	 * @param Schema $schema The object's schema.
	 * @param Register $register The object's register.
	 *
	 * @return array<string, mixed> The JSON-LD document.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function serialize(array $renderedObject, Schema $schema, Register $register): array;

	/**
	 * Serialize a paginated collection result as a JSON-LD `@graph` document.
	 *
	 * @param array<string, mixed> $paginatedResult The `index()` result (results/total/page/...).
	 * @param Schema $schema The collection's schema.
	 * @param Register $register The collection's register.
	 *
	 * @return array<string, mixed> The JSON-LD `@graph` document.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function serializeCollection(array $paginatedResult, Schema $schema, Register $register): array;
}//end interface
