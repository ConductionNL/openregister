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

use OCA\OpenRegister\Db\RegisterSchema;
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
	 * Serialize a single rendered object of one register and schema as a JSON-LD node document.
	 *
	 * @param array<string, mixed> $renderedObject The rendered object (top-level data + `@self`).
	 * @param RegisterSchema       $table          The object's register and schema.
	 *
	 * @return array<string, mixed> The JSON-LD document.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function serializeIn(array $renderedObject, RegisterSchema $table): array;

	/**
	 * Serialize a paginated collection of one register and schema as a JSON-LD `@graph` document.
	 *
	 * @param array<string, mixed> $paginatedResult The `index()` result (results/total/page/...).
	 * @param RegisterSchema       $table           The collection's register and schema.
	 *
	 * @return array<string, mixed> The JSON-LD `@graph` document.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function serializeCollectionIn(array $paginatedResult, RegisterSchema $table): array;
}//end interface
