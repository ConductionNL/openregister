<?php

/**
 * DeckCardServiceInterface: the Deck card operations of DeckCardService that controllers use.
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
 * The methods of DeckCardService that callers outside the Service component use.
 */
interface DeckCardServiceInterface {

	/**
	 * Check if the Nextcloud Deck app is installed and enabled.
	 *
	 * @return bool True if Deck is available.
	 */
	public function isDeckAvailable(): bool;

	/**
	 * Get all deck links for an object.
	 *
	 * Each row is the canonical {@see DeckLink::jsonSerialize()} payload
	 * enriched with `dueDate`, `labels`, and `assignees` resolved from
	 * the underlying Deck card via Deck's CardService:
	 *   * `dueDate`   — ISO 8601 datetime string ("2026-06-01T12:00:00+00:00")
	 *                   or `null` when the card has no due date.
	 *   * `labels`    — array of `{id, title, color}` (color preserved as
	 *                   Deck stores it — hex without the leading `#`).
	 *   * `assignees` — array of `{uid, type, displayName}`. The Deck
	 *                   Assignment entity exposes `getParticipant()`
	 *                   (a uid for type=USER) and `getType()` (user /
	 *                   group / circle). The provider surfaces type so
	 *                   the UI can render a distinct chip when an
	 *                   assignment is to a group/circle.
	 *
	 * Enrichment is best-effort: if Deck's CardService isn't available
	 * (Deck disabled at runtime) or `find()` throws for an individual
	 * card (deleted from Deck but link row stale), the widened fields
	 * fall back to `null` / `[]` and the link is still surfaced.
	 *
	 * Idempotent: re-running the enrichment doesn't double-write — the
	 * widened keys are computed fresh each call.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return array{results: array, total: int}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-8
	 */
	public function getCardsForObject(string $objectUuid): array;

	/**
	 * Create a new Deck card linked to an object, or link an existing card.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $registerId The register ID.
	 * @param array $data Card data: boardId, stackId, title, description, or cardId for existing.
	 *
	 * @return DeckLink The created link.
	 *
	 * @throws Exception If parameters are missing or Deck operations fail.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-8
	 */
	public function linkOrCreateCard(string $objectUuid, int $registerId, array $data): DeckLink;

	/**
	 * Find all objects linked to cards on a board.
	 *
	 * @param int $boardId The Deck board ID.
	 *
	 * @return array Array of deck links.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-8
	 */
	public function getObjectsForBoard(int $boardId): array;
}//end interface
