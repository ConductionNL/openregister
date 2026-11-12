<?php

/**
 * CaseObjectAccessorInterface: what callers outside the Service component use of CaseObjectAccessor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Case
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

namespace OCA\OpenRegister\Service\Gdpr\Case;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Where DSAR cases are stored, and loading and saving one.
 */
interface CaseObjectAccessorInterface {

	/**
	 * Register slug the case objects live under (declared by the head).
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'data-subject-requests';

	/**
	 * Schema slug of the case entity (declared by the head).
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'dataSubjectRequest';

	/**
	 * Load a case object by uuid under the caller's RBAC + tenant scope.
	 *
	 * @param string $caseUuid The case object uuid/id.
	 *
	 * @return ObjectEntity|null The case, or null when absent or unauthorised.
	 *
	 * @spec openspec/specs/dsar-case-api/spec.md
	 */
	public function load(string $caseUuid): ?ObjectEntity;

	/**
	 * Persist a mutated case, pinned to the DSAR processing activity so the
	 * immutable audit trail records the write under that activity.
	 *
	 * @param ObjectEntity $case The loaded case entity.
	 * @param array<string, mixed> $data The full replacement payload for the case.
	 *
	 * @return ObjectEntity The saved case.
	 *
	 * @spec openspec/specs/dsar-case-api/spec.md
	 */
	public function save(ObjectEntity $case, array $data): ObjectEntity;
}//end interface
