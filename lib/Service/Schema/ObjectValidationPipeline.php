<?php

/**
 * OpenRegister object validation pipeline
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
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

namespace OCA\OpenRegister\Service\Schema;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\ObjectValidatorInterface;

/**
 * The object read/save pipeline together with the single write-path validator.
 */
final class ObjectValidationPipeline {
	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objectService  Object read/save pipeline.
	 * @param ObjectValidatorInterface  $validateObject Single write-path validator.
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly ObjectValidatorInterface $validateObject,
	) {

	}//end __construct()
}//end class
