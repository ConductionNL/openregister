<?php

/**
 * RecordTemplateStoreInterface: list, read and change the record templates of a schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\RecordTemplate
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

namespace OCA\OpenRegister\Service\RecordTemplate;

use OCA\OpenRegister\Db\RecordTemplate;
use OCA\OpenRegister\Exception\RecordTemplateRefused;
use OCA\OpenRegister\Service\Rbac\ViewerReach;

/**
 * The record-template operations the record-templates endpoints run.
 *
 * Bound to RecordTemplateService in ContractBindings.
 */
interface RecordTemplateStoreInterface {

	/**
	 * The templates the caller may use, each with the access they hold on it.
	 *
	 * @param ViewerReach         $reach   The caller.
	 * @param TemplateListOptions $options Only templates for this register id and this schema id, where set.
	 *
	 * @return RecordTemplate[] The usable templates.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function listFor(ViewerReach $reach, TemplateListOptions $options=new TemplateListOptions()): array;

	/**
	 * One template the caller may use.
	 *
	 * @param string      $uuid  The template uuid.
	 * @param ViewerReach $reach The caller.
	 *
	 * @return RecordTemplate The template, with the caller's access.
	 *
	 * @throws RecordTemplateRefused 404 when it is absent or the caller may not use it.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function get(string $uuid, ViewerReach $reach): RecordTemplate;

	/**
	 * Store a new template owned by the caller.
	 *
	 * @param array<string, mixed> $data  `{name, description?, register, schema, values?, isPublic?, sharedWith?}`.
	 * @param ViewerReach          $reach The caller.
	 *
	 * @return RecordTemplate The stored template.
	 *
	 * @throws RecordTemplateRefused 400 when the input is unusable, 403 when the caller may not create in the schema.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function create(array $data, ViewerReach $reach): RecordTemplate;

	/**
	 * Change a template the caller owns, or any template for an administrator.
	 *
	 * @param TemplateChange $change The template uuid, the fields to set and whether only those are set (PATCH) or all (PUT).
	 * @param ViewerReach    $reach  The caller.
	 *
	 * @return RecordTemplate The stored template.
	 *
	 * @throws RecordTemplateRefused 404, 403 or 400.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function change(TemplateChange $change, ViewerReach $reach): RecordTemplate;

	/**
	 * Delete a template the caller owns, or any template for an administrator.
	 *
	 * @param string      $uuid  The template uuid.
	 * @param ViewerReach $reach The caller.
	 *
	 * @return void
	 *
	 * @throws RecordTemplateRefused 404 or 403.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function delete(string $uuid, ViewerReach $reach): void;
}//end interface
