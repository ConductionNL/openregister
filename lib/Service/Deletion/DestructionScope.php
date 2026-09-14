<?php

/**
 * DestructionScope — the closed vocabulary of what a schema may declare is
 * destroyed with an object, and the evidence that can never be in it.
 *
 * Cascade rules and destruction scope answer different questions. A cascade
 * rule decides what must not dangle; a scope decides what must not survive. A
 * note on a destroyed case does not dangle, and it does hold personal data, so
 * referential integrity has nothing to say about it. That is why the scope is
 * declared rather than inferred from a foreign key.
 *
 * The evidence of a destruction is outside the vocabulary BY CONSTRUCTION: no
 * member names it, so no schema can declare it, and the audit action that
 * carries it is named here so every handler that touches audit rows can
 * exclude it from the same one definition.
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
 * The declared destruction scope vocabulary.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
final class DestructionScope {
	/**
	 * The object's version history, held as audit rows.
	 *
	 * @var string
	 */
	public const VERSIONS = 'versions';

	/**
	 * Notes written on the object.
	 *
	 * @var string
	 */
	public const NOTES = 'notes';

	/**
	 * Files in the object's bound folder.
	 *
	 * @var string
	 */
	public const FILES = 'files';

	/**
	 * CalDAV tasks linked to the object.
	 *
	 * @var string
	 */
	public const TASKS = 'tasks';

	/**
	 * The timeline rows that are not notes: the email, calendar, contact and
	 * deck links drawn beside them.
	 *
	 * @var string
	 */
	public const TIMELINE = 'timeline';

	/**
	 * The remaining audit rows whose payload carries the object's content.
	 *
	 * @var string
	 */
	public const AUDIT_CONTENT = 'auditContent';

	/**
	 * Everything a schema may declare. A member outside this list is a
	 * declaration nobody can honour, and an unclear scope refuses rather than
	 * guesses (ADR-005).
	 *
	 * @var array<int, string>
	 */
	public const MEMBERS = [
		self::VERSIONS,
		self::NOTES,
		self::FILES,
		self::TASKS,
		self::TIMELINE,
		self::AUDIT_CONTENT,
	];

	/**
	 * The audit action that records a destruction.
	 *
	 * A destruction record with no object is the point, so this row is never
	 * inside a scope. Every handler that touches audit rows excludes it from
	 * this one definition.
	 *
	 * @var string
	 */
	public const DESTRUCTION_ACTION = 'object.destroyed';

	/**
	 * The audit actions that ARE the evidence, excluded from every scope.
	 *
	 * @var array<int, string>
	 */
	public const EVIDENCE_ACTIONS = [self::DESTRUCTION_ACTION];

	/**
	 * The schema key a destruction scope is declared under.
	 *
	 * @var string
	 */
	public const SCHEMA_KEY = 'destructionScope';

	/**
	 * The audit actions that make up an object's version history.
	 *
	 * @var array<int, string>
	 */
	public const VERSION_ACTIONS = ['create', 'update'];

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
	public static function declaredOn(?Schema $schema): array {
		if ($schema === null) {
			return [
				'scope' => [],
				'unknown' => [],
			];
		}

		$declared = ($schema->getArchive()[self::SCHEMA_KEY] ?? []);
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
			if (in_array(needle: $name, haystack: self::MEMBERS, strict: true) === true) {
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

	/**
	 * Whether an audit action is evidence of a destruction and therefore
	 * outside every scope.
	 *
	 * @param string|null $action The audit action.
	 *
	 * @return bool True when the row is evidence.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public static function isEvidence(?string $action): bool {
		return in_array(needle: (string)$action, haystack: self::EVIDENCE_ACTIONS, strict: true);
	}//end isEvidence()
}//end class
