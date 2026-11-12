<?php

/**
 * OrganisationScopeMembership
 *
 * Whether a decided organisation scope holds a row of one organisation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * The in-memory rendering of {@see MagicOrganizationHandler::applyOrganizationFilter()}:
 * the same scope modes, compared against one organisation instead of a column.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class OrganisationScopeMembership
{
	/**
	 * Whether the scope holds a row of the given organisation.
	 *
	 * Only null counts as "no organisation", as SQL `IS NULL` does. An unknown
	 * mode holds nothing.
	 *
	 * @param array{mode: string, uuids?: array<int, string>} $scope        The decided scope.
	 * @param string|null                                     $organisation The row's organisation, null when it has none.
	 *
	 * @return bool True when the scope holds the row.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function holds(array $scope, ?string $organisation): bool {
		$mode = ($scope['mode'] ?? null);
		if ($mode === MagicOrganizationHandler::SCOPE_ALL) {
			return true;
		}

		if ($organisation === null) {
			return self::holdsOrganisationless(mode: $mode);
		}

		if (self::holdsMembers(mode: $mode) === false) {
			return false;
		}

		return in_array($organisation, ($scope['uuids'] ?? []), true);
	}//end holds()

	/**
	 * Whether a scope mode holds the rows with no organisation.
	 *
	 * @param mixed $mode The scope mode, any value.
	 *
	 * @return bool True for SCOPE_NULL_ONLY and SCOPE_IN_OR_NULL.
	 */
	private static function holdsOrganisationless(mixed $mode): bool {
		return in_array($mode, [MagicOrganizationHandler::SCOPE_NULL_ONLY, MagicOrganizationHandler::SCOPE_IN_OR_NULL], true);
	}//end holdsOrganisationless()

	/**
	 * Whether a scope mode holds the rows of its listed organisations.
	 *
	 * @param mixed $mode The scope mode, any value.
	 *
	 * @return bool True for SCOPE_IN and SCOPE_IN_OR_NULL.
	 */
	private static function holdsMembers(mixed $mode): bool {
		return in_array($mode, [MagicOrganizationHandler::SCOPE_IN, MagicOrganizationHandler::SCOPE_IN_OR_NULL], true);
	}//end holdsMembers()
}//end class
