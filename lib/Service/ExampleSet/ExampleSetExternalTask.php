<?php

/**
 * A task an example set gives a party outside the instance: a resident's
 * portal task, assigned to `party:<reference>`, with the upload rules the
 * portal task seam reads from `metadata.upload`.
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
 * @spec openspec/changes/example-set-external-party-tasks/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-resident-a-portal-task
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Db\Task;

/**
 * Checks and shapes an example set's external task.
 *
 * @spec openspec/changes/example-set-external-party-tasks/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-resident-a-portal-task
 */
final class ExampleSetExternalTask {
	/**
	 * The upload rules an external task may carry.
	 */
	public const UPLOAD_RULES = ['required', 'maxFiles', 'maxSizeBytes', 'acceptedTypes'];

	/**
	 * Whether the task is addressed to a party outside the instance.
	 *
	 * @param array<string, mixed> $data The filled task fields.
	 *
	 * @return bool
	 */
	public static function isExternal(array $data): bool {
		return ($data['performerType'] ?? null) === Task::PERFORMER_EXTERNAL;
	}//end isExternal()

	/**
	 * An external task that is not addressed to a party, is marked as done by
	 * someone, or upload rules on a task that is not external or outside the
	 * rules the seam reads.
	 *
	 * @param array<string, mixed> $data The filled task fields.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-external-party-tasks/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-resident-a-portal-task
	 */
	public static function refusal(array $data): string {
		if (self::isExternal(data: $data) === false) {
			if (isset($data['uploadRules']) === true) {
				return 'uploadRules apply to an external task only (performerType: external)';
			}

			return '';
		}

		$assignee = (string) ($data['assignee'] ?? '');
		if (str_starts_with($assignee, Task::EXTERNAL_PARTY_PREFIX) === false || trim(substr($assignee, strlen(Task::EXTERNAL_PARTY_PREFIX))) === '') {
			return 'an external task is assigned to a party ("'.Task::EXTERNAL_PARTY_PREFIX.'<reference>"), not "'.$assignee.'"';
		}

		if (isset($data['completedBy']) === true) {
			return 'an external task is completed by its party in the portal, not by completedBy';
		}

		$rules = $data['uploadRules'] ?? [];
		if (is_array($rules) === false) {
			return 'uploadRules is an object';
		}

		$unknown = array_diff(array_keys($rules), self::UPLOAD_RULES);
		if ($unknown !== []) {
			return 'upload rule "'.implode('", "', $unknown).'" is not one the portal reads';
		}

		return '';
	}//end refusal()

	/**
	 * The task's upload rules as the engine's `metadata`, or nothing.
	 *
	 * @param array<string, mixed> $data The filled task fields.
	 *
	 * @return array{metadata?: array{upload: array<string, mixed>}}
	 *
	 * @spec openspec/changes/example-set-external-party-tasks/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-resident-a-portal-task
	 */
	public static function metadata(array $data): array {
		if (isset($data['uploadRules']) === false) {
			return [];
		}

		return ['metadata' => ['upload' => (array) $data['uploadRules']]];
	}//end metadata()
}//end class
