<?php

/**
 * OpenRegister Mailbox Page
 *
 * One page of messages in a Nextcloud Mail mailbox, as the picker asks for it.
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
 * A mailbox of a Mail account, a cursor into it and a page size.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class MailboxPage {

	/**
	 * Message id to start after; null or '' for the first page.
	 *
	 * @var string|null
	 */
	private ?string $afterCursor = null;

	/**
	 * Page size.
	 *
	 * @var integer
	 */
	private int $limit = 1;

	/**
	 * Constructor.
	 *
	 * @param int    $accountId Mail account id.
	 * @param string $mailbox   Mailbox name.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly int $accountId,
		public readonly string $mailbox,
	) {
	}//end __construct()

	/**
	 * A copy that starts after the given cursor and holds the given page size.
	 *
	 * @param string|null $afterCursor Message id to start after; null for the first page.
	 * @param int         $limit       Page size.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function startingAfter(?string $afterCursor, int $limit): self {
		$copy = clone $this;
		$copy->afterCursor = $afterCursor;
		$copy->limit = $limit;
		return $copy;
	}//end startingAfter()

	/**
	 * A copy whose page size lies between 1 and the given maximum.
	 *
	 * @param int $max The largest page size allowed.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function withLimitAtMost(int $max): self {
		return $this->startingAfter(afterCursor: $this->afterCursor, limit: max(1, min($this->limit, $max)));
	}//end withLimitAtMost()

	/**
	 * The message id to start after; 0 for the first page.
	 *
	 * @return int The cursor id.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function cursorId(): int {
		if ($this->afterCursor === null || $this->afterCursor === '') {
			return 0;
		}

		return (int)$this->afterCursor;
	}//end cursorId()

	/**
	 * The page size.
	 *
	 * @return int The page size.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function limit(): int {
		return $this->limit;
	}//end limit()

	/**
	 * Rows to fetch: one more than the page size, to tell whether a next page exists.
	 *
	 * @return int The fetch size.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function fetchSize(): int {
		return $this->limit + 1;
	}//end fetchSize()
}//end class
