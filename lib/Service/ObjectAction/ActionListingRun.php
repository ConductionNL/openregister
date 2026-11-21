<?php

/**
 * One listing of the actions on an object.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-the-actions-a-user-may-run-on-an-object-are-listed-in-one-place
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The object whose actions ObjectActionList lists, its schema and the
 * lifecycle moves available on it now. Never leaves ObjectActionList.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-the-actions-a-user-may-run-on-an-object-are-listed-in-one-place
 */
final readonly class ActionListingRun {

	/**
	 * Describe the listing.
	 *
	 * @param ObjectEntity                        $object    The object.
	 * @param Schema                              $schema    Its schema.
	 * @param array<string, array<string, mixed>> $available The lifecycle's available moves, by action.
	 */
	public function __construct(
		public ObjectEntity $object,
		public Schema $schema,
		public array $available,
	) {
	}//end __construct()
}//end class
