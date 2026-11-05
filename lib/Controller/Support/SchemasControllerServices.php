<?php

/**
 * OpenRegister schemas controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

/**
 * Everything the schemas controller injects besides the request.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class SchemasControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param RegisterMappers $mappers   Register, schema, audit and object mappers.
	 * @param SchemaServices  $schemas   Schema caches, exploration and versioning.
	 * @param SchemaSemantics $semantics JSON-LD, import and semantic typing.
	 * @param SchemaPlatform  $platform  Uploads, organisations, logger and container.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function __construct(
		public readonly RegisterMappers $mappers,
		public readonly SchemaServices $schemas,
		public readonly SchemaSemantics $semantics,
		public readonly SchemaPlatform $platform,
	) {
	}//end __construct()
}//end class
