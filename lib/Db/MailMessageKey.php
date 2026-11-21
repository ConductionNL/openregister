<?php

/**
 * MailMessageKey
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Which mail message an email link points at: the account, the message id and
 * the message UID, the three message columns of the link table's unique key
 * (ADR-011 Rule 2).
 *
 * @spec exclude ADR-011 parameter object of EmailLinkMapper, not a capability of its own
 */
final class MailMessageKey {

	/**
	 * Constructor
	 *
	 * @param int         $mailAccountId  The mail account id.
	 * @param int         $mailMessageId  The mail message id.
	 * @param string|null $mailMessageUid The mail message UID, or null when it has none.
	 */
	public function __construct(
		public readonly int $mailAccountId,
		public readonly int $mailMessageId,
		public readonly ?string $mailMessageUid,
	) {
	}//end __construct()
}//end class
