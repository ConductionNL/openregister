<?php

/**
 * One request to mint an access link: who mints it, over which subject, on which terms.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

/**
 * The minting principal, the subject and the terms of one link, as asked.
 *
 * The minting principal is a field rather than something the service reaches
 * for, so the identity behind a published link is decided at the HTTP
 * boundary and is visible there. AccessLinkService::mint() refuses unusable
 * values; this object only carries them.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
 */
final class LinkMintRequest {
	/**
	 * The request as asked.
	 *
	 * @param string          $minter      The principal minting the link.
	 * @param string          $subjectType `object`, `view` or `file`.
	 * @param string          $subjectId   The object uuid, view uuid, or file id.
	 * @param AccessLinkTerms $terms       The capabilities, the expiry (required), the password and the label.
	 */
	public function __construct(
		public readonly string $minter,
		public readonly string $subjectType,
		public readonly string $subjectId,
		public readonly AccessLinkTerms $terms,
	) {
	}//end __construct()
}//end class
