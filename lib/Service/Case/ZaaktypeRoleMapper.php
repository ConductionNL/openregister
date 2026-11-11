<?php

/**
 * OpenRegister ZaaktypeRoleMapper
 *
 * A zaaktype's `roltypen` as case-plan candidate roles: every named role is
 * carried, and the first behandelaar becomes every human item's candidate role.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * Maps `roltypen` for {@see ZaaktypeCaseSkeletonMapper}.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
 */
final class ZaaktypeRoleMapper {

	/**
	 * The report line for a role without a name: status, reason, action.
	 */
	private const NOTE_ROLE_UNNAMED = [ZaaktypeCaseSkeletonMapper::UNMAPPED, 'No `omschrijving`.', 'Name the role.'];

	/**
	 * `roltypen` -> candidate roles; the behandelaar goes on the human items.
	 *
	 * @param array<string, mixed>  $zaaktype The document.
	 * @param ZaaktypeSkeletonDraft $draft    The skeleton being built.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	public function mapRoles(array $zaaktype, ZaaktypeSkeletonDraft $draft): void {
		$roles = ($zaaktype['roltypen'] ?? []);
		if (is_array($roles) === false || $roles === []) {
			return;
		}

		$draft->settings['candidateRoles'] = [];
		foreach ($roles as $index => $role) {
			$named = self::namedRole(role: $role);
			if ($named === null) {
				$draft->note(element: 'roltypen[' . (int)$index . ']', line: self::roleNote(role: $role));
				continue;
			}

			self::placeRole(role: $named, draft: $draft);
		}
	}//end mapRoles()

	/**
	 * A role's trimmed name and generic designation, or null when it is not an object or has no name.
	 *
	 * @param mixed $role The roltype as stored.
	 *
	 * @return array{role: string, generic: string}|null The role.
	 */
	private static function namedRole(mixed $role): ?array {
		if (is_array($role) === false) {
			return null;
		}

		$name = trim((string)($role['omschrijving'] ?? ''));
		if ($name === '') {
			return null;
		}

		return ['role' => $name, 'generic' => trim((string)($role['omschrijvingGeneriek'] ?? ''))];
	}//end namedRole()

	/**
	 * The report line for a role that could not be used.
	 *
	 * @param mixed $role The roltype as stored.
	 *
	 * @return array{0: string, 1: string, 2: string} The line.
	 */
	private static function roleNote(mixed $role): array {
		if (is_array($role) === false) {
			return ZaaktypeCaseSkeletonMapper::NOTE_NOT_OBJECT;
		}

		return self::NOTE_ROLE_UNNAMED;
	}//end roleNote()

	/**
	 * Carry one named role, and make the first behandelaar the candidate role of every human item.
	 *
	 * @param array{role: string, generic: string} $role  The role.
	 * @param ZaaktypeSkeletonDraft                $draft The skeleton being built.
	 *
	 * @return void
	 */
	private static function placeRole(array $role, ZaaktypeSkeletonDraft $draft): void {
		$draft->settings['candidateRoles'][] = $role;
		$element = sprintf("roltypen '%s'", $role['role']);
		if ($role['generic'] === 'behandelaar' && $draft->behandelaar === null) {
			$draft->behandelaar = $role['role'];
			$reason = 'The behandelaar role became the candidate role of every human item.';
			$action = sprintf("Make sure a group named '%s' exists, or the role will not resolve.", $role['role']);
			$draft->note(element: $element, line: [ZaaktypeCaseSkeletonMapper::MAPPED, $reason, $action]);
			return;
		}

		$reason = sprintf("Carried in settings.candidateRoles with generic designation '%s'; not placed on any item.", $role['generic']);
		$action = 'Assign it as candidateRole to the items this role performs.';
		$draft->note(element: $element, line: [ZaaktypeCaseSkeletonMapper::CARRIED, $reason, $action]);
	}//end placeRole()
}//end class
