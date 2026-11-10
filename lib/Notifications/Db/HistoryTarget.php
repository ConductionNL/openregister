<?php

/**
 * HistoryTarget: the object and firing a history row belongs to.
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
 * The object a notice is about, and the firing it came from.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class HistoryTarget {

	/**
	 * Constructor.
	 *
	 * @param ?string $schemaId   Schema id.
	 * @param ?string $registerId Register id.
	 * @param ?string $objectUuid Object uuid; also the notice's subject id.
	 * @param ?string $eventId    The firing, shared by every transport of one dispatch.
	 */
	public function __construct(
		public readonly ?string $schemaId = null,
		public readonly ?string $registerId = null,
		public readonly ?string $objectUuid = null,
		public readonly ?string $eventId = null,
	) {
	}//end __construct()
}//end class
