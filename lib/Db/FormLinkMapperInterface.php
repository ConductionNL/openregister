<?php

/**
 * FormLinkMapperInterface: what callers outside the Db component use of FormLinkMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The methods of FormLinkMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to FormLinkMapper. Guarded by F05InterfaceBindingsTest.
 */
interface FormLinkMapperInterface {

	/**
	 * Find the form-level link (submissionId NULL) for the given `(object, form)` pair.
	 *
	 * @param string $objectUuid The OR object UUID.
	 * @param int    $formId     The NC Forms form id.
	 *
	 * @return FormLink|null The link, or null when no such row exists.
	 *
	 * @spec openspec/specs/integration-forms/spec.md
	 */
	public function findFormLink(string $objectUuid, int $formId): ?FormLink;

	/**
	 * Find a submission-level link for a (object, form, submission) tuple.
	 *
	 * @param string            $objectUuid The OR object UUID.
	 * @param FormSubmissionRef $submission The form and submission ids.
	 *
	 * @return FormLink|null The link, or null.
	 *
	 * @spec openspec/specs/integration-forms/spec.md
	 */
	public function findSubmissionLink(string $objectUuid, FormSubmissionRef $submission): ?FormLink;

	/**
	 * Delete every link row (form-level + submission-level) for a (object, form) pair.
	 *
	 * @param string $objectUuid The OR object UUID.
	 * @param int    $formId     The NC Forms form id.
	 *
	 * @return int Number of deleted rows.
	 *
	 * @spec openspec/specs/integration-forms/spec.md
	 */
	public function deleteByObjectAndForm(string $objectUuid, int $formId): int;

	/**
	 * Find the links of an object, newest first.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return FormLink[] The links.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function findByObjectUuid(string $objectUuid): array;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return FormLink The inserted row, with its id.
	 *
	 * @spec openspec/specs/integration-forms/spec.md
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Delete one row.
	 *
	 * @param Entity $entity The row to delete.
	 *
	 * @return FormLink The deleted row.
	 *
	 * @spec openspec/specs/integration-forms/spec.md
	 */
	public function delete(Entity $entity): Entity;
}//end interface
