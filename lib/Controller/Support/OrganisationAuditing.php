<?php

/**
 * OpenRegister organisation domain
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Organisation\OrganisationEditAuditorInterface;

/**
 * The domain services of the organisation API.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
final class OrganisationAuditing {
	/**
	 * Bundle the services.
	 *
	 * @param ?OrganisationEditAuditorInterface $lifecycleAuditor Records an edit of an organisation's details. Null only in legacy fixtures.
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ?OrganisationEditAuditorInterface $lifecycleAuditor = null,
	) {
	}//end __construct()
}//end class
