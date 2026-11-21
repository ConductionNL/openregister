<?php

/**
 * SearchAnnotationValidators: builds the Search annotation validators for the schema save path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search\Service
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

namespace OCA\OpenRegister\Search\Service;

use OCA\OpenRegister\Search\Service\Aggregation\AggregationAnnotationValidator;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationAnnotationValidatorInterface;
use OCA\OpenRegister\Search\Service\Quality\UniqueHintAnnotationValidator;
use OCA\OpenRegister\Service\Quality\UniqueHintAnnotationValidatorInterface;

/**
 * The schema mapper's save-time checks construct these validators directly, outside the
 * container. They do that here, so code outside the Search component names only the interfaces.
 */
final class SearchAnnotationValidators {

	/**
	 * The validator of the `x-openregister-aggregations` annotation.
	 *
	 * @return AggregationAnnotationValidatorInterface
	 */
	public static function aggregation(): AggregationAnnotationValidatorInterface {
		return new AggregationAnnotationValidator();
	}//end aggregation()

	/**
	 * The validator of the `x-openregister-unique-hint` annotation.
	 *
	 * @return UniqueHintAnnotationValidatorInterface
	 */
	public static function uniqueHint(): UniqueHintAnnotationValidatorInterface {
		return new UniqueHintAnnotationValidator();
	}//end uniqueHint()
}//end class
