<?php

/**
 * OpenRegister unique field scope
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\OrganisationServiceInterface;

/**
 * The rows a unique-field check counts: those of the organisation the write lands in, and the rows with no organisation.
 *
 * The count ignores the caller's RBAC row rules, so a duplicate is found even
 * in a row the caller cannot read, and it never counts another organisation's
 * rows, so a refusal says nothing about them.
 *
 * @spec openspec/specs/object-validation/spec.md
 */
final class UniqueFieldScope {
	/**
	 * Bind the scope to the organisation lookups.
	 *
	 * @param OrganisationServiceInterface|null $organisations Resolves the caller's organisation; without it only
	 *                                                         an organisation context or org-less rows are counted.
	 */
	public function __construct(
		private readonly ?OrganisationServiceInterface $organisations,
	) {
	}//end __construct()

	/**
	 * The count filters: the unique values, the organisation boundary, and RBAC and multitenancy off.
	 *
	 * @param array<string, mixed> $object        The object being written.
	 * @param array<string, mixed> $uniqueFilters The unique field values, keyed by field.
	 *
	 * @return array<string, mixed> The filters for the count.
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	public function countFilters(array $object, array $uniqueFilters): array {
		$organisation = $this->writeOrganisation(object: $object);

		$criterion = ['isnull' => true];
		if ($organisation !== null) {
			$criterion = ['in' => [$organisation], 'orNull' => true];
		}

		return $uniqueFilters + [
			'@self'         => ['organisation' => $criterion],
			'_rbac'         => false,
			'_multitenancy' => false,
		];
	}//end countFilters()

	/**
	 * Whether the org-less rows need a count of their own.
	 *
	 * Inside an organisation context the context bounds every read to its
	 * organisation, so the count from countFilters() misses the rows with no
	 * organisation; those are counted outside the context instead.
	 *
	 * @return bool True inside an organisation context.
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OrganisationContext is an ambient scope marker, read statically by design.
	 */
	public function countsOrganisationlessSeparately(): bool {
		return OrganisationContext::isActive();
	}//end countsOrganisationlessSeparately()

	/**
	 * The organisation the write lands in.
	 *
	 * An organisation context wins. A `@self.organisation` counts only when the
	 * caller may write into it, the same rule the save applies; otherwise the
	 * organisation a new object is given.
	 *
	 * @param array<string, mixed> $object The object being written.
	 *
	 * @return string|null The organisation uuid, or null when none is known.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OrganisationContext is an ambient scope marker, read statically by design.
	 */
	private function writeOrganisation(array $object): ?string {
		$context = OrganisationContext::current();
		if ($context !== null) {
			return $context;
		}

		if ($this->organisations === null) {
			return null;
		}

		$requested = ($object['@self']['organisation'] ?? null);
		if (is_string($requested) === true && $requested !== ''
			&& $this->organisations->hasAccessToOrganisation($requested) === true
		) {
			return $requested;
		}

		$organisation = $this->organisations->getOrganisationForNewEntity();
		if ($organisation === null || $organisation === '') {
			return null;
		}

		return $organisation;
	}//end writeOrganisation()
}//end class
