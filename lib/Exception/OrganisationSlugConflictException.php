<?php

/**
 * A new organisation's slug is already taken by another organisation.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-organisationservice-must-resolve-per-user-organisation-membership-and-active-context
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown when a new organisation would take a slug another organisation holds.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-organisationservice-must-resolve-per-user-organisation-membership-and-active-context
 */
class OrganisationSlugConflictException extends RuntimeException {

	/**
	 * Create the conflict for a slug.
	 *
	 * @param string         $slug     The slug that is taken.
	 * @param Throwable|null $previous The database error, when the conflict surfaced there.
	 */
	public function __construct(private readonly string $slug, ?Throwable $previous = null) {
		parent::__construct(message: 'An organisation with this name already exists.', code: 409, previous: $previous);
	}//end __construct()

	/**
	 * The slug that is taken.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-organisationservice-must-resolve-per-user-organisation-membership-and-active-context
	 */
	public function getSlug(): string {
		return $this->slug;
	}//end getSlug()
}//end class
