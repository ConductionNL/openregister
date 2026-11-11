<?php

/**
 * AccessLinkServiceInterface: what callers outside the component use of AccessLinkService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AccessLink;

/**
 * The access link operations the access link endpoints call.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface AccessLinkServiceInterface {

	/**
	 * The audit action written when a link serves its subject.
	 *
	 * @var string
	 */
	public const ACT_READ = 'accesslink.read';

	/**
	 * The audit action written when a link leaves a comment.
	 *
	 * @var string
	 */
	public const ACT_COMMENT = 'accesslink.comment';

	/**
	 * The audit action written when a link adds a file.
	 *
	 * @var string
	 */
	public const ACT_UPLOAD = 'accesslink.upload';

	/**
	 * Every link one principal minted, with the URL each one opens.
	 *
	 * @param string $userId The minting principal.
	 *
	 * @return array<int, array<string, mixed>> The serialised links.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function listForUser(string $userId): array;

	/**
	 * Mint a link over one subject.
	 *
	 * The minting principal travels in the request rather than being something
	 * this service reaches for, so the identity behind a published link is
	 * decided at the HTTP boundary and is visible there.
	 *
	 * @param LinkMintRequest $request The minting principal, the subject and the terms.
	 *
	 * @return array<string, mixed> The link row plus the URL a holder opens.
	 *
	 * @throws InvalidArgumentException When the subject, the capabilities or the expiry are unusable.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
	 */
	public function mint(LinkMintRequest $request): array;

	/**
	 * Whether the password presented opens this link.
	 *
	 * A link with no password opens without one. A link with a password never
	 * opens without it, and never on a wrong one.
	 *
	 * @param AccessLink $link The link.
	 * @param string|null $password The password presented, when one was.
	 *
	 * @return bool True when the holder may proceed.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
	 */
	public function passwordAccepted(AccessLink $link, ?string $password): bool;

	/**
	 * Record one use of a link on the audit trail, and note it on the row.
	 *
	 * The actor is the link, never a user, so four uses of one link are four
	 * entries naming that link. The calling address rides along because a
	 * publication that turns out to have been wrong is reconstructed from
	 * where it was opened as much as from when.
	 *
	 * The row's own counters are a convenience and are updated after the audit
	 * entry: a failure to bump a counter must never lose the entry.
	 *
	 * @param AccessLink $link The link that acted.
	 * @param AccessLinkUse $use What it did, to which object, from which address, with what context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function recordUse(AccessLink $link, AccessLinkUse $use): void;

	/**
	 * Resolve an anchor to a link that may still answer, or null.
	 *
	 * Unknown, revoked, switched off and expired all return null, so the
	 * caller's single 404 cannot be used to tell them apart. A holder who knows
	 * the anchor learns nothing from the answer that they did not already hold.
	 *
	 * @param string $anchor The anchor from the URL.
	 *
	 * @return AccessLink|null The live link, or null.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function resolve(string $anchor): ?AccessLink;

	/**
	 * Revoke a link the calling principal minted.
	 *
	 * Revocation takes effect on the next use, because every use reads the row.
	 * A link somebody else minted is, to this caller, a link that does not
	 * exist.
	 *
	 * @param int $id The link row id.
	 * @param string $userId The principal asking.
	 *
	 * @return bool True when a link was revoked, false when there was none to revoke.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function revoke(int $id, string $userId): bool;

	/**
	 * Switch a link off without revoking it; a switched-off link answers the same 404 as a revoked one.
	 *
	 * @param int    $id     The link row id.
	 * @param string $userId The principal asking.
	 *
	 * @return array<string, mixed>|null The updated link, or null when there is none.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function disable(int $id, string $userId): ?array;

	/**
	 * Switch a link the caller minted back on.
	 *
	 * @param int    $id     The link row id.
	 * @param string $userId The principal asking.
	 *
	 * @return array<string, mixed>|null The updated link, or null when there is none.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function enable(int $id, string $userId): ?array;
}//end interface
