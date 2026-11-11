<?php

/**
 * OpenRegister DeckRowMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\IUserManager;
use Throwable;

/**
 * Maps Deck Label and Assignment entities to the leaf-row shapes. A getter that
 * fails leaves its field at the default; Deck entities are read duck-typed
 * because the Deck app is optional.
 *
 * @spec openspec/specs/deck-integration/spec.md
 */
final class DeckRowMapper {
	/**
	 * @param IUserManager $userManager Resolves display names of user assignees.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * The leaf-row shape of a Deck Label.
	 *
	 * @param object $label Deck Label.
	 *
	 * @return array{id: ?int, title: string, color: string}
	 */
	public function label(object $label): array {
		return [
			'id' => $this->read(getter: fn () => $this->nullableInt(value: $label->getId()), fallback: null),
			'title' => $this->read(getter: fn () => (string)$label->getTitle(), fallback: ''),
			'color' => $this->read(getter: fn () => (string)$label->getColor(), fallback: ''),
		];
	}//end label()

	/**
	 * The leaf-row shape of a Deck Assignment: the participant (user uid, group
	 * name or circle id), its type, and a display name for user assignees.
	 *
	 * @param object $assignment Deck Assignment.
	 *
	 * @return array{uid: string, type: string, displayName: string}
	 */
	public function assignee(object $assignment): array {
		$uid = $this->read(getter: fn () => (string)$assignment->getParticipant(), fallback: '');
		$type = $this->read(getter: fn () => (string)$assignment->getTypeString(), fallback: 'user');
		$displayName = $uid;
		if ($type === 'user' && $uid !== '') {
			$displayName = $this->read(getter: fn () => $this->userDisplayName(uid: $uid), fallback: $uid);
		}

		return ['uid' => $uid, 'type' => $type, 'displayName' => $displayName];
	}//end assignee()

	/**
	 * The display name of a user, or the uid when there is no such user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return string
	 */
	private function userDisplayName(string $uid): string {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return $uid;
		}

		return (string)$user->getDisplayName();
	}//end userDisplayName()

	/**
	 * The value as an int, null staying null.
	 *
	 * @param mixed $value The raw id.
	 *
	 * @return int|null
	 */
	private function nullableInt(mixed $value): ?int {
		if ($value === null) {
			return null;
		}

		return (int)$value;
	}//end nullableInt()

	/**
	 * The getter's value, or the fallback when it throws.
	 *
	 * @param callable $getter   Reads one field.
	 * @param mixed    $fallback The value when the read fails.
	 *
	 * @return mixed
	 */
	private function read(callable $getter, mixed $fallback): mixed {
		try {
			return $getter();
		} catch (Throwable $e) {
			return $fallback;
		}
	}//end read()
}//end class
