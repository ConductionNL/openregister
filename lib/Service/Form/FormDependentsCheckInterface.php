<?php

/**
 * FormDependentsCheckInterface: what callers outside lib/Service use of FormDependentsCheck.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Kernel\Contracts\Core\SchemaFormDependentsInterface;

/**
 * The part of FormDependentsCheck that code outside lib/Service uses.
 */
interface FormDependentsCheckInterface extends SchemaFormDependentsInterface {

	/**
	 * Whether the caller of this schema's save judges the dependent forms itself.
	 *
	 * @param int $schemaId The schema.
	 *
	 * @return bool True when it was claimed in this request.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
	 */
	public function isClaimed(int $schemaId): bool;
}//end interface
