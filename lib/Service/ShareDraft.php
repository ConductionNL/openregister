<?php

/**
 * OpenRegister Share Draft
 *
 * A share of an object's file that is about to be created.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCP\Share\IShare;
use Throwable;

/**
 * The file, type, recipient and protection of a share to create.
 */
final class ShareDraft {

	/**
	 * Recipient (user, group or email); null for none.
	 *
	 * @var string|null
	 */
	private ?string $shareWith = null;

	/**
	 * Permission bits.
	 *
	 * @var integer
	 */
	private int $permissions = 0;

	/**
	 * Password; null for none.
	 *
	 * @var string|null
	 */
	private ?string $password = null;

	/**
	 * Expiration date; null for none.
	 *
	 * @var string|null
	 */
	private ?string $expiration = null;

	/**
	 * Constructor.
	 *
	 * @param int $fileId    Id of the file in the object's folder.
	 * @param int $shareType One of the IShare::TYPE_* constants.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly int $fileId,
		public readonly int $shareType,
	) {
	}//end __construct()

	/**
	 * A copy with the given recipient and permissions.
	 *
	 * @param string|null $shareWith   Recipient; null for link shares.
	 * @param int         $permissions Permission bits.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function withRecipient(?string $shareWith, int $permissions): self {
		$copy              = clone $this;
		$copy->shareWith   = $shareWith;
		$copy->permissions = $permissions;
		return $copy;
	}//end withRecipient()

	/**
	 * A copy with the given password and expiration date.
	 *
	 * @param string|null $password   Password; null or blank for none.
	 * @param string|null $expiration Expiration date; null or blank for none.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function withProtection(?string $password, ?string $expiration): self {
		$copy             = clone $this;
		$copy->password   = $password;
		$copy->expiration = $expiration;
		return $copy;
	}//end withProtection()

	/**
	 * Permission bits.
	 *
	 * @return int The permissions.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function permissions(): int {
		return $this->permissions;
	}//end permissions()

	/**
	 * The trimmed recipient, or null when none or blank.
	 *
	 * @return string|null The recipient.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function recipient(): ?string {
		if ($this->shareWith === null || trim($this->shareWith) === '') {
			return null;
		}

		return trim($this->shareWith);
	}//end recipient()

	/**
	 * Whether the share type needs a recipient (user, group, email) and none is given.
	 *
	 * @return bool True when the recipient is missing.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function lacksRecipient(): bool {
		$needsOne = in_array(
			$this->shareType,
			[IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_EMAIL],
			true
		);
		return $needsOne === true && $this->recipient() === null;
	}//end lacksRecipient()

	/**
	 * The password, or null when none or blank.
	 *
	 * @return string|null The password, untrimmed.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function password(): ?string {
		if ($this->password === null || trim($this->password) === '') {
			return null;
		}

		return $this->password;
	}//end password()

	/**
	 * The expiration date, or null when none or blank.
	 *
	 * @return DateTime|null The date.
	 *
	 * @throws Exception When the date cannot be parsed (400).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function expiresAt(): ?DateTime {
		if ($this->expiration === null || trim($this->expiration) === '') {
			return null;
		}

		try {
			return new DateTime($this->expiration);
		} catch (Throwable $e) {
			throw new Exception('Invalid expiration date', 400);
		}
	}//end expiresAt()
}//end class
