<?php

/**
 * Work Package Draft
 *
 * The project, subject and type of an OpenProject work package that is about
 * to be created and linked to an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * A work package to create, as the caller gave it (untrimmed).
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
 */
final class WorkPackageDraft {
	/**
	 * Constructor.
	 *
	 * @param string $projectId Target OpenProject project id.
	 * @param string $subject   New work-package subject.
	 * @param string $type      Optional work-package type (id or label).
	 */
	public function __construct(
		public readonly string $projectId,
		public readonly string $subject,
		public readonly string $type = '',
	) {
	}//end __construct()
}//end class
