<?php

/**
 * OpenRegister Example Set User Object Rows
 *
 * What the rows an account leaves on one object share: a note, a read state and
 * a watcher each name a user the set creates or adopts and the object, and are
 * recorded by both.
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
use Throwable;

/**
 * The checks and values shared by rows of one account on one object. A class
 * using this declares FIELDS (the fields a set may write), FIELD_WORD (the row
 * in the unknown-field message) and NEEDS_OBJECT (the message for a missing
 * objectUuid).
 */
trait ExampleSetUserObjectRows {
	/**
	 * Why the fields, the user or the object of a row are wrong, or ''.
	 *
	 * @param array<string, mixed> $data  The filled fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 */
	protected function userObjectRefusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a '.self::FIELD_WORD.' field a set may write';
		}

		$user = (string) ($data['user'] ?? '');
		if (in_array($user, $users, true) === false) {
			return 'user "'.$user.'" is not an account the set creates or adopts';
		}

		if ($this->isBlank(value: ($data['objectUuid'] ?? null)) === true) {
			return self::NEEDS_OBJECT;
		}

		return '';
	}//end userObjectRefusal()

	/**
	 * Whether a value is not text, or only white space.
	 *
	 * @param mixed $value The filled value.
	 *
	 * @return bool
	 */
	protected function isBlank(mixed $value): bool {
		return is_string($value) === false || trim($value) === '';
	}//end isBlank()

	/**
	 * A date and time as written by `{{datetime:...}}`, or null.
	 *
	 * @param mixed $value The filled value.
	 *
	 * @return DateTime|null
	 */
	protected function moment(mixed $value): ?DateTime {
		if ($this->isBlank(value: $value) === true) {
			return null;
		}

		try {
			return new DateTime($value);
		} catch (Throwable) {
			return null;
		}
	}//end moment()

	/**
	 * A filled value as text, or null when it is not filled.
	 *
	 * @param mixed $value The filled value.
	 *
	 * @return string|null
	 */
	protected function textOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		return (string) $value;
	}//end textOrNull()

	/**
	 * Run a row's write and answer what write() answers: the record entry, or why the write failed.
	 *
	 * @param callable             $write Writes the row; throws when it fails.
	 * @param array<string, mixed> $data  The filled fields.
	 *
	 * @return array{error: string, entry: array{user: string, objectUuid: string}|null}
	 */
	protected function userObjectWrite(callable $write, array $data): array {
		try {
			$write();
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => $this->userObjectEntry(data: $data)];
	}//end userObjectWrite()

	/**
	 * Whether a row is gone: the lookup finds nothing, or fails.
	 *
	 * @param callable $find Looks the row up; null when it is not there.
	 *
	 * @return bool
	 */
	protected function userObjectLost(callable $find): bool {
		try {
			return $find() === null;
		} catch (Throwable) {
			return true;
		}
	}//end userObjectLost()

	/**
	 * Take a row away: `removed` (also when it was already gone) or `failed`.
	 *
	 * @param callable $remove Removes the row; throws when it fails.
	 *
	 * @return string
	 */
	protected function userObjectRevert(callable $remove): string {
		try {
			$remove();
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end userObjectRevert()

	/**
	 * The record entry of a row: its user and its object.
	 *
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return array{user: string, objectUuid: string}
	 */
	protected function userObjectEntry(array $data): array {
		return ['user' => (string) $data['user'], 'objectUuid' => (string) $data['objectUuid']];
	}//end userObjectEntry()
}//end trait
