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

use DateTime;
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
	/**
	 * The note fields a fragment may set.
	 */
	public const FIELDS = ['user', 'objectUuid', 'message', 'createdAt'];

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
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a note field a set may write';
		}

		$user = (string) ($data['user'] ?? '');
		if (in_array($user, $users, true) === false) {
			return 'user "'.$user.'" is not an account the set creates or adopts';
		}

		if (is_string($data['objectUuid'] ?? null) === false || trim($data['objectUuid']) === '') {
			return 'a note needs the object it is on (objectUuid)';
		}

		if (is_string($data['message'] ?? null) === false || trim($data['message']) === '') {
			return 'a note needs a message';
		}

		if (isset($data['createdAt']) === true && $this->moment(value: $data['createdAt']) === null) {
			return 'createdAt is a date and time';
		}

		return '';
	}//end refusal()

	/**
	 * Leave the note on the object, as the account, at `createdAt` or now.
	 *
	 * @param string               $app  The app that ships the fragment (unused: a note names its author).
	 * @param string               $key  The note's key in the fragment (unused).
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return array{error: string, entry: array{id: string}|null} The record entry, or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-leave-notes-of-the-sets-own-accounts-on-its-objects
	 */
	public function write(string $app, string $key, array $data): array {
		try {
			$comments = $this->comments();
			$comment  = $comments->create('users', (string) $data['user'], self::OBJECT_TYPE, (string) $data['objectUuid']);
			$comment->setMessage((string) $data['message']);
			$comment->setVerb('comment');
			$moment = $this->moment(value: ($data['createdAt'] ?? null));
			if ($moment !== null) {
				$comment->setCreationDateTime($moment);
			}

			$comments->save($comment);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['id' => (string) $comment->getId()]];
	}//end write()

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
	 * A date and time as written by `{{datetime:...}}`, or null.
	 *
	 * @param mixed $value The filled value.
	 *
	 * @return DateTime|null
	 */
	private function moment(mixed $value): ?DateTime {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTime($value);
		} catch (Throwable) {
			return null;
		}
	}//end moment()

	/**
	 * The comments manager.
	 *
	 * @return ICommentsManager
	 */
	private function comments(): ICommentsManager {
		return $this->container->get(ICommentsManager::class);
	}//end comments()
}//end class
