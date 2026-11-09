<?php

/**
 * ExternalInvitation: an e-mail guest invited into an object-linked Talk room.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TalkLink
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-talk/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TalkLink;

/**
 * Who is invited into which linked room.
 *
 * ADR-011 Rule 2 operation object for TalkLinkService::invite().
 *
 * @spec openspec/specs/integration-talk/spec.md
 */
final class ExternalInvitation {

	/**
	 * Describe it.
	 *
	 * @param string      $objectUuid  Parent OR object uuid.
	 * @param string      $roomToken   Talk room token.
	 * @param string      $email       The guest's e-mail address.
	 * @param string|null $displayName Optional display name; the e-mail is used when empty.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly string $roomToken,
		public readonly string $email,
		public readonly ?string $displayName = null,
	) {
	}//end __construct()
}//end class
