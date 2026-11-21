<?php

/**
 * OpenRegister HistoryRedactionRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\Schema;

/**
 * One redaction of an object's history for one reader.
 *
 * The state HistoryEntryRedactor's helpers share: the schema whose rules
 * apply, the properties withheld from this reader, and the explicit-reveal
 * properties shown masked. It never leaves HistoryEntryRedactor.
 */
final class HistoryRedactionRun {

	/**
	 * Constructor.
	 *
	 * @param Schema             $schema The object's schema.
	 * @param array<int, string> $hidden Withheld property names.
	 * @param array<int, string> $masked Explicit-reveal property names, whose `@self` copies are withheld too.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly array $hidden,
		public readonly array $masked,
	) {
	}//end __construct()

	/**
	 * Every property whose `@self` copies are withheld: the hidden and the masked ones.
	 *
	 * @return array<int, string> The property names.
	 */
	public function withheld(): array {
		return array_merge($this->hidden, $this->masked);
	}//end withheld()
}//end class
