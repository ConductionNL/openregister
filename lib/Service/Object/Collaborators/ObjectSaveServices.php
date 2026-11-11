<?php

/**
 * OpenRegister ObjectService save services: single and bulk save and their validation
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\Collaborators
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

namespace OCA\OpenRegister\Service\Object\Collaborators;

use OCA\OpenRegister\Service\Object\SaveObjectInterface;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\ValidateObjectInterface;
use OCA\OpenRegister\Service\Object\ValidationHandler;

/**
 * ObjectService save services: single and bulk save and their validation.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectSaveServices {
	/**
	 * Bundle the services.
	 *
	 * @param SaveObjectInterface     $saveHandler        Handler for individual object saving.
	 * @param SaveObjects             $saveObjectsHandler Handler for bulk object saving operations.
	 * @param ValidateObjectInterface $validateHandler    Handler for object validation.
	 * @param ValidationHandler       $validationHandler  Handler for validation operations.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly SaveObjectInterface $saveHandler,
		public readonly SaveObjects $saveObjectsHandler,
		public readonly ValidateObjectInterface $validateHandler,
		public readonly ValidationHandler $validationHandler,
	) {
	}//end __construct()
}//end class
