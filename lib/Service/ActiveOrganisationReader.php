<?php

/**
 * Core's answer to which organisation the caller is acting in.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Kernel\Contracts\ActiveOrganisationReaderInterface;

/**
 * Reads the active organisation through {@see OrganisationServiceInterface},
 * with its own fallback rules, and reduces it to the UUID.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 */
class ActiveOrganisationReader implements ActiveOrganisationReaderInterface {

	/**
	 * Constructor.
	 *
	 * @param OrganisationServiceInterface $organisationService Resolves the active organisation.
	 */
	public function __construct(
		private readonly OrganisationServiceInterface $organisationService,
	) {
	}//end __construct()

	/**
	 * The active organisation's UUID.
	 *
	 * @return string|null The UUID, or null when there is none.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public function activeOrganisationUuid(): ?string {
		$uuid = $this->organisationService->getActiveOrganisation()?->getUuid();
		if ($uuid === null || trim((string) $uuid) === '') {
			return null;
		}

		return (string) $uuid;
	}//end activeOrganisationUuid()
}//end class
