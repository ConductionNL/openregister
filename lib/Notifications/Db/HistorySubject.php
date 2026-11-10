<?php

/**
 * HistorySubject: what a history row is about, as a list axis.
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
 * What a notice is about when that is not the object it fired on.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class HistorySubject {

	/**
	 * Constructor.
	 *
	 * @param string $type What the notice is about, as a list axis.
	 * @param string $id   The subject's own id.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $id,
	) {
	}//end __construct()
}//end class
