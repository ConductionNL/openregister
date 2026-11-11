<?php

/**
 * PollLinkServiceInterface: the part of PollLinkService that its controller and the relations aggregation use.
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
use OCA\OpenRegister\Db\PollLink;

/**
 * The methods of PollLinkService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface PollLinkServiceInterface {

	/**
	 * Whether NC Polls is installed + enabled for the current user.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function isPollsAvailable(): bool;

	/**
	 * Link an existing Polls poll to an OR object.
	 *
	 * Idempotent: a duplicate link raises a 409 Exception (callers
	 * should surface as HTTP 409 to the UI).
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param int $pollId Polls poll id.
	 *
	 * @return PollLink The persisted link row.
	 *
	 * @throws Exception On missing user, missing poll (404), duplicate (409).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function linkPoll(LinkTarget $target, int $pollId): PollLink;

	/**
	 * Unlink a poll from an object.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $pollId Polls poll id.
	 *
	 * @return void
	 *
	 * @throws Exception When no matching link is found (404).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function unlinkPoll(string $objectUuid, int $pollId): void;

	/**
	 * Return the linked polls for an object, with the tally counts and
	 * `closed` flag refreshed from `oc_polls_polls` on every call.
	 *
	 * Session-workaround pattern (Phase B-3 commit 73b49f3ad): Polls'
	 * own PollService can't run inside an OR controller because Polls'
	 * UserSession isn't populated, so we read directly from
	 * `oc_polls_polls` / `oc_polls_votes`. When Polls is unavailable
	 * the cached link-row values are returned unchanged.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getLinkedPolls(string $objectUuid): array;

	/**
	 * Return polls visible to the current user (picker source).
	 *
	 * Filters out polls already deleted in Polls. Optional substring
	 * search against the poll title.
	 *
	 * @param string|null $search Optional title-substring filter.
	 *
	 * @return array<int,array{id:int,title:string,type:string,deadline:?string,closed:bool,voterCount:int,optionCount:int}>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getAvailablePolls(?string $search = null): array;

	/**
	 * Create a new Polls poll and link it to an OR object in one operation.
	 *
	 * Direct INSERT into `oc_polls_polls` + `oc_polls_options` (session
	 * workaround, see class docblock). The new poll is owned by the
	 * current user and uses the default access setting.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param PollDraft  $draft  Title (required), description, type, options and deadline.
	 *
	 * @return PollLink The persisted link row.
	 *
	 * @throws Exception On missing user, Polls unavailable, or create failure.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function createAndLinkPoll(LinkTarget $target, PollDraft $draft): PollLink;
}//end interface
