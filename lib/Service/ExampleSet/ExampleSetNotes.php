<?php

/**
 * OpenRegister Example Set Notes
 *
 * The notes an example set's accounts left on its objects, such as what a
 * handler wrote on a case this afternoon. A note is a Nextcloud comment on the
 * object (object type `openregister`, the one the notes endpoints read), not a
 * register row, so the set writes it through the comments manager with its
 * author and moment and records the comment id. The author is one of the set's
 * own accounts. Remove deletes the notes the record holds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-leave-notes-of-the-sets-own-accounts-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, writes, proves and deletes the notes of an example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-leave-notes-of-the-sets-own-accounts-on-its-objects
 */
class ExampleSetNotes extends ExampleSetEngineRows {
	use ExampleSetUserObjectRows;

	/**
	 * The note fields a fragment may set.
	 */
	public const FIELDS = ['user', 'objectUuid', 'message', 'createdAt'];

	/**
	 * The row in the unknown-field message.
	 */
	private const FIELD_WORD = 'note';

	/**
	 * The refusal for a row without its object.
	 */
	private const NEEDS_OBJECT = 'a note needs the object it is on (objectUuid)';

	/**
	 * The comment object type OpenRegister's notes live under.
	 */
	private const OBJECT_TYPE = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the comments manager on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment and record part notes live in.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-leave-notes-of-the-sets-own-accounts-on-its-objects
	 */
	public function part(): string {
		return 'notes';
	}//end part()

	/**
	 * The word for one note, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-leave-notes-of-the-sets-own-accounts-on-its-objects
	 */
	public function noun(): string {
		return 'note';
	}//end noun()

	/**
	 * Why a filled note cannot be written, or ''.
	 *
	 * @param array<string, mixed> $data  The filled fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-leave-notes-of-the-sets-own-accounts-on-its-objects
	 */
	public function refusal(array $data, array $users): string {
		$refusal = $this->userObjectRefusal(data: $data, users: $users);
		if ($refusal !== '') {
			return $refusal;
		}

		if ($this->isBlank(value: ($data['message'] ?? null)) === true) {
			return 'a note needs a message';
		}

		if (isset($data['createdAt']) === true && ExampleSetDateValue::moment(value: $data['createdAt']) === null) {
			return 'createdAt is a date and time';
		}

		return '';
	}//end refusal()

	/**
	 * Leave the note on the object, as the account, at `createdAt` or now.
	 *
	 * @param ExampleSetRow $row The row: its app, its key in the fragment and its filled fields.
	 *
	 * @return array{error: string, entry: array{id: string}|null} The record entry, or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-leave-notes-of-the-sets-own-accounts-on-its-objects
	 */
	public function write(ExampleSetRow $row): array {
		$data = $row->data;
		try {
			$comments = $this->comments();
			$comment  = $this->newComment(comments: $comments, data: $data);
			$comments->save($comment);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['id' => (string) $comment->getId()]];
	}//end write()

	/**
	 * The note as a comment of the account on the object, with its message and, when given, its moment.
	 *
	 * @param ICommentsManager     $comments The comments manager.
	 * @param array<string, mixed> $data     The filled fields.
	 *
	 * @return IComment
	 */
	private function newComment(ICommentsManager $comments, array $data): IComment {
		$comment = $comments->create('users', (string) $data['user'], self::OBJECT_TYPE, (string) $data['objectUuid']);
		$comment->setMessage((string) $data['message']);
		$comment->setVerb('comment');
		$moment = ExampleSetDateValue::moment(value: ($data['createdAt'] ?? null));
		if ($moment !== null) {
			$comment->setCreationDateTime($moment);
		}

		return $comment;
	}//end newComment()

	/**
	 * Whether a written note is no longer there.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-leave-notes-of-the-sets-own-accounts-on-its-objects
	 */
	public function lost(array $entry): bool {
		try {
			$this->comments()->get((string) $entry['id']);
		} catch (Throwable) {
			return true;
		}

		return false;
	}//end lost()

	/**
	 * Delete a note the set wrote.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` (also when it was already gone) or `failed`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-takes-away-the-notes-and-read-states-a-set-wrote
	 */
	public function revert(array $entry): string {
		try {
			$this->comments()->delete((string) $entry['id']);
		} catch (NotFoundException) {
			return 'removed';
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * The comments manager.
	 *
	 * @return ICommentsManager
	 */
	private function comments(): ICommentsManager {
		return $this->container->get(ICommentsManager::class);
	}//end comments()
}//end class
