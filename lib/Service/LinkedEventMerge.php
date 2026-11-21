<?php

/**
 * OpenRegister linked-event merge
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * The events linked to one object, merged from the link table and the
 * legacy X-OR-* scan, keyed by `${calendarUri}|${eventUid}`.
 *
 * Built per call by CalendarLinkService::getLinkedEvents().
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class LinkedEventMerge {

	/**
	 * The merged rows, keyed by `${calendarUri}|${eventUid}`.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $rows = [];

	/**
	 * Add a link-table row.
	 *
	 * @param string              $key     The `${calendarUri}|${eventUid}` key.
	 * @param array<string,mixed> $payload The serialised link row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function addLinkRow(string $key, array $payload): void {
		$this->rows[$key] = $payload;
	}//end addLinkRow()

	/**
	 * Merge one X-OR-* scan event; an event without a UID is skipped.
	 *
	 *   - If the (calendarUri, eventUid) key is already present: mark as 'both'
	 *     and refresh free-text fields from the live scan.
	 *   - If only the UID matches (calendar URI missing from the live event):
	 *     mark the existing entry as 'both' via fallback dedupe.
	 *   - Otherwise: insert a new 'xor-only' entry.
	 *
	 * @param array<string,mixed> $event       X-OR-* scan event row.
	 * @param string              $calendarUri Resolved calendar URI (may be '').
	 *
	 * @return void
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function addXorEvent(array $event, string $calendarUri): void {
		$eventUid = (string)($event['uid'] ?? '');
		if ($eventUid === '') {
			return;
		}

		$key = $calendarUri . '|' . $eventUid;
		if (isset($this->rows[$key]) === true) {
			$this->rows[$key] = $this->refreshedFromXor(row: $this->rows[$key], event: $event);
			return;
		}

		if ($this->markUidAsBoth(eventUid: $eventUid) === false) {
			$this->rows[$key] = array_merge($event, ['source' => 'xor-only', 'calendarUri' => $calendarUri]);
		}
	}//end addXorEvent()

	/**
	 * The merged rows.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function rows(): array {
		return array_values($this->rows);
	}//end rows()

	/**
	 * A link-table row also found by the X-OR-* scan: source both, free-text fields from the live event.
	 *
	 * @param array<string,mixed> $row   The merged link-table payload.
	 * @param array<string,mixed> $event The live X-OR event.
	 *
	 * @return array<string,mixed>
	 */
	private function refreshedFromXor(array $row, array $event): array {
		$row['source'] = 'both';
		foreach (['summary', 'dtstart', 'dtend', 'location', 'description', 'attendees', 'status'] as $field) {
			if (isset($event[$field]) === true && $event[$field] !== '') {
				$row[$field] = $event[$field];
			}
		}

		return $row;
	}//end refreshedFromXor()

	/**
	 * Mark the first merged entry with the event UID as found by both sources.
	 *
	 * @param string $eventUid The event UID.
	 *
	 * @return bool Whether an entry was marked.
	 */
	private function markUidAsBoth(string $eventUid): bool {
		foreach (array_keys($this->rows) as $existingKey) {
			if (str_ends_with($existingKey, '|' . $eventUid) === true) {
				$this->rows[$existingKey]['source'] = 'both';
				return true;
			}
		}

		return false;
	}//end markUidAsBoth()
}//end class
