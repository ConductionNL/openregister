<?php

/**
 * The public half of an object's timeline, as a stranger may read it.
 *
 * ONE CLASS FOR EVERY ANONYMOUS READER. Two surfaces publish a timeline to
 * somebody with no account: the access-link reader (`/api/public/links`) and
 * the case-token resolve (`/api/public/case-tokens`). Each used to decide for
 * itself what an entry looked like on the way out, and the access-link reader
 * decided nothing: it handed every public note to the link holder as the note
 * service shaped it, with the author's user id and display name on it. So the
 * decision lives here, once, and both surfaces ask for it.
 *
 * THE PROJECTION IS A WHITELIST. Five keys leave: `id`, `kind`, `message`,
 * `fields`, `occurredAt`. Naming the fields to drop would publish the next one
 * somebody adds to an entry; naming the fields to keep cannot.
 *
 * RECORDS AND NOTES, NOT RECORDS ALONE. A kinded entry (a delivered decision,
 * a status that was announced, a portal message) exists only as a timeline
 * record. A note exists as a comment, and is projected into a record when it
 * is written through the notes endpoint; a note written before records
 * existed has no record at all. Reading only records would drop those notes
 * from the public view without a word. Reading only notes, as the access-link
 * reader did, never showed a kinded entry. So both are read, and a note whose
 * record is present is dropped in favour of the record, which carries the kind.
 *
 * EACH SOURCE FAILS ON ITS OWN. A record table that cannot be read does not
 * hide the notes, and the other way round. Every failure is logged at warning.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Timeline;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\TimelineVisibilityService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and projects the public timeline of one object.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
 */
class PublicTimeline {

	/**
	 * The only keys an entry carries out of the building.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = ['id', 'kind', 'message', 'fields', 'occurredAt'];

	/**
	 * Constructor.
	 *
	 * @param TimelineEntryService $entries Reads the timeline records.
	 * @param NoteService          $notes   Reads the notes a record may not exist for yet.
	 * @param LoggerInterface      $logger  PSR logger.
	 */
	public function __construct(
		private readonly TimelineEntryService $entries,
		private readonly NoteService $notes,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The public entries on one object, newest first, projected.
	 *
	 * The filter is fixed at `public` and is not a parameter, so no caller can
	 * ask this class for the internal half.
	 *
	 * @param ObjectEntity $object The object the timeline hangs on.
	 * @param integer      $limit  How many entries at most.
	 *
	 * @return array<int, array<string, mixed>> The entries, each with exactly the keys in {@see self::KEYS}.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
	 */
	public function forObject(ObjectEntity $object, int $limit = 50): array {
		$records = $this->records(object: $object, limit: $limit);

		$projected = [];
		$noteIdsWithARecord = [];
		foreach ($records as $record) {
			$commentId = $record['commentId'] ?? null;
			if ($commentId !== null) {
				$noteIdsWithARecord[(string)$commentId] = true;
			}

			$projected[] = $this->fromRecord(row: $record);
		}

		foreach ($this->notesOf(object: $object, limit: $limit) as $note) {
			if (isset($noteIdsWithARecord[(string)($note['id'] ?? '')]) === true) {
				continue;
			}

			$projected[] = $this->fromNote(note: $note);
		}

		usort(
			$projected,
			fn (array $left, array $right): int => $this->moment(entry: $right) <=> $this->moment(entry: $left)
		);

		return array_slice($projected, 0, $limit);
	}//end forObject()

	/**
	 * When an entry happened, as a number that sorts.
	 *
	 * Both sources write ISO 8601 with an offset, but not always the same
	 * offset, so comparing the strings would order a summer entry against a
	 * winter one by the digits of their offsets. An entry with no readable
	 * moment sorts last rather than first.
	 *
	 * @param array<string, mixed> $entry A projected entry.
	 *
	 * @return integer The Unix time, or 0 when the moment cannot be read.
	 */
	private function moment(array $entry): int {
		$moment = strtotime((string)$entry['occurredAt']);
		if ($moment === false) {
			return 0;
		}

		return $moment;
	}//end moment()

	/**
	 * The object's public timeline records, as plain rows.
	 *
	 * @param ObjectEntity $object The object.
	 * @param integer      $limit  How many at most.
	 *
	 * @return array<int, array<string, mixed>> The rows, or none when the records cannot be read.
	 */
	private function records(ObjectEntity $object, int $limit): array {
		try {
			$entries = $this->entries->listForObject(
				object: $object,
				visibility: TimelineVisibilityService::PUBLIC_ENTRY,
				limit: $limit
			);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[PublicTimeline] Could not read the public timeline records: ' . $failure->getMessage()
			);
			return [];
		}

		$rows = [];
		foreach ($entries as $entry) {
			$rows[] = $entry->jsonSerialize();
		}

		return $rows;
	}//end records()

	/**
	 * The object's public notes.
	 *
	 * @param ObjectEntity $object The object.
	 * @param integer      $limit  How many at most.
	 *
	 * @return array<int, array<string, mixed>> The notes, or none when they cannot be read.
	 */
	private function notesOf(ObjectEntity $object, int $limit): array {
		try {
			return $this->notes->getNotesForObject(
				objectUuid: (string)$object->getUuid(),
				limit: $limit,
				offset: 0,
				visibility: TimelineVisibilityService::PUBLIC_ENTRY
			);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[PublicTimeline] Could not read the public notes: ' . $failure->getMessage()
			);
			return [];
		}
	}//end notesOf()

	/**
	 * One record, cut down to the whitelist.
	 *
	 * @param array<string, mixed> $row The record as it serialises.
	 *
	 * @return array<string, mixed> The projection.
	 */
	private function fromRecord(array $row): array {
		return [
			'id' => (string)($row['id'] ?? ''),
			'kind' => (string)($row['kind'] ?? ''),
			'message' => (string)($row['message'] ?? ''),
			'fields' => (array)($row['fields'] ?? []),
			'occurredAt' => (string)($row['created'] ?? ''),
		];
	}//end fromRecord()

	/**
	 * One note, cut down to the same whitelist.
	 *
	 * A note has no kind and no fields, and says so with the empty values
	 * rather than by leaving the keys out, so a reader sees one shape.
	 *
	 * @param array<string, mixed> $note The note as the note service shapes it.
	 *
	 * @return array<string, mixed> The projection.
	 */
	private function fromNote(array $note): array {
		return [
			'id' => (string)($note['id'] ?? ''),
			'kind' => '',
			'message' => (string)($note['message'] ?? ''),
			'fields' => [],
			'occurredAt' => (string)($note['createdAt'] ?? ''),
		];
	}//end fromNote()
}//end class
