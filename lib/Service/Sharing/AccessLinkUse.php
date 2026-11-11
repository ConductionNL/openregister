<?php

/**
 * One use of an access link, as it is recorded.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * What a link did, to what, from where, and anything else worth recording.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
 */
final readonly class AccessLinkUse {

	/**
	 * Describe one use.
	 *
	 * @param string $act The audit action, one of AccessLinkService's ACT_ constants.
	 * @param ObjectEntity|null $object The object touched, when the subject resolved to one.
	 * @param string|null $ipAddress The calling address.
	 * @param array<string, mixed> $context Anything else worth recording.
	 */
	public function __construct(
		public string $act,
		public ?ObjectEntity $object = null,
		public ?string $ipAddress = null,
		public array $context = [],
	) {
	}//end __construct()
}//end class
