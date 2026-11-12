<?php

/**
 * EmailLinkRequest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

/**
 * A request to link one Mail message to one object.
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */
final class EmailLinkRequest {

	/**
	 * Constructor.
	 *
	 * @param string $objectUuid    The object UUID.
	 * @param int    $registerId    The register ID.
	 * @param int    $mailAccountId The mail account ID.
	 * @param int    $mailMessageId The mail message ID.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly int $registerId,
		public readonly int $mailAccountId,
		public readonly int $mailMessageId,
	) {
	}//end __construct()
}//end class
