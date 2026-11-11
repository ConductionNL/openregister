<?php

/**
 * The refusal and failure answers of the migration-pack endpoints.
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
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\MigrationPack;

use OCA\OpenRegister\Exception\MigrationPackRefusedException;
use OCP\AppFramework\Http\JSONResponse;
use Throwable;

/**
 * The refusal and failure answers of the migration-pack endpoints.
 *
 * Bound to MigrationPackErrorResponder in ContractBindings. Every method is described on MigrationPackErrorResponder.
 */
interface MigrationPackErrorResponderInterface {

	/**
	 * 401: nobody is signed in.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
	 */
	public function notAuthenticated(): JSONResponse;

	/**
	 * 403: the caller is signed in but not a Nextcloud administrator.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
	 */
	public function adminRequired(): JSONResponse;

	/**
	 * 404: no pack has the requested row id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#migration-packs-must-be-manageable-via-admin-gated-rest-crud-with-json-file-importexport
	 */
	public function notFound(): JSONResponse;

	/**
	 * Answer a refusal the caller caused: 400 for a malformed request, 409
	 * for an update based on an outdated read, 422 for a refused definition.
	 *
	 * @param MigrationPackRefusedException $exception The refusal.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
	 */
	public function refused(MigrationPackRefusedException $exception): JSONResponse;

	/**
	 * 500: log an unexpected failure and answer with a static message.
	 *
	 * @param 'import'|'create'|'update'|'delete'|'preview' $action    What the caller tried to do.
	 * @param Throwable                                     $exception The failure, logged in full.
	 * @param array<string, mixed>                          $context   Extra log context, such as the row id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
	 */
	public function unexpected(string $action, Throwable $exception, array $context = []): JSONResponse;
}//end interface
