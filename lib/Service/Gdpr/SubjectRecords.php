<?php

/**
 * OpenRegister data-subject record collaborators
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr;

use OCA\OpenRegister\Service\Gdpr\Subject\SubjectDeclaredFieldIndex;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectEntityIndex;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectObjectLoader;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectObjectWriter;

/**
 * Where a data-subject request finds, loads and writes the subject's objects.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
final class SubjectRecords {
	/**
	 * Bundle the collaborators.
	 *
	 * @param SubjectEntityIndex  $index  GdprEntity ⋈ entity_relations discovery.
	 * @param SubjectObjectLoader $loader RBAC/tenant scoped object loads.
	 * @param SubjectObjectWriter $writer DSAR-attributed, audited, scoped writes.
	 * @param SubjectDeclaredFieldIndex|null $declaredFields Discovery through schema-declared subject id fields;
	 *                                                       null searches the entity index only.
	 */
	public function __construct(
		public readonly SubjectEntityIndex $index,
		public readonly SubjectObjectLoader $loader,
		public readonly SubjectObjectWriter $writer,
		public readonly ?SubjectDeclaredFieldIndex $declaredFields = null,
	) {
	}//end __construct()
}//end class
