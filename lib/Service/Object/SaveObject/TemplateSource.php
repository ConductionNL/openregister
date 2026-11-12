<?php

/**
 * What a metadata template reads its placeholder values from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * The object data and the schema properties used to resolve relations to names.
 */
final readonly class TemplateSource {

	/**
	 * Constructor.
	 *
	 * @param array $data             The object data.
	 * @param array $schemaProperties The schema properties, for relation resolution.
	 */
	public function __construct(
		public array $data,
		public array $schemaProperties,
	) {
	}//end __construct()
}//end class
