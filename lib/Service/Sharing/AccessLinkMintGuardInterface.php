<?php

/**
 * AccessLinkMintGuardInterface: what callers outside the component use of AccessLinkMintGuard.
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

/**
 * The minting check the access link endpoints run before minting.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface AccessLinkMintGuardInterface {

	/**
	 * Whether the calling principal may publish this subject at a link.
	 *
	 * The flags are `true` on purpose and are the point of the method: the
	 * subject is fetched exactly as the caller would fetch it themselves, so a
	 * record they cannot read is a record they cannot publish.
	 *
	 * @param string $subjectType `object`, `view` or `file`.
	 * @param string $subjectId The subject the caller wants to publish.
	 *
	 * @return bool True when the caller may mint a link over it.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
	 */
	public function mayMint(string $subjectType, string $subjectId): bool;
}//end interface
