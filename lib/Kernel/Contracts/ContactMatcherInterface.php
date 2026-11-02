<?php

/**
 * ContactMatcherInterface: matches a contact to the objects that name it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Matches a contact to the objects that name it. Implemented by Service\ContactMatchingService.
 */
interface ContactMatcherInterface {

	/**
	 * Combined contact matching with deduplication.
	 *
	 * Calls matchByEmail first, then matchByName and matchByOrganization if provided.
	 * Deduplicates by object UUID, keeping the highest confidence match.
	 *
	 * @param string $email The email address (required)
	 * @param string|null $name The display name (optional)
	 * @param string|null $organization The organization name (optional)
	 *
	 * @return array Combined, deduplicated match results sorted by confidence
	 */
	public function matchContact(
		string $email,
		?string $name = null,
		?string $organization = null,
	): array;

	/**
	 * Get related object counts grouped by schema title.
	 *
	 * @param array $matches The match results from matchContact()
	 *
	 * @return array Associative array of schema title => count
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function getRelatedObjectCounts(array $matches): array;
}//end interface
