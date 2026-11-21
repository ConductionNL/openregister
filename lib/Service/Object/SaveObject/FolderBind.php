<?php

/**
 * A folder a save asks to bind its object to through `@self.folder`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCP\IUser;

/**
 * The requested folder value, as the client sent it, and the user the access
 * check is asked for (null: the session user).
 *
 * Not strict: the value is cast from client input the same way the save did
 * before this object existed.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FolderBind {
	/**
	 * The bind.
	 *
	 * @param string     $folder     The requested folder value, uncast to an id.
	 * @param IUser|null $actingUser Explicit acting user; null falls back to the session user.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly string $folder,
		public readonly ?IUser $actingUser,
	) {
	}//end __construct()

	/**
	 * The bind `@self` asks for, or null when it names no folder.
	 *
	 * @param array      $selfData   The @self metadata.
	 * @param IUser|null $actingUser Explicit acting user; null falls back to the session user.
	 *
	 * @return self|null The bind, or null when `folder` is absent or empty.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public static function fromSelfData(array $selfData, ?IUser $actingUser): ?self {
		if (array_key_exists('folder', $selfData) === false || empty($selfData['folder']) === true) {
			return null;
		}

		return new self(folder: (string)$selfData['folder'], actingUser: $actingUser);
	}//end fromSelfData()
}//end class
