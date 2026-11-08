<?php

/**
 * OrganisationUsageServiceInterface: what callers outside the Service component use of OrganisationUsageService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Organisation;

/**
 * Measures what an organisation uses, as the organisation endpoints report it.
 *
 * @spec openspec/specs/organisation-usage-measured/spec.md
 */
interface OrganisationUsageServiceInterface {

	/**
	 * Measure the organisation's usage.
	 *
	 * - `users`, `groups`: the organisation's members and groups.
	 * - `storage`: bytes of the files in the folders of the organisation's live
	 *   objects; null when it could not be read.
	 * - `requests`: the requests counted for it this hour by the quota
	 *   middleware, which counts only for an organisation with a request quota
	 *   and only with APCu; null otherwise.
	 * - `bandwidth`: null. The middleware counts JSON response bodies only,
	 *   which is not the organisation's bandwidth.
	 * - `objects`: the organisation's live objects of the schema its object
	 *   quota names (every schema when it names none), counted only for an
	 *   organisation with an object quota; null otherwise or when they could
	 *   not be counted.
	 *
	 * @param Organisation $organisation The organisation.
	 *
	 * @return array{storage: int|null, bandwidth: null, requests: int|null, objects: int|null, users: int, groups: int}
	 *
	 * @spec openspec/specs/organisation-usage-measured/spec.md
	 * @spec openspec/specs/organisation-quota/spec.md#requirement-an-organisation-quota-limits-its-users-and-its-objects
	 */
	public function measure(Organisation $organisation): array;
}//end interface
