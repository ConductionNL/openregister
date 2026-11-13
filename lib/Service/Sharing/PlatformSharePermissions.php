<?php

/**
 * PlatformSharePermissions — the platform's permission bits, read as object verbs.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-share-provider/specs/platform-share-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

use InvalidArgumentException;
use OCP\Constants;

/**
 * Maps each permission bit of a platform share onto an object verb, or refuses it.
 *
 * Silently ignoring a bit is how a share ends up granting less or more than
 * the person who made it believes (design D-2), so every bit set is either
 * mapped or refused, and a refusal names the bit.
 *
 * Read and update map onto the object verbs of the same name. The rest are
 * refused:
 *
 *   - create: an object is one record, there is nothing inside it to create.
 *   - delete: deleting a record is not something a share hands out; it stays
 *     with the owner and the schema's own rules.
 *   - share: the object share model strips the re-share bit on every grant
 *     (a recipient never re-shares onward), so accepting it here would be
 *     exactly the silent narrowing this mapping exists to prevent.
 *   - any bit the platform does not define.
 *
 * @spec openspec/changes/platform-share-provider/specs/platform-share-provider/spec.md
 */
final class PlatformSharePermissions {

	/**
	 * The bits that map, and the object verb each becomes.
	 *
	 * @var array<int, string>
	 */
	public const MAPPED = [
		Constants::PERMISSION_READ => 'read',
		Constants::PERMISSION_UPDATE => 'update',
	];

	/**
	 * The platform bits that mean nothing for an object, by name.
	 *
	 * @var array<int, string>
	 */
	public const REFUSED = [
		Constants::PERMISSION_CREATE => 'create',
		Constants::PERMISSION_DELETE => 'delete',
		Constants::PERMISSION_SHARE => 'share',
	];

	/**
	 * The object verbs a permission mask grants.
	 *
	 * @param int $permissions The platform permission bitmask.
	 *
	 * @throws InvalidArgumentException When the mask is empty, or sets a bit that maps to no object verb (naming it).
	 *
	 * @return string[] The verbs, in bit order.
	 *
	 * @spec openspec/changes/platform-share-provider/specs/platform-share-provider/spec.md
	 */
	public function verbsFor(int $permissions): array {
		if ($permissions <= 0) {
			throw new InvalidArgumentException('A share must grant at least one permission');
		}

		$verbs = [];
		for ($bit = 1; $bit <= $permissions && $bit > 0; $bit <<= 1) {
			if (($permissions & $bit) === 0) {
				continue;
			}

			if (isset(self::MAPPED[$bit]) === false) {
				throw new InvalidArgumentException($this->refusal(bit: $bit));
			}

			$verbs[] = self::MAPPED[$bit];
		}

		return $verbs;
	}//end verbsFor()

	/**
	 * The refusal message for one bit, naming it.
	 *
	 * @param int $bit The single bit refused.
	 *
	 * @return string The message.
	 */
	private function refusal(int $bit): string {
		if (isset(self::REFUSED[$bit]) === true) {
			return sprintf('Permission bit %d (%s) maps to no object verb', $bit, self::REFUSED[$bit]);
		}

		return sprintf('Permission bit %d is not a platform permission and maps to no object verb', $bit);
	}//end refusal()
}//end class
