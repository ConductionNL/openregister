<?php

/**
 * What the migration-pack endpoints read from a request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\MigrationPack
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\MigrationPack;

use DateTimeImmutable;
use OCA\OpenRegister\Exception\InvalidPackDefinitionException;
use OCA\OpenRegister\Exception\MigrationPackRequestException;
use OCP\IRequest;

/**
 * What the migration-pack endpoints read from a request.
 *
 * Bound to MigrationPackRequestReader in ContractBindings. Every method is described on MigrationPackRequestReader.
 * The preview bounds live here so callers name only the interface.
 */
interface MigrationPackRequestReaderInterface {

	/**
	 * The most sample rows one preview request may map.
	 *
	 * @var int
	 */
	public const MAX_PREVIEW_ROWS = 20;

	/**
	 * The largest preview input, definition and rows together, as JSON bytes.
	 *
	 * The row count alone does not bound the work: one row can carry any
	 * number of columns, and every row runs through the mapping engine in the
	 * request. 1 MiB holds a large pack with long lookup tables and twenty
	 * wide rows.
	 *
	 * @var int
	 */
	public const MAX_PREVIEW_BYTES = 1048576;

	/**
	 * The pack row id from the route, falling back to the dispatched argument.
	 *
	 * The dispatched `$id` is read from the merged parameters, where a body
	 * `id` wins; a pack slug there is cast to 0. The route value does not
	 * have that problem.
	 *
	 * @param IRequest $request      The request.
	 * @param int      $dispatchedId The id the dispatcher passed to the controller.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id
	 */
	public function routeId(IRequest $request, int $dispatchedId): int;

	/**
	 * Read the pack definition from a create or update request body.
	 *
	 * Accepts the envelope `{"definition": {...}}`, taken as is, or the pack
	 * document as the whole body (the raw form). A body with a top-level
	 * `definition` object is always read as the envelope. In the raw form,
	 * every `_`-prefixed key is dropped, because Nextcloud merges router keys
	 * such as `_route` into the same parameters; a pack that needs such keys
	 * must use the envelope.
	 *
	 * Nextcloud also merges the route `id` into those parameters, so in the
	 * raw form a body `id` equal to the row id in the URL cannot be told apart
	 * from no body `id` at all. That request is refused, with every validation
	 * error of the pack without an id plus a pointer to the envelope, rather
	 * than guessed at.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidPackDefinitionException When the raw form's id is the route id.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id
	 */
	public function definition(IRequest $request): array;

	/**
	 * The `updatedAt` an update was based on, from the envelope's `expectedUpdatedAt`.
	 *
	 * An update that names it is refused when the pack was saved since; one
	 * that does not overwrites whatever is stored. Only the envelope carries
	 * it: in the raw form every body key belongs to the pack document.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return DateTimeImmutable|null Null when the request names no version.
	 *
	 * @throws MigrationPackRequestException When it is not a date-time as the API answers `updatedAt`.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id
	 */
	public function expectedUpdatedAt(IRequest $request): ?DateTimeImmutable;

	/**
	 * Read the pack document from the uploaded `file` of an import request.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws MigrationPackRequestException When no file was uploaded, or it is not a JSON object.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#migration-packs-must-be-manageable-via-admin-gated-rest-crud-with-json-file-importexport
	 */
	public function uploadedDefinition(IRequest $request): array;

	/**
	 * Read the definition and sample rows of a preview request.
	 *
	 * Bounded twice: at most MAX_PREVIEW_ROWS rows, and at most
	 * MAX_PREVIEW_BYTES for the definition and rows together.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return array{definition: array<string, mixed>, rows: list<array<string, mixed>>}
	 *
	 * @throws MigrationPackRequestException When the body is malformed, or has too many or too large rows.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
	 */
	public function previewInput(IRequest $request): array;
}//end interface
