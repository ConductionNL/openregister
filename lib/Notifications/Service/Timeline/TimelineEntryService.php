<?php

/**
 * TimelineEntryService: the timeline entry as a record.
 *
 * This service owns the entry record: writing it beside the note that carries
 * the text, validating the fields its kind declares, moving the follow-up from
 * open to done, pinning it, keeping the raw inbound source, and recording the
 * language it arrived in.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It does not resolve the object. Every
 * public method takes the {@see ObjectEntity} the caller already resolved,
 * because the object read is where RBAC, multitenancy and the published
 * predicate are enforced, and a service that looks its own object up invites a
 * caller to skip that. It does not write the comment either: a note is still a
 * Nextcloud comment and {@see \OCA\OpenRegister\Files\Service\NoteService} still owns
 * it.
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

namespace OCA\OpenRegister\Notifications\Service\Timeline;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Db\TimelineEntry;
use OCA\OpenRegister\Notifications\Db\TimelineEntryMapper;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * The timeline entry record.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Timeline
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One method per act on the
 * record: write it, sync it, read it, list it, pin it, close its follow-up,
 * read its source, tie its siblings, forget it. They share the visibility
 * guard and the mapper, which is what keeps them one class.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class TimelineEntryService {
	use OptionalPayloadString;

	/**
	 * Constructor.
	 *
	 * @param TimelineEntryRecords      $records     The record store, kind registry and language detector.
	 * @param TimelineVisibilityService $visibility  Normalises the flag and decides who may set it.
	 * @param IUserSession              $userSession The caller.
	 * @param LoggerInterface           $logger      Logger for the fail-open paths.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TimelineEntryRecords $records,
		private readonly TimelineVisibilityService $visibility,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the record for an entry.
	 *
	 * Called by the note path right after the comment is saved, and by any
	 * inbound source that produces an entry without one. `kind` may be absent,
	 * and then the entry is a plain note with no fields and no follow-up:
	 * everything this record adds is opt-in, which is what keeps a timeline
	 * written before this change readable exactly as it was.
	 *
	 * @param ObjectEntity        $object The object the entry hangs on.
	 * @param array<string,mixed> $data   message, kind, fields, visibility, commentId, rawSource, rawHeaders, siblings.
	 *
	 * @return TimelineEntry The stored record.
	 *
	 * @throws TimelineValidationException When the kind is undeclared or a field does not fit.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function record(ObjectEntity $object, array $data): TimelineEntry {
		$kind = $this->stringOrNull(data: $data, key: 'kind');
		$accepted = $this->records->kinds->validateFields(kindSlug: $kind, fields: $this->arrayOrEmpty(data: $data, key: 'fields'));

		$entry = $this->newEntry(object: $object, data: $data);
		$entry->setKind($kind);
		$entry->setFields($accepted);
		$this->writeText(entry: $entry, data: $data);
		$this->writeInbound(entry: $entry, data: $data);

		if ($this->records->kinds->carriesFollowUp(kindSlug: $kind) === true) {
			$entry->setFollowUp(TimelineEntry::FOLLOW_UP_OPEN);
		}

		return $this->records->entryMapper->insert($entry);
	}//end record()

	/**
	 * A record with its identity, place and author, not yet stored.
	 *
	 * @param ObjectEntity        $object The object the entry hangs on.
	 * @param array<string,mixed> $data   The payload.
	 *
	 * @return TimelineEntry The new record.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4() is the standard utility pattern in this app
	 */
	private function newEntry(ObjectEntity $object, array $data): TimelineEntry {
		$entry = new TimelineEntry();
		$entry->setUuid((string)Uuid::v4());
		$entry->setObjectUuid((string)$object->getUuid());
		$entry->setRegister($this->stringOrNull(data: $data, key: 'register'));
		$entry->setSchema($this->stringOrNull(data: $data, key: 'schema'));
		$entry->setCommentId($this->readInt(data: $data, key: 'commentId'));
		$entry->setAuthor($this->callerUid());
		$entry->setPinned(false);
		$entry->setCreated(new DateTime());
		$entry->setUpdated(new DateTime());

		return $entry;
	}//end newEntry()

	/**
	 * Write the text, its language and the visibility flag onto a record.
	 *
	 * @param TimelineEntry       $entry The record.
	 * @param array<string,mixed> $data  The payload.
	 *
	 * @return void
	 */
	private function writeText(TimelineEntry $entry, array $data): void {
		$message = (string)($data['message'] ?? '');

		$entry->setVisibility($this->visibility->normalise(value: $this->stringOrNull(data: $data, key: 'visibility')));
		$entry->setMessage($message);
		$entry->setLanguage($this->records->languages->detect(text: $message));
	}//end writeText()

	/**
	 * Write the raw inbound source and the siblings onto a record.
	 *
	 * @param TimelineEntry       $entry The record.
	 * @param array<string,mixed> $data  The payload.
	 *
	 * @return void
	 */
	private function writeInbound(TimelineEntry $entry, array $data): void {
		$entry->setRawSource($this->stringOrNull(data: $data, key: 'rawSource'));

		if (is_array($data['rawHeaders'] ?? null) === true) {
			$entry->setRawHeaders($data['rawHeaders']);
		}

		if (is_array($data['siblings'] ?? null) === true) {
			$entry->setSiblings(array_values($data['siblings']));
		}
	}//end writeInbound()

	/**
	 * Keep the record in step with the note that was just rewritten.
	 *
	 * The comment stays the authority for the text; the record carries the
	 * copy the index reads. They are rewritten in the same call so they cannot
	 * drift: an index that answers with yesterday's sentence is worse than no
	 * index, because it looks like a hit.
	 *
	 * A comment with no record is not an error. Notes written before this
	 * change have none, and inventing one here would date them wrong.
	 *
	 * @param NoteRewrite $rewrite What changed.
	 *
	 * @return TimelineEntry|null The rewritten record, or null when the comment has none.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function syncFromNote(NoteRewrite $rewrite): ?TimelineEntry {
		$entry = $this->records->entryMapper->findByComment(commentId: $rewrite->commentId);
		if ($entry === null) {
			return null;
		}

		if ($rewrite->message !== null) {
			$entry->setMessage($rewrite->message);
			$entry->setLanguage($this->records->languages->detect(text: $rewrite->message));
		}

		if ($rewrite->visibility !== null) {
			$entry->setVisibility($this->visibility->normalise(value: $rewrite->visibility));
		}

		$entry->setUpdated(new DateTime());

		return $this->records->entryMapper->update($entry);
	}//end syncFromNote()

	/**
	 * One object's timeline, pinned first.
	 *
	 * @param ObjectEntity $object The object.
	 * @param ListQuery    $query  The page (limit and offset) and the `visibility` filter: the effective
	 *                             filter, or absent for the unfiltered view.
	 *
	 * @return array<int, TimelineEntry> The entries.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function listForObject(ObjectEntity $object, ListQuery $query = new ListQuery(limit: 50)): array {
		return $this->records->entryMapper->findForObject(
			objectUuid: (string)$object->getUuid(),
			visibility: ($query->filters['visibility'] ?? null),
			limit: $query->limit,
			offset: ($query->offset ?? 0)
		);
	}//end listForObject()

	/**
	 * One entry by its stable id, scoped to the object it must hang on.
	 *
	 * The object is passed and checked rather than trusted from the entry: an
	 * entry id addressed through another object's url would otherwise read
	 * that entry under the other object's access.
	 *
	 * @param ObjectEntity $object    The object the caller addressed.
	 * @param string       $entryUuid The entry id.
	 *
	 * @return TimelineEntry|null The entry, or null when it is not on this object.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function get(ObjectEntity $object, string $entryUuid): ?TimelineEntry {
		$entry = $this->records->entryMapper->findByUuid(uuid: $entryUuid);
		if ($entry === null || $entry->getObjectUuid() !== (string)$object->getUuid()) {
			return null;
		}

		return $entry;
	}//end get()

	/**
	 * The record behind one note, when there is one.
	 *
	 * Read by the notes endpoint BEFORE it deletes a note, because after the
	 * delete there is nothing left to look the entry up by and its references
	 * would be orphaned.
	 *
	 * @param integer $commentId The comment.
	 *
	 * @return TimelineEntry|null The record, or null for a note written before this change.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function entryForNote(int $commentId): ?TimelineEntry {
		return $this->records->entryMapper->findByComment(commentId: $commentId);
	}//end entryForNote()

	/**
	 * Pin an entry, or take the pin off.
	 *
	 * The pin is on the record, not per reader (D-4): the colleague who needs
	 * the four entries out of three hundred is not the person who knows which
	 * four. Setting it is therefore an act on the object, authorised the same
	 * way moving a note across the counter is.
	 *
	 * @param ObjectEntity $object The object the entry hangs on.
	 * @param EntryPin     $pin    The entry and the pin asked for.
	 *
	 * @return TimelineEntry The written record.
	 *
	 * @throws TimelinePermissionException When the caller may not pin on this object.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function pin(ObjectEntity $object, EntryPin $pin): TimelineEntry {
		if ($this->visibility->mayManage(object: $object) === false) {
			throw new TimelinePermissionException('You do not have permission to pin entries on this object');
		}

		$entry = $pin->entry;
		$entry->setPinned($pin->pinned);
		$entry->setPinnedBy(null);
		$entry->setPinnedAt(null);
		if ($pin->pinned === true) {
			$entry->setPinnedBy($this->callerUid());
			$entry->setPinnedAt(new DateTime());
		}

		$entry->setUpdated(new DateTime());

		return $this->records->entryMapper->update($entry);
	}//end pin()

	/**
	 * Close a follow-up, naming who closed it and when.
	 *
	 * A callback that has not happened is a property of the request, not of
	 * the case (D-3). An entry whose kind declares no follow-up has nothing to
	 * close, and saying so is better than silently writing a state nothing
	 * will ever read.
	 *
	 * @param ObjectEntity  $entryObject The object the entry hangs on.
	 * @param TimelineEntry $entry       The entry.
	 *
	 * @return TimelineEntry The written record.
	 *
	 * @throws TimelinePermissionException When the caller may not close follow-ups here.
	 * @throws TimelineValidationException When the entry carries no follow-up.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function closeFollowUp(ObjectEntity $entryObject, TimelineEntry $entry): TimelineEntry {
		if ($this->visibility->mayManage(object: $entryObject) === false) {
			throw new TimelinePermissionException('You do not have permission to close follow-ups on this object');
		}

		if ($entry->getFollowUp() === null) {
			throw new TimelineValidationException(['followUp' => 'This entry carries no follow-up']);
		}

		$entry->setFollowUp(TimelineEntry::FOLLOW_UP_DONE);
		$entry->setClosedBy($this->callerUid());
		$entry->setClosedAt(new DateTime());
		$entry->setUpdated(new DateTime());

		return $this->records->entryMapper->update($entry);
	}//end closeFollowUp()

	/**
	 * The raw inbound message and its headers.
	 *
	 * A rendered message cannot prove when it arrived; the headers can (D-5).
	 * The access this needs is the access to the ENTRY, which the caller has
	 * already established by resolving the object and by the entry belonging
	 * to it: there is no second, weaker door onto the source.
	 *
	 * @param TimelineEntry $entry The entry.
	 *
	 * @return array{source: string|null, headers: array<string,mixed>} The stored source.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function sourceFor(TimelineEntry $entry): array {
		return [
			'source' => $entry->getRawSource(),
			'headers' => ($entry->getRawHeaders() ?? []),
		];
	}//end sourceFor()

	/**
	 * Tie a set of entries together as siblings of one multi-object note.
	 *
	 * One author, one text, an entry on each object, each naming the others.
	 * The siblings are written after all the entries exist, because an entry
	 * cannot name an id that has not been minted yet.
	 *
	 * @param array<int, TimelineEntry> $entries The entries one note produced.
	 *
	 * @return array<int, TimelineEntry> The entries, each carrying the others.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function linkSiblings(array $entries): array {
		if (count($entries) < 2) {
			return $entries;
		}

		$written = [];
		foreach ($entries as $entry) {
			$entry->setSiblings($this->siblingsOf(entry: $entry, entries: $entries));
			$entry->setUpdated(new DateTime());
			$written[] = $this->records->entryMapper->update($entry);
		}

		return $written;
	}//end linkSiblings()

	/**
	 * What one entry says about the other entries of its note.
	 *
	 * @param TimelineEntry             $entry   The entry.
	 * @param array<int, TimelineEntry> $entries The entries the note produced.
	 *
	 * @return array<int, array<string, string|null>> One reference per other entry.
	 */
	private function siblingsOf(TimelineEntry $entry, array $entries): array {
		$siblings = [];
		foreach ($entries as $other) {
			if ($other->getUuid() !== $entry->getUuid()) {
				$siblings[] = [
					'entry' => $other->getUuid(),
					'objectUuid' => $other->getObjectUuid(),
					'register' => $other->getRegister(),
					'schema' => $other->getSchema(),
				];
			}
		}

		return $siblings;
	}//end siblingsOf()

	/**
	 * Forget the record behind a deleted note.
	 *
	 * Never throws: the note is already gone by the time this runs, and losing
	 * the delete to a failing index write would leave a note nobody can remove.
	 * A failure is logged loudly instead.
	 *
	 * @param integer $commentId The deleted comment.
	 *
	 * @return integer The number of records removed.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function forgetNote(int $commentId): int {
		try {
			return $this->records->entryMapper->deleteForComment(commentId: $commentId);
		} catch (Throwable $e) {
			$this->logger->error(
				'[TimelineEntryService] The record for note '.$commentId.' could not be removed',
				['exception' => $e]
			);

			return 0;
		}
	}//end forgetNote()

	/**
	 * Forget every record on an object being emptied.
	 *
	 * @param string $objectUuid The object.
	 *
	 * @return integer The number of records removed.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function forgetObject(string $objectUuid): int {
		try {
			return $this->records->entryMapper->deleteForObject(objectUuid: $objectUuid);
		} catch (Throwable $e) {
			$this->logger->error(
				'[TimelineEntryService] The records on object '.$objectUuid.' could not be removed',
				['exception' => $e]
			);

			return 0;
		}
	}//end forgetObject()

	/**
	 * The caller's uid, or null for a system write.
	 *
	 * @return string|null The uid.
	 */
	private function callerUid(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end callerUid()

	/**
	 * Read one optional integer off a payload.
	 *
	 * @param array<string,mixed> $data The payload.
	 * @param string              $key  The key.
	 *
	 * @return integer|null The value, or null when absent or not numeric.
	 */
	private function readInt(array $data, string $key): ?int {
		if (isset($data[$key]) === false || is_numeric($data[$key]) === false) {
			return null;
		}

		return (int)$data[$key];
	}//end readInt()
}//end class
