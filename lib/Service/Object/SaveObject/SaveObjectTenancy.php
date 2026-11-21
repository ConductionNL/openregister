<?php

/**
 * Who a single-object save acts for: the organisation and the admin bypass readers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * Who a single-object save acts for: the organisation and the admin bypass readers.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class SaveObjectTenancy {
	/**
	 * Bundle the collaborators.
	 *
	 * @param OrganisationServiceInterface $organisationService Service for organisation operations.
	 * @param ?IGroupManager               $groupManager        Group manager for admin-bypass detection.
	 * @param ?IAppConfig                  $appConfig           App-config reader (admin bypass, log redaction).
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly OrganisationServiceInterface $organisationService,
		public readonly ?IGroupManager $groupManager = null,
		public readonly ?IAppConfig $appConfig = null,
	) {
	}//end __construct()
}//end class
