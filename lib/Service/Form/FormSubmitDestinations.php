<?php

/**
 * OpenRegister Form Submit Destinations
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\ValidateObjectInterface;

/**
 * Where a submit lands: the destination's register and schema, its validator and the object write path.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
final readonly class FormSubmitDestinations {

	/**
	 * Bundle the collaborators.
	 *
	 * @param RegisterMapperInterface $registers Resolves a destination's register.
	 * @param SchemaMapperInterface $schemas Resolves a destination's schema.
	 * @param ValidateObjectInterface $validator The destination schema's full validator.
	 * @param ObjectOperationsInterface $objects Creates, re-reads and (to compensate) deletes objects.
	 */
	public function __construct(
		public RegisterMapperInterface $registers,
		public SchemaMapperInterface $schemas,
		public ValidateObjectInterface $validator,
		public ObjectOperationsInterface $objects,
	) {
	}//end __construct()
}//end class
