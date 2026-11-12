<?php

/**
 * A request to list the row verbs a caller holds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The schema, the row when the listing is about one, and the caller whose verbs are listed.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class VerbListing {

	/**
	 * Constructor for VerbListing.
	 *
	 * @param Schema            $schema The schema the row belongs to.
	 * @param ObjectEntity|null $object The row, when the question is about one.
	 * @param string|null       $userId The caller, or null to resolve from the session.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly ?ObjectEntity $object = null,
		public readonly ?string $userId = null,
	) {
	}//end __construct()
}//end class
