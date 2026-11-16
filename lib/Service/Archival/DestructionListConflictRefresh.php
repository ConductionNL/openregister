<?php

/**
 * OpenRegister DestructionListConflictRefresh
 *
 * The link conflicts of destruction-list entries as they stand now, for a
 * reviewer reading the list and for the check before a destroy answer.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-conflicts-are-current-when-a-reviewer-reads-them
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recomputes the link conflicts of list entries without saving them (design D-3).
 *
 * A date can move between a list's creation and its review, so a read shows
 * the conflicts as they are at that moment and says when they were checked.
 * The stored list keeps what it looked like when it was made.
 *
 * An entry whose record cannot be read, or whose check could not run, is
 * reported with no conflicts and `linkConflictsTruncated: true`: the flag
 * that tells a reviewer the list of conflicts is partial, never "none".
 *
 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-conflicts-are-current-when-a-reviewer-reads-them
 */
class DestructionListConflictRefresh {

	/**
	 * Constructor.
	 *
	 * @param DestructionListLinkConflicts $linkConflicts Finds the conflicts of a set of records.
	 * @param MagicMapperInterface         $objects       Loads the entries' records by uuid.
	 * @param LoggerInterface              $logger        For a record set that could not be read.
	 * @param ITimeFactory|null            $clock         Tells the moment of the check; the system clock when null.
	 */
	public function __construct(
		private readonly DestructionListLinkConflicts $linkConflicts,
		private readonly MagicMapperInterface $objects,
		private readonly LoggerInterface $logger,
		private readonly ?ITimeFactory $clock = null,
	) {
	}//end __construct()

	/**
	 * The entries with their conflicts recomputed and the moment of the check.
	 *
	 * Each entry keeps every other key it had and gets `linkConflicts`,
	 * `linkConflictsTruncated` and `linkConflictsCheckedAt`.
	 *
	 * @param array<int, array<string, mixed>> $entries Entries or pending rows, each with a `uuid`.
	 *
	 * @return array{entries: array<int, array<string, mixed>>, checkedAt: string, count: int}
	 *
	 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-conflicts-are-current-when-a-reviewer-reads-them
	 */
	public function refresh(array $entries): array {
		$checkedAt = $this->now()->format(DateTimeInterface::ATOM);
		$uuids     = [];
		foreach ($entries as $entry) {
			$uuids[] = (string) ($entry['uuid'] ?? '');
		}

		$current = $this->current(uuids: $uuids);
		$count   = 0;
		$fresh   = [];
		foreach ($entries as $entry) {
			$found = ($current[(string) ($entry['uuid'] ?? '')] ?? self::partial());
			$entry['linkConflicts']          = $found['conflicts'];
			$entry['linkConflictsTruncated'] = $found['truncated'];
			$entry['linkConflictsCheckedAt'] = $checkedAt;
			$count += count($found['conflicts']);
			$fresh[] = $entry;
		}

		return ['entries' => $fresh, 'checkedAt' => $checkedAt, 'count' => $count];
	}//end refresh()

	/**
	 * The conflicts of one entry's record as they stand now.
	 *
	 * @param string $entryUuid The uuid of the record the entry is about.
	 *
	 * @return array{conflicts: array<int, array<string, mixed>>, truncated: bool}
	 *
	 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-destroying-over-a-conflict-needs-an-explicit-acknowledgement
	 */
	public function forEntry(string $entryUuid): array {
		return ($this->current(uuids: [$entryUuid])[$entryUuid] ?? self::partial());
	}//end forEntry()

	/**
	 * The current conflicts of the records with these uuids, by uuid.
	 *
	 * @param array<int, string> $uuids The entries' record uuids.
	 *
	 * @return array<string, array{conflicts: array<int, array<string, mixed>>, truncated: bool}>
	 */
	private function current(array $uuids): array {
		$uuids = array_values(array_unique(array_filter($uuids, static fn (string $uuid): bool => $uuid !== '')));
		if ($uuids === []) {
			return [];
		}

		try {
			$records = $this->objects->findMultiple($uuids);
		} catch (Throwable $failure) {
			$this->logger->error(
				'[DestructionListConflictRefresh] the entries\' records could not be read; their conflicts are reported partial: ' . $failure->getMessage(),
				['exception' => $failure]
			);
			return [];
		}

		return $this->linkConflicts->forEntries(entries: array_values($records));
	}//end current()

	/**
	 * The result for an entry whose conflicts could not be established.
	 *
	 * @return array{conflicts: array<int, array<string, mixed>>, truncated: bool}
	 */
	private static function partial(): array {
		return ['conflicts' => [], 'truncated' => true];
	}//end partial()

	/**
	 * The current moment, from the injected clock when there is one.
	 *
	 * @return DateTimeInterface Now.
	 */
	private function now(): DateTimeInterface {
		if ($this->clock === null) {
			return new DateTimeImmutable();
		}

		return $this->clock->now();
	}//end now()
}//end class
