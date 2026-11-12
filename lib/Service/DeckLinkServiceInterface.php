<?php

/**
 * DeckLinkServiceInterface: the Deck link operations of DeckLinkService that controllers use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\DeckLink;

/**
 * The methods of DeckLinkService that callers outside the Service component use.
 */
interface DeckLinkServiceInterface {

	/**
	 * Whether NC Deck is installed + enabled for the current user.
	 *
	 * @return bool
	 */
	public function isDeckAvailable(): bool;

	/**
	 * Link an existing Deck card to an OR object.
	 *
	 * The link row carries board_id/stack_id/title/dueDate/labels/assignees
	 * harvested from the Deck card so subsequent reads don't need to hit
	 * Deck. Idempotent: a duplicate link raises a 409 Exception (callers
	 * should surface as HTTP 409 to the UI).
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param int $cardId Deck card id.
	 *
	 * @return DeckLink The persisted link row.
	 *
	 * @throws Exception On missing user, missing card (404), duplicate (409).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function linkCard(LinkTarget $target, int $cardId): DeckLink;

	/**
	 * Unlink a Deck card from an object.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $cardId Deck card id.
	 *
	 * @return void
	 *
	 * @throws Exception When no matching link is found (404).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function unlinkCard(string $objectUuid, int $cardId): void;

	/**
	 * Return the linked cards for an object, enriched from Deck where
	 * available.
	 *
	 * Always succeeds: when Deck's `CardService` is missing or `find()`
	 * throws for a stale link, the stored row is returned with empty
	 * `labels`/`assignees` and `dueDate` falling back to the cached column.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getLinkedCards(string $objectUuid): array;

	/**
	 * Create a new Deck card and link it to an object in one transaction.
	 *
	 * Uses Deck's CardService for the create (with the owner-arg fix
	 * from commit 3cdbfe71a) then persists the link row carrying the
	 * new card's id + cached title + due date.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param CardDraft  $card   Title, list (stack), board (used when Deck cannot
	 *                           resolve the card's board after create), and
	 *                           optional description and ISO 8601 due date.
	 *
	 * @return DeckLink The persisted link row.
	 *
	 * @throws Exception On missing user, Deck unavailable, or create failure.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function createAndLinkCard(LinkTarget $target, CardDraft $card): DeckLink;

	/**
	 * Return the Deck boards visible to the current user.
	 *
	 * Each row is `{id, title}` — the minimum the picker needs.
	 *
	 * @return array<int,array{id:int,title:string}>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getAvailableBoards(): array;

	/**
	 * Return the stacks for a Deck board.
	 *
	 * @param int $boardId Deck board id.
	 *
	 * @return array<int,array{id:int,title:string,boardId:int}>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getStacksForBoard(int $boardId): array;
}//end interface
