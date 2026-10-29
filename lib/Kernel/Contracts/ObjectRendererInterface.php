<?php

/**
 * ObjectRendererInterface: render stored objects for a response.
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
 * Renders object entities for an API response, and post-processes rows that were read without rendering. Implemented by RenderObject.
 */
interface ObjectRendererInterface {

	/**
	 * Render multiple entities with extensions, filters, and field selections.
	 *
	 * This method renders an array of ObjectEntities by calling renderEntity() for each one.
	 * It's used for batch rendering of search results and collections.
	 *
	 * @param array $entities Array of ObjectEntity instances to render.
	 * @param array|string|null $_extend Properties to extend/embed in the response.
	 * @param array|null $_filter Filters to apply to the rendered entities.
	 * @param array|null $_fields Specific fields to include in the response.
	 * @param array|null $_unset Fields to exclude from the response.
	 * @param bool $_rbac Whether to apply RBAC checks (default: true).
	 * @param bool $_multitenancy Whether to apply multitenancy filtering (default: true).
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\ObjectEntity>
	 */
	public function renderEntities(
		array $entities,
		array|string|null $_extend = [],
		array|string|null $_filter = null,
		array|string|null $_fields = null,
		array|string|null $_unset = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array;

	/**
	 * Attach the lightweight `\@self.files` ID list to a batch of un-rendered rows.
	 *
	 * @param array $rows Result rows by reference. Each row is mutated in place.
	 *
	 * @return void
	 *
	 * Used by list pipelines that bypass the full renderEntity pipeline (the
	 * cheap-path when no extend/fields/filter/unset is requested, or the SOLR/index
	 * path which never renders rows). Walks rows, collects UUIDs, issues a single
	 * batched FileMapper lookup, and writes the lightweight ID list onto each row's
	 * `\@self.files` (or the equivalent property on an ObjectEntity).
	 *
	 * Shape-tolerant: rows may be ObjectEntity instances or arrays. Rows whose UUID
	 * cannot be resolved get `\@self.files` set to []. Guarantees the spec contract
	 * that the field is always present on rendered list rows.
	 *
	 * Caller must not call this when rows already have full file metadata from
	 * renderEntities() — this method overwrites with IDs.
	 */
	public function attachLightweightFilesToRows(array &$rows): void;

	/**
	 * Resolve translatable properties on a batch of un-rendered list rows.
	 *
	 * List pipelines that bypass the full renderEntity pipeline (the cheap-path
	 * when no extend/fields/filter/unset is requested) never resolve translatable
	 * properties, so translatable fields come back as raw language-keyed maps
	 * (e.g. `{"nl":"Foo","en":"Bar"}`) instead of the single projected string the
	 * single-object read path returns. This method closes that gap by delegating
	 * each row to TranslationHandler::resolveTranslationsForRender(), mirroring
	 * the resolution done in renderEntity().
	 *
	 * Behaviour:
	 * - No-op when the request opts in to `?_translations=all` and when the row's
	 *   schema declares no translatable properties (both early-return inside the
	 *   translation handler), so there is no cost for non-translatable schemas.
	 * - Multi-schema results resolve each row against ITS OWN schema and register.
	 *
	 * Shape-tolerant: rows may be ObjectEntity instances or serialized arrays.
	 * Rows whose schema cannot be resolved are left untouched.
	 *
	 * @param array $rows Result rows by reference. Each row is mutated in place.
	 *
	 * @return void
	 */
	public function resolveTranslationsForRows(array &$rows): void;

	/**
	 * Redact write-only properties from cheap-path list rows (openregister#380, ocon#147).
	 *
	 * The list cheap-path (no _extend/_fields/_filter/_unset) does NOT run rows through
	 * renderEntity() — for performance — so the write-only redaction enforced there never
	 * runs. That is exactly the path the OpenConnector leak used: `GET .../objects/{reg}/
	 * {schema}` is a LIST, so it took the cheap-path and returned every Source's apikey in
	 * cleartext even after the single-object read was redacted. This method closes that gap
	 * with the same rule, mirroring resolveTranslationsForRows()'s per-row schema
	 * resolution and object-vs-array handling.
	 *
	 * writeOnly stripping is a HARD render-boundary rule and is NOT rbac-gated, exactly as
	 * on the single-object path (renderEntity, #389): a `writeOnly: true` property — and a
	 * nested `x-openregister-writeonly-paths` dot-path (#459), which rides the same
	 * boundary — must never be returned on ANY read, including admin and `_rbac: false`
	 * renders. `ObjectsController` derives `_rbac` as `($isAdmin === false)`, so an ADMIN
	 * list/search read arrives here with `_rbac: false`; gating writeOnly on it returned
	 * every secret in cleartext (#460 — the list half of #389, which only ever fixed
	 * renderEntity). `_rbac: false` on an HTTP read means "this caller bypasses WHICH
	 * OBJECTS it may see", never "this caller may see secrets"; conflating the two was the
	 * bug. Internal code that needs a raw secret value reads ObjectEntity::getObject() from
	 * the mapper, or `find(..., _render: false)`, and never enters a render path.
	 *
	 * Property `authorization.read` (group-based) stripping and field decryption REMAIN
	 * `_rbac` / SystemOperationContext-gated: those are trusted-internal-read bypasses that
	 * mirror PermissionHandler::hasPermission(), and are unchanged by #460.
	 *
	 * Strips the object body AND the `@self.relations` search index copy (#429), which is a
	 * separate scalar mirror of reference-shaped values and therefore leaks a writeOnly
	 * secret independently of the body.
	 *
	 * @param array $rows Result rows by reference, mutated in place.
	 * @param bool $_rbac Whether this is a user-facing read (true) or system context (false).
	 *                    Gates ONLY the authorization.read strip and decryption — never writeOnly.
	 *
	 * @return void
	 */
	public function redactWriteOnlyFromRows(array &$rows, bool $_rbac = true): void;
}//end interface
