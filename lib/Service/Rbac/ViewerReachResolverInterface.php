<?php

/**
 * ViewerReachResolverInterface: the part of ViewerReachResolver that callers outside Service use.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Exception\ViewAlertRecipientException;

/**
 * The methods of ViewerReachResolver that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface ViewerReachResolverInterface {

	/**
	 * The signed-in caller's uid, or an empty string when nobody is signed in.
	 *
	 * An empty string rather than null because every call site turned null
	 * into exactly that, and eight copies of the same conversion is eight
	 * places for one of them to convert it differently.
	 *
	 * @return string The uid, or ''.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function currentUid(): string;

	/**
	 * How far one caller reaches: their groups, and whether they administer.
	 *
	 * An unreadable membership is NOT an authorization. It answers no groups
	 * and no administration, so the caller sees their own views and the public
	 * ones and nothing else, which is the fail-closed direction.
	 *
	 * @param string $userId The caller.
	 *
	 * @return ViewerReach The caller's reach.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function reachOf(string $userId): ViewerReach;

	/**
	 * Findings for a share list about to be written, against the users and groups that exist.
	 *
	 * @param mixed $sharedWith The declared share list.
	 *
	 * @return array<int, array{code: string, message: string}> The findings; empty when the list may be stored.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function shareFindings(mixed $sharedWith): array;

	/**
	 * Whether a view reaches this caller at all: owned, shared with one of their groups, public, or an administrator.
	 *
	 * @param array<string, mixed> $view  The serialised view.
	 * @param ViewerReach          $reach The caller's reach.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function reaches(array $view, ViewerReach $reach): bool;

	/**
	 * The fields of an update this caller may NOT make to one view.
	 *
	 * The three questions the endpoint used to ask separately, answered
	 * together: what the view grants this caller, whether they may administer
	 * it, and which of the fields they sent that combination refuses. Asking
	 * them one at a time from the controller left the third free to be called
	 * with the wrong answer to the first two.
	 *
	 * @param array<string, mixed> $view   The serialised view.
	 * @param ViewerReach          $reach  The caller's reach.
	 * @param array<string, mixed> $update The fields the caller sent; only those whose value changes are judged.
	 *
	 * @return array<int, string> The refused field names, empty when the update may proceed.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function refusedFields(array $view, ViewerReach $reach, array $update): array;

	/**
	 * Refuse a save whose alert names recipients this caller may not add.
	 *
	 * The view is judged as it will be saved: the stored view (or, on create,
	 * just its owner) with the body's `isPublic` and `sharedWith` laid over it;
	 * a caller replacing the whole view (PUT) lays over the `isPublic` the save
	 * will write. The stored alert's recipients are not judged as additions, but
	 * one the save itself stops reaching is refused.
	 *
	 * @param array<string, mixed> $view  The stored view, or `['owner' => <uid>]` on create.
	 * @param ViewerReach          $reach The caller's reach.
	 * @param array<string, mixed> $data  The request body.
	 *
	 * @return void
	 *
	 * @throws ViewAlertRecipientException When a recipient may not be added.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function assertMayNameRecipients(array $view, ViewerReach $reach, array $data): void;

	/**
	 * Whether a recipient, a `user:<uid>` or a group id, may read the view.
	 *
	 * @param array<string, mixed> $view      The view.
	 * @param string               $recipient The recipient.
	 *
	 * @return bool True when the view reaches every person the recipient names.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function recipientReads(array $view, string $recipient): bool;
}//end interface
