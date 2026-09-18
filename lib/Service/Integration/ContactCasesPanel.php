<?php

/**
 * What one contact is involved in, grouped the way a reader asks the question.
 *
 * 🔑 THE QUESTION IS "WHAT IS THIS PERSON INVOLVED IN", AND THE ANSWER IS NOT
 * A FLAT LIST. A contact linked to nine objects across three schemas reads as
 * nine rows a reader has to sort themselves; grouped by schema it reads as
 * three answers. The grouping is here, apart from the fetching, so the rule
 * can be driven without an address book or a database.
 *
 * 🔴 AN OBJECT THE READER MAY NOT SEE IS COUNTED, NEVER NAMED, AND NEVER
 * SILENTLY DROPPED. Dropping it under-reports: the panel would say a contact
 * is involved in two cases when they are involved in five, and nothing on
 * screen would say so. Naming it leaks a title. So it is counted apart, and
 * the count is deliberately NOT broken down by schema — a per-schema count of
 * things you may not read is an oracle that tells you which register somebody
 * appears in.
 *
 * 🔴 THE LINKS THAT REACH THIS CLASS ARE ALREADY SCOPED. `ContactService`
 * resolves them only for contacts in the caller's OWN address books, which is
 * the IDOR guard that stops an enumerable CardDAV uid from answering about
 * other people's contacts. This class narrows further and widens never.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
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
 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Groups a contact's linked objects by schema, for the cases panel.
 *
 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md
 */
class ContactCasesPanel {

	/**
	 * How many rows one group shows before it says "and more".
	 *
	 * A panel beside a contact card is a summary. A contact linked to four
	 * hundred objects must not render four hundred rows into a sidebar, and
	 * the count above the group is what tells the reader there are more.
	 *
	 * @var int
	 */
	public const ROWS_PER_GROUP = 10;

	/**
	 * A contact's links, grouped by the schema they point at.
	 *
	 * @param array<int,array<string,mixed>> $rows Resolved rows: `schema`, `schemaLabel`, `objectUuid`, `title`, `status`, `url`, `readable`.
	 *
	 * @return array{groups:array<int,array<string,mixed>>,unreadable:int,total:int}
	 *         The groups newest schema-label first, how many rows the reader may not see, and the total.
	 *
	 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md
	 */
	public function group(array $rows): array {
		$groups = [];
		$unreadable = 0;
		$total = 0;

		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$total++;

			if (($row['readable'] ?? true) === false) {
				// Counted apart and not broken down by schema: a per-schema
				// count of things you may not read tells you which register
				// somebody appears in, which is most of what you were not
				// allowed to know.
				$unreadable++;
				continue;
			}

			$schema = trim((string)($row['schema'] ?? ''));
			if ($schema === '') {
				// A link with no schema cannot be grouped and must not be
				// invented into one: it is counted as unreadable, which is
				// what it is to a reader.
				$unreadable++;
				continue;
			}

			if (isset($groups[$schema]) === false) {
				$groups[$schema] = [
					'schema' => $schema,
					'label' => trim((string)($row['schemaLabel'] ?? $schema)),
					'count' => 0,
					'rows' => [],
				];
			}

			$groups[$schema]['count']++;
			if (count($groups[$schema]['rows']) < self::ROWS_PER_GROUP) {
				$groups[$schema]['rows'][] = [
					'objectUuid' => (string)($row['objectUuid'] ?? ''),
					'title' => trim((string)($row['title'] ?? '')),
					'status' => trim((string)($row['status'] ?? '')),
					'url' => (string)($row['url'] ?? ''),
					'role' => trim((string)($row['role'] ?? '')),
				];
			}
		}

		$groups = array_values($groups);
		usort(
			$groups,
			static function (array $left, array $right): int {
				// The biggest group first, because that is where a reader
				// looks; ties by label so the order is a fact rather than
				// whatever the map happened to hold.
				$byCount = ($right['count'] <=> $left['count']);

				return ($byCount !== 0 ? $byCount : strcasecmp($left['label'], $right['label']));
			}
		);

		return ['groups' => $groups, 'unreadable' => $unreadable, 'total' => $total];
	}//end group()

	/**
	 * Whether a group is showing everything it counted.
	 *
	 * @param array<string,mixed> $group One group.
	 *
	 * @return bool True when rows were held back.
	 *
	 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md
	 */
	public function hasMore(array $group): bool {
		return ((int)($group['count'] ?? 0) > count($group['rows'] ?? []));
	}//end hasMore()
}//end class
