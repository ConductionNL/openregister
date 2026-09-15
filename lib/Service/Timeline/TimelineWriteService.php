<?php

/**
 * TimelineWriteService: writing one entry, end to end.
 *
 * Writing a timeline entry is five things and they have to happen in one
 * order: the comment that carries the text, the record that makes it an
 * entry, the references its text produced, the principals it named, and, for
 * a multi-object note, the same again on each related object with the entries
 * tied together afterwards.
 *
 * That order lives HERE rather than in a controller, because there are two
 * callers: the timeline endpoint this change adds, and the notes endpoint that
 * already existed. A note written the old way still becomes a record, or it
 * would be invisible to the entry search, and half a timeline that cannot be
 * searched is worse than none.
 *
 * WHAT HAPPENS WHEN A LATER STEP FAILS. The comment and the record are the
 * entry; references and mentions are things derived from it. A failure in a
 * derived step is logged and the entry stands, because losing somebody's note
 * to a regex that stopped compiling is the worse outcome.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Timeline;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TimelineEntry;
use OCA\OpenRegister\Service\NoteService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writing a timeline entry and everything that follows from it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Timeline
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class TimelineWriteService {

	/**
	 * Constructor.
	 *
	 * @param NoteService          $notes      Writes the comment that carries the text.
	 * @param TimelineEntryService $entries    Writes the record.
	 * @param ReferenceService     $references Records what the text points at.
	 * @param EntryMentionService  $mentions   Notifies and subscribes whoever was named.
	 * @param TextBlockService     $blocks     Inserts administered canned text.
	 * @param LoggerInterface      $logger     Logger for the derived steps that fail soft.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly NoteService $notes,
		private readonly TimelineEntryService $entries,
		private readonly ReferenceService $references,
		private readonly EntryMentionService $mentions,
		private readonly TextBlockService $blocks,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write one entry on one object.
	 *
	 * @param ObjectEntity        $object The object the entry hangs on.
	 * @param array<string,mixed> $data   message or textBlock, kind, fields, visibility, register, schema, rawSource, rawHeaders.
	 *
	 * @return TimelineEntry The entry.
	 *
	 * @throws TimelineValidationException When the kind, a field or the named text block does not fit.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function write(ObjectEntity $object, array $data): TimelineEntry {
		$message = $this->resolveMessage(object: $object, data: $data);

		$note = $this->notes->createNote(
			objectUuid: (string)$object->getUuid(),
			message: $message,
			visibility: $this->readString(data: $data, key: 'visibility')
		);

		$entry = $this->entries->record(
			object: $object,
			data: array_merge(
				$data,
				['message' => $message, 'commentId' => ($note['id'] ?? null)]
			)
		);

		$this->derive(object: $object, entry: $entry, message: $message, data: $data);

		return $entry;
	}//end write()

	/**
	 * Write the same note onto several related objects at once.
	 *
	 * One author, one text, an entry on each, each naming the others. The
	 * objects are passed already resolved, so an object the author may not
	 * write on never reaches this method: the caller refuses it with a 403
	 * naming which one, rather than silently writing the note on three of the
	 * four objects the author asked for.
	 *
	 * @param array<int, ObjectEntity> $objects The objects, the first being the one addressed.
	 * @param array<string,mixed>      $data    The entry payload.
	 *
	 * @return array<int, TimelineEntry> The entries, each naming the others.
	 *
	 * @throws TimelineValidationException When the kind, a field or the named text block does not fit.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function writeToMany(array $objects, array $data): array {
		$written = [];
		foreach ($objects as $object) {
			$written[] = $this->write(object: $object, data: $data);
		}

		return $this->entries->linkSiblings(entries: $written);
	}//end writeToMany()

	/**
	 * Keep a note's record, references and mentions in step after an edit.
	 *
	 * @param ObjectEntity $object     The object the note hangs on.
	 * @param integer      $commentId  The comment that was rewritten.
	 * @param string|null  $message    The new text, or null when it did not change.
	 * @param string|null  $visibility The new flag, or null when it did not change.
	 *
	 * @return TimelineEntry|null The record, or null when the note has none.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function rewrite(
		ObjectEntity $object,
		int $commentId,
		?string $message = null,
		?string $visibility = null,
	): ?TimelineEntry {
		$entry = $this->entries->syncFromNote(
			commentId: $commentId,
			message: $message,
			visibility: $visibility
		);

		if ($entry === null || $message === null) {
			return $entry;
		}

		// The references are rewritten from the NEW text, which is what makes
		// removing the code remove the reference. Mentions are not: somebody
		// already named and subscribed stays subscribed, because unsubscribing
		// them by deleting a word is a silent act on another person's inbox.
		$this->recordReferences(object: $object, entry: $entry, message: $message);

		return $entry;
	}//end rewrite()

	/**
	 * Project a note written through the notes endpoint into a record.
	 *
	 * The notes endpoint predates this change and keeps its own shape. This is
	 * the one line it gains, so a plain note is searchable like every other
	 * entry while behaving exactly as it did (task 1.3).
	 *
	 * Never throws: a note that was accepted must not be lost to the index.
	 *
	 * @param ObjectEntity        $object The object the note hangs on.
	 * @param array<string,mixed> $note   The note as the notes endpoint returned it.
	 * @param string|null         $register The register as the caller addressed it.
	 * @param string|null         $schema   The schema as the caller addressed it.
	 *
	 * @return TimelineEntry|null The record, or null when it could not be written.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function projectNote(
		ObjectEntity $object,
		array $note,
		?string $register = null,
		?string $schema = null,
	): ?TimelineEntry {
		$message = (string)($note['message'] ?? '');

		try {
			$entry = $this->entries->record(
				object: $object,
				data: [
					'message' => $message,
					'commentId' => ($note['id'] ?? null),
					'visibility' => ($note['visibility'] ?? null),
					'register' => $register,
					'schema' => $schema,
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'[TimelineWriteService] Note '.(string)($note['id'] ?? '?')
					.' was written but its record was not; it will not be searchable',
				['exception' => $e]
			);

			return null;
		}

		$this->derive(
			object: $object,
			entry: $entry,
			message: $message,
			data: ['register' => $register, 'schema' => $schema]
		);

		return $entry;
	}//end projectNote()

	/**
	 * Forget the record, and what it pointed at, behind a deleted note.
	 *
	 * @param integer     $commentId The deleted comment.
	 * @param string|null $entryUuid The record's id when the caller already read it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function forgetNote(int $commentId, ?string $entryUuid = null): void {
		if ($entryUuid !== null) {
			try {
				$this->references->forget(entryUuid: $entryUuid);
			} catch (Throwable $e) {
				$this->logger->warning(
					'[TimelineWriteService] References of entry '.$entryUuid.' could not be removed',
					['exception' => $e]
				);
			}
		}

		$this->entries->forgetNote(commentId: $commentId);
	}//end forgetNote()

	/**
	 * The things derived from an entry: what it points at, and whom it named.
	 *
	 * @param ObjectEntity        $object  The object.
	 * @param TimelineEntry       $entry   The entry just written.
	 * @param string              $message Its text.
	 * @param array<string,mixed> $data    The payload, for the register and schema labels.
	 *
	 * @return void
	 */
	private function derive(ObjectEntity $object, TimelineEntry $entry, string $message, array $data): void {
		$this->recordReferences(object: $object, entry: $entry, message: $message);

		try {
			$this->mentions->apply(
				object: $object,
				entryUuid: (string)$entry->getUuid(),
				text: $message,
				register: $this->readString(data: $data, key: 'register'),
				schema: $this->readString(data: $data, key: 'schema')
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[TimelineWriteService] Mentions in entry '.(string)$entry->getUuid().' could not be applied',
				['exception' => $e]
			);
		}
	}//end derive()

	/**
	 * Record what an entry's text points at, without risking the entry.
	 *
	 * @param ObjectEntity  $object  The object.
	 * @param TimelineEntry $entry   The entry.
	 * @param string        $message Its text.
	 *
	 * @return void
	 */
	private function recordReferences(ObjectEntity $object, TimelineEntry $entry, string $message): void {
		try {
			$this->references->record(
				entryUuid: (string)$entry->getUuid(),
				sourceUuid: (string)$object->getUuid(),
				text: $message
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[TimelineWriteService] References of entry '.(string)$entry->getUuid().' could not be recorded',
				['exception' => $e]
			);
		}
	}//end recordReferences()

	/**
	 * The text the entry carries: written, or inserted from a canned block.
	 *
	 * A payload may name a `textBlock` instead of a message, and then the
	 * block's text is substituted with the object's own values plus whatever
	 * the caller supplied. Naming both is not an error: the message wins and
	 * the block is ignored, because a caller that sent text meant the text.
	 *
	 * @param ObjectEntity        $object The object, whose values feed the substitution.
	 * @param array<string,mixed> $data   The payload.
	 *
	 * @return string The text.
	 *
	 * @throws TimelineValidationException When neither is usable.
	 */
	private function resolveMessage(ObjectEntity $object, array $data): string {
		$message = $this->readString(data: $data, key: 'message');
		if ($message !== null) {
			return $message;
		}

		$slug = $this->readString(data: $data, key: 'textBlock');
		if ($slug === null) {
			throw new TimelineValidationException(['message' => 'An entry needs a message or a text block']);
		}

		$variables = [];
		if (isset($data['variables']) === true && is_array($data['variables']) === true) {
			$variables = $data['variables'];
		}

		$objectValues = [];
		$objectData = $object->getObject();
		if (is_array($objectData) === true) {
			$objectValues = $objectData;
		}

		return $this->blocks->insert(
			slug: $slug,
			variables: array_merge(
				$objectValues,
				['objectUuid' => (string)$object->getUuid()],
				$variables
			)
		);
	}//end resolveMessage()

	/**
	 * Read one optional string off a payload.
	 *
	 * @param array<string,mixed> $data The payload.
	 * @param string              $key  The key.
	 *
	 * @return string|null The value, or null when absent, empty or not a string.
	 */
	private function readString(array $data, string $key): ?string {
		if (isset($data[$key]) === false || is_string($data[$key]) === false) {
			return null;
		}

		$value = trim($data[$key]);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end readString()
}//end class
