<?php

/**
 * OpenRegister schema semantics services
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

use OCA\OpenRegister\Service\JsonLd\JsonLdContextServiceInterface;
use OCA\OpenRegister\Service\SchemaImport\SchemaImportService;
use OCA\OpenRegister\Service\Schemas\SemanticRoleHandler;
use OCA\OpenRegister\Service\SemanticTypeResolverInterface;

/**
 * JSON-LD context, schema import and semantic typing; each is null when absent.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class SchemaSemantics {
	/**
	 * Bundle the services.
	 *
	 * @param ?JsonLdContextServiceInterface $jsonLdContextService The JSON-LD context service.
	 * @param ?SchemaImportService           $schemaImportService  The schema import service.
	 * @param ?SemanticTypeResolverInterface $semanticTypeResolver The semantic type resolver.
	 * @param ?SemanticRoleHandler           $semanticRoles        The semantic role handler.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function __construct(
		public readonly ?JsonLdContextServiceInterface $jsonLdContextService = null,
		public readonly ?SchemaImportService $schemaImportService = null,
		public readonly ?SemanticTypeResolverInterface $semanticTypeResolver = null,
		public readonly ?SemanticRoleHandler $semanticRoles = null,
	) {
	}//end __construct()
}//end class
