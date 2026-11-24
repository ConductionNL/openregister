<?php

/**
 * ArchivalDeclarationValidators: the archival validators the schema save checks build, behind their interfaces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
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

namespace OCA\OpenRegister\Service\Schemas;

use OCA\OpenRegister\Service\Archival\ArchivalAnnotationValidator;
use OCA\OpenRegister\Service\Archival\ArchivalAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Archival\ElementMappingValidator;
use OCA\OpenRegister\Service\Archival\ElementMappingValidatorInterface;
use OCA\OpenRegister\Service\Archival\MdtoElementCatalogue;

/**
 * Builds the MDTO element-mapping and archival annotation validators.
 *
 * The archival declaration checks Compliance contributes to the schema save
 * live in the Db layer and have no container: each builds its validator with
 * `new` through this class, typed against the validator's interface.
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
final class ArchivalDeclarationValidators {

	/**
	 * A fresh MDTO element-mapping validator over the MDTO element catalogue.
	 *
	 * @return ElementMappingValidatorInterface
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public static function elementMapping(): ElementMappingValidatorInterface {
		return new ElementMappingValidator(catalogue: new MdtoElementCatalogue());
	}//end elementMapping()

	/**
	 * A fresh archival annotation validator.
	 *
	 * @return ArchivalAnnotationValidatorInterface
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public static function archival(): ArchivalAnnotationValidatorInterface {
		return new ArchivalAnnotationValidator();
	}//end archival()
}//end class
