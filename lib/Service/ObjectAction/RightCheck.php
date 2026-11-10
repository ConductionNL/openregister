<?php

/**
 * A right asked on a schema, for one object or for a selection.
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
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The right the gate is asked about: the schema, the right (an action key or
 * a CRUD verb) and the object, null when asked at schema level.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */
final readonly class RightCheck {

	/**
	 * Describe the check.
	 *
	 * @param Schema            $schema The object's schema.
	 * @param string            $right  The action key, or a CRUD verb.
	 * @param ObjectEntity|null $object The object; null asks at schema level.
	 */
	public function __construct(
		public Schema $schema,
		public string $right,
		public ?ObjectEntity $object,
	) {
	}//end __construct()
}//end class
