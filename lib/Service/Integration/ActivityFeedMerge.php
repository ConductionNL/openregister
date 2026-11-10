<?php

/**
 * One feed for an object, drawn from sources that know nothing about each other.
 *
 * 🔑 EVERY COMPETITOR SHOWS ONE TIMELINE AND THIS APP SHOWS FIVE LISTS. The
 * audit trail, the file events, the notes, the linked mail and the NC Activity
 * rows each answer about the same object and each answers separately, so a
 * reader who wants to know what happened reads five places and reconstructs
 * the order themselves. This class is the one place that order is decided.
 *
 * 🔴 EVERY SOURCE IS BOUNDED BEFORE IT IS MERGED, AND THE BOUND IS PER SOURCE.
 * A merge that asked each source for everything and then took the newest page
 * is an unbounded read of five tables to render twenty rows, and it gets
 * slower as the object gets older, which is exactly when somebody is reading
 * the history. Each source hands over at most one page; the merge then takes
 * the newest page of what it was given.
 *
 * 🔴 THE CURSOR IS SHARED, AND IT IS A TIME RATHER THAN AN OFFSET. Five
 * sources with five offsets cannot be paged: the second page of each is not
 * the second page of the feed, and rows appear twice or not at all as soon as
 * the sources are unequal. A timestamp is the one coordinate all five agree
 * on, so the next page asks every source for what is older than the oldest row
 * already shown.
 *
 * 🔴 READS ARE EXCLUDED UNLESS ASKED FOR. Fifteen of the seventeen rows on a
 * measured case detail were reads, and the two writes a handler was looking
 * for sat underneath them. Excluding reads is therefore the DEFAULT rather
 * than a filter chip that starts off, and the toggle is remembered per user by
 * the caller.
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
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Merges the bounded pages of an object's activity sources into one feed.
 *
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */
class ActivityFeedMerge {

	/**
	 * The kinds a merged row may carry.
	 *
	 * The vocabulary is closed and it is the filter chips' vocabulary too: a
	 * source that invented a sixth kind would render a chip nobody can
	 * translate and a row nobody can filter out.
	 *
	 * @var array<int,string>
	 */
	public const KINDS = ['audit', 'file', 'note', 'mail', 'activity'];

	/**
	 * The audit action that records somebody looking at an object.
	 *
	 * @var string
	 */
	public const READ_ACTION = 'read';

	/**
	 * How many rows one page holds when the caller names no size.
	 *
	 * @var int
	 */
	public const DEFAULT_PAGE_SIZE = 25;

	/**
	 * The hard ceiling on a page, whatever the caller asks for.
	 *
	 * A caller asking for ten thousand rows is asking five sources for ten
	 * thousand rows each, and the reader gets a page they cannot read from a
	 * query nobody can afford.
	 *
	 * @var int
	 */
	public const MAX_PAGE_SIZE = 200;

	/**
	 * The newest page of a merged feed.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $bySource Rows per kind, each already bounded by its own source.
	 * @param array<string,mixed>                          $options  `pageSize`, `includeReads`, `kinds`, `from`, `until`, `before`.
	 *
	 * @return array{rows:array<int,array<string,mixed>>,nextCursor:?int,counts:array<string,int>}
	 *         The page, the cursor the next page asks every source for, and how many rows each kind contributed.
	 *
	 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md#requirement-the-activity-leaf-merges-an-objects-feed-from-five-sources
	 */
	public function page(array $bySource, array $options = []): array {
		$pageSize = $this->pageSize(options: $options);
		$rows = [];
		$counts = [];

		foreach (self::KINDS as $kind) {
			$admitted = $this->admitted(rows: $this->normalisedRows(rows: ($bySource[$kind] ?? []), kind: $kind), options: $options);
			$counts[$kind] = count($admitted);
			array_push($rows, ...$admitted);
		}

		$rows = $this->newestFirst(rows: $rows);

		// The cursor is read off the page that is RETURNED, not off everything
		// that was merged: a cursor taken from a row the reader never saw
		// skips the rows between it and the last one on screen.
		$page = array_slice($rows, 0, $pageSize);
		$nextCursor = self::nextCursor(merged: count($rows), page: $page);

		return ['rows' => $page, 'nextCursor' => $nextCursor, 'counts' => $counts];
	}//end page()

	/**
	 * One source's rows in the feed's own shape; non-array rows are skipped.
	 *
	 * @param mixed  $rows The source's rows.
	 * @param string $kind Which source they came from.
	 *
	 * @return array<int,array<string,mixed>> The normalised rows, in source order.
	 */
	private function normalisedRows(mixed $rows, string $kind): array {
		$normalised = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$normalised[] = $this->normalise(row: $row, kind: $kind);
			}
		}

		return $normalised;
	}//end normalisedRows()

	/**
	 * The normalised rows the options admit.
	 *
	 * @param array<int,array<string,mixed>> $rows    The normalised rows of one source.
	 * @param array<string,mixed>            $options The caller's options.
	 *
	 * @return array<int,array<string,mixed>> The admitted rows, in source order.
	 */
	private function admitted(array $rows, array $options): array {
		return array_values(array_filter($rows, fn (array $row): bool => $this->admits(row: $row, options: $options)));
	}//end admitted()

	/**
	 * The cursor for the next page: the last returned row's time, when rows were held back.
	 *
	 * The page is the head of the merged rows, so rows were held back exactly
	 * when more rows were merged than the page returns.
	 *
	 * @param int                            $merged How many rows were merged.
	 * @param array<int,array<string,mixed>> $page   The returned page.
	 *
	 * @return int|null The cursor, or null when this page is the last.
	 */
	private static function nextCursor(int $merged, array $page): ?int {
		if ($merged > count($page) && $page !== []) {
			return (int)$page[(count($page) - 1)]['timestamp'];
		}

		return null;
	}//end nextCursor()

	/**
	 * One row in the feed's own shape, whatever shape its source speaks.
	 *
	 * Five sources name the same four facts four different ways, and a merge
	 * that read each source's spelling at render time would put the translation
	 * in the template, where the next source's spelling is added by whoever
	 * happens to touch it.
	 *
	 * @param array<string,mixed> $row  The source row.
	 * @param string              $kind Which source it came from.
	 *
	 * @return array<string,mixed> The merged row.
	 *
	 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md#requirement-the-activity-leaf-merges-an-objects-feed-from-five-sources
	 */
	public function normalise(array $row, string $kind): array {
		$timestamp = $row['timestamp'] ?? ($row['created'] ?? ($row['date'] ?? 0));
		if (is_string($timestamp) === true) {
			// A source that writes an ISO moment is not wrong; it just speaks
			// the other spelling. An unparseable one sorts as 0, which puts it
			// at the bottom rather than at the top: a row with no time must
			// never head a feed that is read as a sequence.
			$timestamp = (int)max(0, (int)strtotime($timestamp));
		}

		return [
			'id' => (string)($row['id'] ?? ''),
			'kind' => $kind,
			'timestamp' => (int)$timestamp,
			'actor' => (string)($row['actor'] ?? ($row['actor_id'] ?? ($row['user'] ?? ($row['affecteduser'] ?? '')))),
			'summary' => (string)($row['summary'] ?? ($row['title'] ?? ($row['subject'] ?? ''))),
			'action' => (string)($row['action'] ?? ($row['type'] ?? '')),
			// A deep link when the item has one, and an empty string when it
			// does not. A row that linked to the object it is already on would
			// be a link back to the page the reader is standing on.
			'url' => (string)($row['url'] ?? ''),
			'visibility' => (string)($row['visibility'] ?? ''),
		];
	}//end normalise()

	/**
	 * Whether a normalised row belongs on this page.
	 *
	 * @param array<string,mixed> $row     The normalised row.
	 * @param array<string,mixed> $options The caller's options.
	 *
	 * @return bool True when the row is shown.
	 *
	 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md#requirement-reads-are-hidden-unless-asked-for
	 */
	private function admits(array $row, array $options): bool {
		return ($this->admitsKind(row: $row, options: $options) === true
			&& $this->admitsRead(row: $row, options: $options) === true
			&& $this->admitsWindow(row: $row, options: $options) === true);
	}//end admits()

	/**
	 * Whether the row's kind is one the caller asked for.
	 *
	 * An absent or empty kind list means every kind, not none.
	 *
	 * @param array<string,mixed> $row     The normalised row.
	 * @param array<string,mixed> $options The caller's options.
	 *
	 * @return bool True when the kind is admitted.
	 *
	 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md#requirement-reads-are-hidden-unless-asked-for
	 */
	private function admitsKind(array $row, array $options): bool {
		$kinds = ($options['kinds'] ?? null);
		if (is_array($kinds) === true && $kinds !== [] && in_array($row['kind'], $kinds, true) === false) {
			return false;
		}

		return true;
	}//end admitsKind()

	/**
	 * Whether the row survives the read filter.
	 *
	 * Reads are excluded unless asked for, and ONLY audit rows can be reads: a
	 * note is not a read of anything, and excluding a note because its action
	 * happens to be spelled `read` would empty a chip the reader turned on.
	 *
	 * @param array<string,mixed> $row     The normalised row.
	 * @param array<string,mixed> $options The caller's options.
	 *
	 * @return bool True when the row is not a hidden read.
	 *
	 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md#requirement-reads-are-hidden-unless-asked-for
	 */
	private function admitsRead(array $row, array $options): bool {
		$includeReads = (($options['includeReads'] ?? false) === true);
		if ($includeReads === false && $row['kind'] === 'audit' && $row['action'] === self::READ_ACTION) {
			return false;
		}

		return true;
	}//end admitsRead()

	/**
	 * Whether the row's timestamp falls inside the requested window.
	 *
	 * The `before` cursor is STRICT: a row exactly on it is the last row of the
	 * previous page and would otherwise be shown twice.
	 *
	 * @param array<string,mixed> $row     The normalised row.
	 * @param array<string,mixed> $options The caller's options.
	 *
	 * @return bool True when the timestamp is admitted.
	 *
	 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md#requirement-reads-are-hidden-unless-asked-for
	 */
	private function admitsWindow(array $row, array $options): bool {
		$timestamp = $row['timestamp'];

		return self::isBeforeCursor(timestamp: $timestamp, before: ($options['before'] ?? null))
			&& self::isFromOnwards(timestamp: $timestamp, from: ($options['from'] ?? null))
			&& self::isUntilAtMost(timestamp: $timestamp, until: ($options['until'] ?? null));
	}//end admitsWindow()

	/**
	 * Whether a time is older than the paging cursor (no integer cursor admits all).
	 *
	 * @param mixed $timestamp The row's time.
	 * @param mixed $before    The `before` option.
	 *
	 * @return bool
	 */
	private static function isBeforeCursor(mixed $timestamp, mixed $before): bool {
		return is_int($before) === false || $timestamp < $before;
	}//end isBeforeCursor()

	/**
	 * Whether a time is at or after `from` (no integer bound admits all).
	 *
	 * @param mixed $timestamp The row's time.
	 * @param mixed $from      The `from` option.
	 *
	 * @return bool
	 */
	private static function isFromOnwards(mixed $timestamp, mixed $from): bool {
		return is_int($from) === false || $timestamp >= $from;
	}//end isFromOnwards()

	/**
	 * Whether a time is at or before `until` (no integer bound admits all).
	 *
	 * @param mixed $timestamp The row's time.
	 * @param mixed $until     The `until` option.
	 *
	 * @return bool
	 */
	private static function isUntilAtMost(mixed $timestamp, mixed $until): bool {
		return is_int($until) === false || $timestamp <= $until;
	}//end isUntilAtMost()

	/**
	 * The rows in the order a history is read.
	 *
	 * Ties break on the kind and then on the id, so two rows written in the
	 * same second come back in the same order on every request. A merge whose
	 * order wobbles under a tie makes paging drop rows: the cursor is a time,
	 * and two rows sharing one are separated by nothing else unless this says
	 * what separates them.
	 *
	 * @param array<int,array<string,mixed>> $rows The normalised rows.
	 *
	 * @return array<int,array<string,mixed>> The rows, newest first.
	 *
	 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md#requirement-the-activity-leaf-merges-an-objects-feed-from-five-sources
	 */
	private function newestFirst(array $rows): array {
		usort(
			$rows,
			static function (array $left, array $right): int {
				$byTime = ($right['timestamp'] <=> $left['timestamp']);
				if ($byTime !== 0) {
					return $byTime;
				}

				$byKind = (array_search($left['kind'], self::KINDS, true) <=> array_search($right['kind'], self::KINDS, true));
				if ($byKind !== 0) {
					return $byKind;
				}

				return ($left['id'] <=> $right['id']);
			}
		);

		return $rows;
	}//end newestFirst()

	/**
	 * How many rows this page holds.
	 *
	 * @param array<string,mixed> $options The caller's options.
	 *
	 * @return int The page size, bounded.
	 */
	private function pageSize(array $options): int {
		$asked = ($options['pageSize'] ?? self::DEFAULT_PAGE_SIZE);
		if (is_numeric($asked) === false) {
			return self::DEFAULT_PAGE_SIZE;
		}

		return (int)max(1, min(self::MAX_PAGE_SIZE, (int)$asked));
	}//end pageSize()

	/**
	 * How many rows to ask ONE source for, given the page the caller wants.
	 *
	 * One page's worth per source, because the newest page of the feed can in
	 * the worst case come entirely from one of them. Asking each for five
	 * times the page would be the unbounded read this class exists to avoid;
	 * asking each for a fifth of it loses rows whenever the sources are
	 * unequal, which they always are.
	 *
	 * @param array<string,mixed> $options The caller's options.
	 *
	 * @return int The per-source bound.
	 *
	 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md#requirement-the-activity-leaf-merges-an-objects-feed-from-five-sources
	 */
	public function boundPerSource(array $options = []): int {
		return $this->pageSize(options: $options);
	}//end boundPerSource()
}//end class
