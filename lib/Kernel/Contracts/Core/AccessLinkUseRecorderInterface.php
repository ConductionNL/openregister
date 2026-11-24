<?php

/**
 * Puts what an access link did to an object on the audit trail.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Service\Sharing\AccessLinkUse;

/**
 * Records one use of an access link on the audit trail, with the link as the actor.
 *
 * Core asks; the audit trail (Compliance) writes the entry and binds its recorder outside Core.
 * A failed write is thrown to the caller, which decides whether the use still stands.
 */
interface AccessLinkUseRecorderInterface {

	/**
	 * Write the entry for one use; a use without an object writes nothing.
	 *
	 * @param AccessLink    $link The link that acted.
	 * @param AccessLinkUse $use  What it did, to which object, from which address, with what context.
	 *
	 * @return void
	 *
	 * @throws \Throwable When the trail cannot store the entry.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function recordLinkUse(AccessLink $link, AccessLinkUse $use): void;
}//end interface
