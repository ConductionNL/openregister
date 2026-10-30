<?php

/**
 * What is wrong with a share list about to be written on a saved view.
 *
 * A share names a group or a user, never both, in `read` or `write` mode. A
 * target that does not exist is refused rather than stored: a share on a name
 * nobody holds looks, in the grid, exactly like a share somebody has, and the
 * view is then narrower than its own screen says.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-10-30-saved-view-user-shares/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Validates a `sharedWith` list of `{group, mode}` and `{user, mode}` entries.
 *
 * @spec openspec/changes/archive/2026-10-30-saved-view-user-shares/tasks.md#task-2
 */
class ViewShareListValidator {

	/**
	 * The findings for a whole list.
	 *
	 * @param mixed                   $sharedWith The declared share list.
	 * @param array<string, callable> $exists     Per kind (`group`, `user`), whether an id exists.
	 *
	 * @return array<int, array{code: string, message: string}> The findings; empty when the list may be stored.
	 *
	 * @spec openspec/changes/archive/2026-10-30-saved-view-user-shares/tasks.md#task-2
	 */
	public function findings(mixed $sharedWith, array $exists): array {
		if ($sharedWith === null || $sharedWith === []) {
			return [];
		}

		if (is_array($sharedWith) === false) {
			return [['code' => 'share.not-a-list', 'message' => 'sharedWith must be a list of shares.']];
		}

		// `$seen` carries across the list: two shares with one target is an
		// authoring mistake whose winner depends on storage order.
		$findings = [];
		$seen = [];
		foreach ($sharedWith as $index => $share) {
			$findings = array_merge(
				$findings,
				$this->shareFindings(share: $share, index: (string)$index, exists: $exists, seen: $seen)
			);
		}

		return $findings;
	}//end findings()

	/**
	 * The findings for one share.
	 *
	 * @param mixed                   $share  The declared share.
	 * @param string                  $index  Which share it is.
	 * @param array<string, callable> $exists Per kind, whether an id exists.
	 * @param array<string, true>     $seen   Targets already shared with, updated in place.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function shareFindings(mixed $share, string $index, array $exists, array &$seen): array {
		$target = $this->targetOf(share: $share, index: $index);
		if (isset($target['finding']) === true) {
			return [$target['finding']];
		}

		[$kind, $id] = [$target['kind'], $target['id']];
		$mode = trim((string)($share['mode'] ?? ''));

		$findings = [];
		if (in_array($mode, ViewShareResolver::MODES, true) === false) {
			$findings[] = [
				'code' => 'share.bad-mode',
				'message' => 'Share with "'.$id.'" must be read or write, not "'.$mode.'".',
			];
		}

		if (isset($seen[$kind.':'.$id]) === true) {
			$findings[] = [
				'code' => 'share.duplicate-'.$kind,
				'message' => 'The '.$kind.' "'.$id.'" is shared with twice.',
			];
		}

		$seen[$kind.':'.$id] = true;

		if ($exists[$kind]($id) !== true) {
			$findings[] = [
				'code' => 'share.unknown-'.$kind,
				'message' => 'The '.$kind.' "'.$id.'" does not exist.',
			];
		}

		return $findings;
	}//end shareFindings()

	/**
	 * Who one share names, or the finding that says it names nobody usable.
	 *
	 * @param mixed  $share The declared share.
	 * @param string $index Which share it is.
	 *
	 * @return array{kind?: string, id?: string, finding?: array{code: string, message: string}}
	 */
	private function targetOf(mixed $share, string $index): array {
		if (is_array($share) === false) {
			return ['finding' => ['code' => 'share.not-an-object', 'message' => 'Share '.$index.' is not an object.']];
		}

		$group = trim((string)($share['group'] ?? ''));
		$user = trim((string)($share['user'] ?? ''));

		if ($group !== '' && $user !== '') {
			return ['finding' => ['code' => 'share.user-and-group', 'message' => 'Share '.$index.' names both a user and a group.']];
		}

		if ($user !== '') {
			return ['kind' => 'user', 'id' => $user];
		}

		if ($group !== '') {
			return ['kind' => 'group', 'id' => $group];
		}

		return ['finding' => ['code' => 'share.no-group', 'message' => 'Share '.$index.' names no user or group.']];
	}//end targetOf()
}//end class
