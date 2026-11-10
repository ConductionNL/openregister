<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\SchemasController;

/**
 * Builds SchemasController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class SchemasControllerFactory {
	/**
	 * The constructor arguments of SchemasController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param IAppConfig $config Unused; kept so existing calls still fit.
	 * @param SchemaMapper $schemaMapper Constructor argument.
	 * @param RegisterMapper $registerMapper Constructor argument.
	 * @param MagicMapper $objectEntityMapper Constructor argument.
	 * @param UploadService $uploadService Constructor argument.
	 * @param AuditTrailMapper $auditTrailMapper Constructor argument.
	 * @param OrganisationService $organisationService Constructor argument.
	 * @param SchemaCacheHandler $schemaCacheService Constructor argument.
	 * @param FacetResultCacheClearer $facetCacheSvc Constructor argument.
	 * @param SchemaExplorationInterface $schemaService Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param ContainerInterface $container Constructor argument.
	 * @param SchemaVersioningService $schemaVersioningService Constructor argument.
	 * @param ?JsonLdContextService $jsonLdContextService Constructor argument.
	 * @param ?SchemaImportService $schemaImportService Constructor argument.
	 * @param ?SemanticTypeResolver $semanticTypeResolver Constructor argument.
	 * @param ?SemanticRoleHandler $semanticRoles Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCP\IAppConfig $config,
		\OCA\OpenRegister\Db\SchemaMapper $schemaMapper,
		\OCA\OpenRegister\Db\RegisterMapper $registerMapper,
		\OCA\OpenRegister\Db\MagicMapper $objectEntityMapper,
		\OCA\OpenRegister\Files\Service\UploadService $uploadService,
		\OCA\OpenRegister\Db\AuditTrailMapper $auditTrailMapper,
		\OCA\OpenRegister\Service\OrganisationService $organisationService,
		\OCA\OpenRegister\Service\Schemas\SchemaCacheHandler $schemaCacheService,
		\OCA\OpenRegister\Service\Schemas\FacetResultCacheClearer $facetCacheSvc,
		\OCA\OpenRegister\Kernel\Contracts\SchemaExplorationInterface $schemaService,
		\Psr\Log\LoggerInterface $logger,
		\Psr\Container\ContainerInterface $container,
		\OCA\OpenRegister\Service\Schema\SchemaVersioningService $schemaVersioningService,
		?\OCA\OpenRegister\Service\JsonLd\JsonLdContextService $jsonLdContextService = null,
		?\OCA\OpenRegister\Service\SchemaImport\SchemaImportService $schemaImportService = null,
		?\OCA\OpenRegister\Service\SemanticTypeResolver $semanticTypeResolver = null,
		?\OCA\OpenRegister\Service\Schemas\SemanticRoleHandler $semanticRoles = null,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\SchemasControllerServices(
				mappers: new \OCA\OpenRegister\Controller\Support\RegisterMappers(
					registerMapper: $registerMapper,
					schemaMapper: $schemaMapper,
					auditTrailMapper: $auditTrailMapper,
					objectEntityMapper: $objectEntityMapper,
				),
				schemas: new \OCA\OpenRegister\Controller\Support\SchemaServices(
					schemaCacheService: $schemaCacheService,
					facetResults: $facetCacheSvc,
					schemaService: $schemaService,
					versioning: $schemaVersioningService,
				),
				semantics: new \OCA\OpenRegister\Controller\Support\SchemaSemantics(
					jsonLdContextService: $jsonLdContextService,
					schemaImportService: $schemaImportService,
					semanticTypeResolver: $semanticTypeResolver,
					semanticRoles: $semanticRoles,
				),
				platform: new \OCA\OpenRegister\Controller\Support\SchemaPlatform(
					uploadService: $uploadService,
					organisationService: $organisationService,
					logger: $logger,
					container: $container,
				),
			),
		];
	}

	/**
	 * Build SchemasController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return SchemasController
	 */
	public static function create(mixed ...$args): SchemasController {
		return new SchemasController(...self::args(...$args));
	}
}
