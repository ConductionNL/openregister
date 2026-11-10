<?php

/**
 * OpenRegister ReadableObject
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * An object the read check let through, with the schema its history is redacted under.
 *
 * Built by ObjectHistoryForReaders after the read check and the doelbinding
 * guard; HistoryEntryRedactor applies the schema's property rules to it.
 */
final class ReadableObject {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object, as the object GET's read path returned it.
	 * @param Schema       $schema The object's schema.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly Schema $schema,
	) {
	}//end __construct()
}//end class
