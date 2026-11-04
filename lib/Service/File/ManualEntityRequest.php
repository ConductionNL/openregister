<?php

/**
 * OpenRegister ManualEntityRequest.
 *
 * Immutable input of one manual-entity add: the target file, the
 * operator-supplied value and type, the match options and the actor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCP\IUser;

/**
 * Input of `ManualEntityService::addManualEntity`.
 */
final class ManualEntityRequest {
	/**
	 * Constructor.
	 *
	 * @param int $fileId Nextcloud file id the manual entity applies to.
	 * @param string $value Operator-supplied text (PII, audit-only).
	 * @param string $type Entity type tag (e.g. `PERSON`).
	 * @param bool $wholeWord Match whole words only.
	 * @param bool $caseSensitive Match case-sensitively.
	 * @param IUser $actor The acting user.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public readonly int $fileId,
		public readonly string $value,
		public readonly string $type,
		public readonly bool $wholeWord,
		public readonly bool $caseSensitive,
		public readonly IUser $actor,
	) {

	}//end __construct()
}//end class
