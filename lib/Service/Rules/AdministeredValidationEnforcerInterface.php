<?php

/**
 * AdministeredValidationEnforcerInterface: what callers outside the component use of AdministeredValidationEnforcer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
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

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Run a schema's administered validations over a write and answer whether it is refused.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface AdministeredValidationEnforcerInterface {

	/**
	 * Run the schema's validations over a write, and say whether it is refused.
	 *
	 * @param ObjectEntity      $newObject The object as it would be saved.
	 * @param ObjectEntity|null $oldObject The object as stored, null on a create.
	 *
	 * @return array<string, mixed>|null The refusal to put on the event, or null when the write may proceed.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function refusalFor(ObjectEntity $newObject, ?ObjectEntity $oldObject): ?array;
}//end interface
