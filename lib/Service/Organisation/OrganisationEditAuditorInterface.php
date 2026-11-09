<?php

/**
 * OrganisationEditAuditorInterface: what callers outside the component use of OrganisationLifecycleAuditor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Organisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Organisation;

use OCA\OpenRegister\Db\Organisation;

/**
 * Records an edit of an organisation's details in the audit trail.
 *
 * Callers outside lib/Service type against this; ContractBindings binds it
 * to OrganisationLifecycleAuditor.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
interface OrganisationEditAuditorInterface {
	/**
	 * Record an edit of an organisation's details.
	 *
	 * @param Organisation         $organisation The organisation, after the edit.
	 * @param array<string, mixed> $before       The edited fields as they were, by name.
	 * @param array<string, mixed> $after        The same fields as they are now.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function recordUpdate(Organisation $organisation, array $before, array $after): void;
}//end interface
