<?php

/**
 * The dates an example set may give a task: due, expiry, completion, claim
 * and creation. Each must be a date, an expiry may not come before the due
 * date, and a task is neither claimed nor completed before it was created.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-task-its-requester-and-expiry
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragments-task-may-carry-the-moment-it-was-created
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;

/**
 * Checks an example set task's dates.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-task-its-requester-and-expiry
 */
final class ExampleSetTaskDates {
	/**
	 * A due, expiry, completion or claim date that is not a date, or an expiry before the due date.
	 *
	 * @param array<string, mixed> $data The filled task fields.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-task-its-requester-and-expiry
	 */
	public static function refusal(array $data): string {
		$refusal = self::formatRefusal(data: $data);
		if ($refusal === '') {
			$refusal = self::expiryRefusal(data: $data);
		}

		if ($refusal === '') {
			$refusal = self::createdAtRefusal(data: $data);
		}

		return $refusal;
	}//end refusal()

	/**
	 * A due, expiry, completion, claim or creation date that is set but is not a date.
	 *
	 * @param array<string, mixed> $data The filled task fields.
	 *
	 * @return string
	 */
	private static function formatRefusal(array $data): string {
		foreach (['dueAt', 'expiresAt', 'completedAt', 'claimedAt', 'createdAt'] as $field) {
			if (isset($data[$field]) === true && self::moment(value: $data[$field]) === null) {
				return $field.' "'.(string) $data[$field].'" is not a date';
			}
		}

		return '';
	}//end formatRefusal()

	/**
	 * An expiry before the due date.
	 *
	 * @param array<string, mixed> $data The filled task fields, their dates already checked.
	 *
	 * @return string
	 */
	private static function expiryRefusal(array $data): string {
		if (isset($data['dueAt'], $data['expiresAt']) === true
			&& self::moment(value: $data['expiresAt']) < self::moment(value: $data['dueAt'])
		) {
			return 'expiresAt lies before dueAt: a task that dies before it is due is a configuration error';
		}

		return '';
	}//end expiryRefusal()

	/**
	 * A creation moment in the future, or a claim or completion before it.
	 *
	 * @param array<string, mixed> $data The filled task fields, their dates already checked.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragments-task-may-carry-the-moment-it-was-created
	 */
	private static function createdAtRefusal(array $data): string {
		$created = self::moment(value: ($data['createdAt'] ?? null));
		if ($created === null) {
			return '';
		}

		if ($created > new DateTime()) {
			return 'createdAt "'.(string) $data['createdAt'].'" lies in the future';
		}

		return self::beforeCreatedRefusal(data: $data, created: $created);
	}//end createdAtRefusal()

	/**
	 * A claim or completion before the task was created.
	 *
	 * @param array<string, mixed> $data    The filled task fields, their dates already checked.
	 * @param DateTime             $created The moment the task was created.
	 *
	 * @return string
	 */
	private static function beforeCreatedRefusal(array $data, DateTime $created): string {
		foreach (['claimedAt', 'completedAt'] as $field) {
			$moment = self::moment(value: ($data[$field] ?? null));
			if ($moment !== null && $moment < $created) {
				return $field.' lies before createdAt: a task is not '.substr($field, 0, -2).' before it exists';
			}
		}

		return '';
	}//end beforeCreatedRefusal()

	/**
	 * A date or date-time value as a moment, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTime|null
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-task-its-requester-and-expiry
	 */
	public static function moment(mixed $value): ?DateTime {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		$moment = date_create($value);
		if ($moment === false) {
			return null;
		}

		return $moment;
	}//end moment()
}//end class
