<?php

/**
 * RecordTemplateMapperInterface: what callers outside the component use of RecordTemplateMapper.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;

/**
 * The record-template store the record-template service reads and writes.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own. Bound to RecordTemplateMapper.
 */
interface RecordTemplateMapperInterface {

	/**
	 * One template, by its uuid.
	 *
	 * @param string $uuid The template uuid.
	 *
	 * @return RecordTemplate The template.
	 *
	 * @throws DoesNotExistException When no template has that uuid.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function findByUuid(string $uuid): RecordTemplate;

	/**
	 * The templates a user might see for a register/schema pair.
	 *
	 * @param string         $userId The caller.
	 * @param ListQuery|null $query  The `register` and `schema` filters: only templates for this register id
	 *                               and this schema id; null, or an absent or empty filter, narrows nothing.
	 *
	 * @return RecordTemplate[] The candidates, newest first.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function findCandidates(string $userId, ?ListQuery $query=null): array;

	/**
	 * Insert a new template.
	 *
	 * @param Entity $entity The template.
	 *
	 * @return RecordTemplate The stored template.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function insert(Entity $entity): RecordTemplate;

	/**
	 * Update a template.
	 *
	 * @param Entity $entity The template.
	 *
	 * @return RecordTemplate The stored template.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function update(Entity $entity): RecordTemplate;

	/**
	 * Delete a template (QBMapper).
	 *
	 * @param Entity $entity The template.
	 *
	 * @return Entity The deleted template.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function delete(Entity $entity): Entity;
}//end interface
