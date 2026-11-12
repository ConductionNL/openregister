<?php

/**
 * OpenRegister Email Message Reference
 *
 * A Nextcloud Mail message named by account, message id and optional uid.
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

/**
 * A Mail message as a link request names it.
 */
final class EmailMessageRef {

	/**
	 * Message uid; '' when not given.
	 *
	 * @var string
	 */
	private string $uid = '';

	/**
	 * Constructor.
	 *
	 * @param int    $mailAccountId Mail account id.
	 * @param string $messageId     Mail message id as sent by the caller.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly int $mailAccountId,
		public readonly string $messageId,
	) {
	}//end __construct()

	/**
	 * A copy with the given message uid.
	 *
	 * @param string $uid The uid; '' for none.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function withUid(string $uid): self {
		$copy      = clone $this;
		$copy->uid = $uid;
		return $copy;
	}//end withUid()

	/**
	 * The message id as an integer; 0 or less when it is not a valid id.
	 *
	 * @return int The message id.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function messageNumber(): int {
		return (int)$this->messageId;
	}//end messageNumber()

	/**
	 * The uid to match on, or null when none was given.
	 *
	 * @return string|null The uid.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function uidForMatch(): ?string {
		if ($this->uid === '') {
			return null;
		}

		return $this->uid;
	}//end uidForMatch()
}//end class
