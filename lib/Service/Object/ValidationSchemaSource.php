<?php

/**
 * OpenRegister validation schema source
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * Where a validation loads its schemas and resolves their references from.
 *
 * @spec openspec/specs/object-validation/spec.md
 */
final class ValidationSchemaSource {
	/**
	 * Bundle the services.
	 *
	 * @param IAppConfig            $config       Configuration service.
	 * @param SchemaMapperInterface $schemaMapper Schema mapper.
	 * @param IURLGenerator         $urlGenerator URL generator.
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	public function __construct(
		public readonly IAppConfig $config,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()
}//end class
