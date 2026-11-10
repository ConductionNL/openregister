<?php

/**
 * FormLinkServiceInterface: what callers outside the Service component ask of FormLinkService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\FormLink;

/**
 * Links Nextcloud Forms forms and their submissions to objects.
 */
interface FormLinkServiceInterface {

	/**
	 * Create a new NC Forms form via the FormMapper and immediately
	 * link it to an OR object.
	 *
	 * The form is created with the same default shape that NC Forms'
	 * own `ApiController::newForm()` uses (empty title/description,
	 * private access, `isAnonymous=false`, `expires=0`). The caller
	 * is expected to follow up by editing the form in NC Forms; the
	 * link points at the form regardless.
	 *
	 * If the Forms app isn't installed (`OCA\Forms\Db\Form` /
	 * `FormMapper` not on the classpath), an exception is thrown so
	 * the caller can return a clean 503/501.
	 *
	 * @param string $objectUuid The OR object UUID.
	 * @param integer $registerId The OR register id.
	 * @param string $title Initial form title.
	 * @param string $description Initial form description.
	 * @param integer $schemaId Optional OR schema id.
	 *
	 * @return FormLink The link row pointing at the freshly-created form.
	 *
	 * @throws Exception When the Forms app is missing or the create fails.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the create-and-link contract is owned by the integration-forms capability.
	 */
	public function createAndLinkForm(
		string $objectUuid,
		int $registerId,
		string $title,
		string $description = '',
		?int $schemaId = null,
	): FormLink;

	/**
	 * Return the set of NC Forms forms the current user owns so the
	 * picker UI can render a list to choose from.
	 *
	 * Defensive: if Forms isn't installed or the query blows up, an
	 * empty array is returned (AD-23). Each row carries `id`, `hash`,
	 * `title`, `description`, plus a `linked` boolean signalling
	 * whether the form already has a form-level link to the given
	 * `$objectUuid` (so the picker can grey-out already-linked rows).
	 *
	 * @param string|null $objectUuid Optional object UUID to compute
	 *                                the `linked` flag against.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the picker-source contract is owned by the integration-forms capability.
	 */
	public function getAvailableForms(?string $objectUuid = null): array;

	/**
	 * Return the form links for an object grouped by parent form.
	 *
	 * Shape:
	 *   [
	 *     { ...formLinkRow, submissions: [{ ...submissionLinkRow }, ...] },
	 *     ...
	 *   ]
	 *
	 * Submission-level links whose form has no form-level link row are
	 * still surfaced under a synthetic parent (so a submission can be
	 * linked without forcing the form itself to be linked).
	 *
	 * @param string $objectUuid The OR object UUID.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the linked-forms listing contract is owned by the integration-forms capability.
	 */
	public function getLinkedForms(string $objectUuid): array;

	/**
	 * Link an existing NC Forms form to an OR object.
	 *
	 * Idempotent: if a form-level link already exists for the
	 * `(objectUuid, formId)` pair, the existing row is returned (no
	 * duplicate is inserted, no exception is raised).
	 *
	 * @param string $objectUuid The OR object UUID.
	 * @param integer $registerId The OR register id.
	 * @param integer $formId The NC Forms form id.
	 * @param integer $schemaId Optional OR schema id.
	 *
	 * @return FormLink The created (or existing) link row.
	 *
	 * @throws Exception If no user is logged in.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the form-link contract is owned by the integration-forms capability.
	 */
	public function linkForm(
		string $objectUuid,
		int $registerId,
		int $formId,
		?int $schemaId = null,
	): FormLink;

	/**
	 * Link a specific NC Forms submission to an OR object.
	 *
	 * @param string $objectUuid The OR object UUID.
	 * @param integer $registerId The OR register id.
	 * @param integer $formId The parent form id.
	 * @param integer $submissionId The submission id.
	 * @param integer $schemaId Optional OR schema id.
	 *
	 * @return FormLink The created (or existing) link row.
	 *
	 * @throws Exception If no user is logged in.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the submission-link contract is owned by the integration-forms capability.
	 */
	public function linkFormSubmission(
		string $objectUuid,
		int $registerId,
		int $formId,
		int $submissionId,
		?int $schemaId = null,
	): FormLink;

	/**
	 * Unlink a form (and every submission-level row beneath it) from an object.
	 *
	 * Idempotent: returns 0 when no rows match. Submission-level rows
	 * are removed too — that's the surface contract: "unlink the form"
	 * means "drop every link to this form on this object".
	 *
	 * @param string $objectUuid The OR object UUID.
	 * @param integer $formId The NC Forms form id.
	 *
	 * @return integer Number of rows removed.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the unlink-form contract is owned by the integration-forms capability.
	 */
	public function unlinkForm(string $objectUuid, int $formId): int;

	/**
	 * Unlink a single submission from an object.
	 *
	 * Form-level row (if any) is left untouched.
	 *
	 * @param string $objectUuid The OR object UUID.
	 * @param integer $formId The parent form id.
	 * @param integer $submissionId The submission id.
	 *
	 * @return boolean True when a row was removed, false when none existed.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the unlink-submission contract is owned by the integration-forms capability.
	 */
	public function unlinkSubmission(string $objectUuid, int $formId, int $submissionId): bool;
}//end interface
