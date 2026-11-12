<?php

/**
 * The optional password and expiry of a link-style share (a public link or an e-mail invitation).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTime;
use InvalidArgumentException;
use OCP\Share\IShare;
use Throwable;

/**
 * What protects a link-style share: an optional password and an optional expiry.
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
final class LinkProtection {
	/**
	 * Hold the protection.
	 *
	 * @param string|null $password   Optional password.
	 * @param string|null $expiration Optional expiry, any format DateTime parses.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function __construct(
		public readonly ?string $password,
		public readonly ?string $expiration,
	) {
	}//end __construct()

	/**
	 * Apply the password and expiry to a share being built.
	 *
	 * @param IShare $share The share being built.
	 *
	 * @throws InvalidArgumentException When the expiry cannot be parsed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function applyTo(IShare $share): void {
		if (self::isGiven(value: $this->password) === true) {
			$share->setPassword($this->password);
		}

		if (self::isGiven(value: $this->expiration) === false) {
			return;
		}

		try {
			$share->setExpirationDate(new DateTime($this->expiration));
		} catch (Throwable $e) {
			throw new InvalidArgumentException('Could not read that expiry date');
		}
	}//end applyTo()

	/**
	 * Whether a value was given: not null and not blank.
	 *
	 * @param string|null $value The value.
	 *
	 * @return bool
	 */
	private static function isGiven(?string $value): bool {
		return ($value !== null && trim($value) !== '');
	}//end isGiven()
}//end class
