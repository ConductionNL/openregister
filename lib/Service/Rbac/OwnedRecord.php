<?php

/**
 * OpenRegister OwnedRecord.
 *
 * A record about to change hands, with its register and schema, its owner
 * before the transfer, and how many generations below the transferred
 * record it sits.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * A record in an ownership transfer.
 *
 * @spec openspec/specs/object-ownership/spec.md
 */
final class OwnedRecord {
	/**
	 * The record's owner when it entered the transfer.
	 *
	 * @var string|null
	 */
	public readonly ?string $previousOwner;

	/**
	 * Constructor.
	 *
	 * @param Register $register The record's register.
	 * @param Schema $schema The record's schema.
	 * @param ObjectEntity $object The record.
	 * @param int $depth How many generations below the transferred record it sits.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly ObjectEntity $object,
		public readonly int $depth = 0,
	) {
		$this->previousOwner = $object->getOwner();

	}//end __construct()
}//end class
