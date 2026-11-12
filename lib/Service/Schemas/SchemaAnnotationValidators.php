<?php

/**
 * SchemaAnnotationValidators: builds the lib/Service annotation validators for the schema save path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
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

namespace OCA\OpenRegister\Service\Schemas;

use OCA\OpenRegister\Service\Hinge\HingeAnnotationValidator;
use OCA\OpenRegister\Service\Hinge\HingeAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipAnnotationValidator;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipAnnotationValidatorInterface;

/**
 * The schema mapper's save-time checks construct these validators directly, outside the
 * container. They do that here, so code outside lib/Service names only the interfaces.
 */
final class SchemaAnnotationValidators {

	/**
	 * The validator of the lens, list and geographic-inheritance annotations.
	 *
	 * @return HingeAnnotationValidatorInterface
	 */
	public static function hinge(): HingeAnnotationValidatorInterface {
		return new HingeAnnotationValidator();
	}//end hinge()

	/**
	 * The validator of the `x-openregister-survivorship` annotation.
	 *
	 * @return SurvivorshipAnnotationValidatorInterface
	 */
	public static function survivorship(): SurvivorshipAnnotationValidatorInterface {
		return new SurvivorshipAnnotationValidator();
	}//end survivorship()
}//end class
