<?php

/**
 * A request to derive one record's archival nomination again.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The record, its schema (holding the archive block), and who asked and why.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */
final class NominationRecompute {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The record.
	 * @param Schema       $schema Its schema, holding the archive block.
	 * @param string|null  $actor  Who asked.
	 * @param string|null  $reason Why they asked.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly Schema $schema,
		public readonly ?string $actor = null,
		public readonly ?string $reason = null,
	) {
	}//end __construct()
}//end class
