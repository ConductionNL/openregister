<?php

/**
 * HistoryNotice: what a history row says.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Notifications\Db
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

namespace OCA\OpenRegister\Notifications\Db;

/**
 * The rendered subject, the recipient locale and why delivery failed, if it did.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class HistoryNotice {

	/**
	 * Constructor.
	 *
	 * @param ?string $subject      Interpolated subject.
	 * @param ?string $errorMessage Error message when status is `failed`.
	 * @param ?string $locale       Recipient locale (null for broadcast).
	 */
	public function __construct(
		public readonly ?string $subject = null,
		public readonly ?string $errorMessage = null,
		public readonly ?string $locale = null,
	) {
	}//end __construct()
}//end class
