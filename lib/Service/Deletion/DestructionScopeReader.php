<?php

/**
 * DestructionScopeReader — reads the destruction scope a schema declares as an
 * injectable collaborator.
 *
 * The vocabulary itself and the rule for what may be declared live on
 * {@see DestructionScope}. This reader holds only the act of reading a scope off
 * a schema, so a caller can depend on an instance rather than reaching for a
 * static, and a test can substitute one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Deletion
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use OCA\OpenRegister\Db\Schema;

/**
 * Reads the destruction scope a schema declares.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
class DestructionScopeReader {
	/**
	 * Read the scope a schema declares, keeping only known members.
	 *
	 * An instance that declares nothing gets an empty scope, which destroys
	 * exactly what it destroyed before this change: the object row and its
	 * bound folder, through the existing delete path.
	 *
	 * @param Schema|null $schema The schema, when it resolves.
	 *
	 * @return array{scope: array<int, string>, unknown: array<int, string>} Known members and the rest.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function declaredOn(?Schema $schema): array {
		if ($schema === null) {
			return [
				'scope' => [],
				'unknown' => [],
			];
		}

		$declared = ($schema->getArchive()[DestructionScope::SCHEMA_KEY] ?? []);
		if (is_array($declared) === false) {
			return [
				'scope' => [],
				'unknown' => [],
			];
		}

		$scope = [];
		$unknown = [];
		foreach ($declared as $member) {
			if (is_string($member) === false) {
				continue;
			}

			$name = trim($member);
			if (in_array(needle: $name, haystack: DestructionScope::MEMBERS, strict: true) === true) {
				$scope[] = $name;
				continue;
			}

			$unknown[] = $name;
		}

		return [
			'scope' => array_values(array_unique($scope)),
			'unknown' => array_values(array_unique($unknown)),
		];
	}//end declaredOn()
}//end class
