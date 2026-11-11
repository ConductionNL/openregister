<?php

/**
 * ObjectActionValidatorInterface: what callers outside the Service layer use of ObjectActionValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\Schema;

/**
 * Refuses a schema whose declared actions cannot be saved.
 */
interface ObjectActionValidatorInterface {

	/**
	 * Every refusal the declared actions of this schema earn.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return list<string> The refusals; empty when every action may be saved.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
	 */
	public function refusals(Schema $schema): array;
}//end interface
