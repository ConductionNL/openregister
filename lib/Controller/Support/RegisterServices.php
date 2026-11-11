<?php

/**
 * OpenRegister register services
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

use OCA\OpenRegister\Service\MigrationPackServiceInterface;
use OCA\OpenRegister\Service\Registers\RegisterCacheHandlerInterface;
use OCA\OpenRegister\Service\RegisterServiceInterface;
use OCA\OpenRegister\Service\Serializer\RegisterSerializerInterface;

/**
 * The register service with its cache, serializer and migration packs.
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */
final class RegisterServices {
	/**
	 * Bundle the services.
	 *
	 * @param RegisterServiceInterface      $registerService      The register service.
	 * @param RegisterCacheHandlerInterface          $registerCacheHandler The register cache.
	 * @param RegisterSerializerInterface            $registerSerializer   The register serializer.
	 * @param MigrationPackServiceInterface $migrationPackService The migration pack service.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function __construct(
		public readonly RegisterServiceInterface $registerService,
		public readonly RegisterCacheHandlerInterface $registerCacheHandler,
		public readonly RegisterSerializerInterface $registerSerializer,
		public readonly MigrationPackServiceInterface $migrationPackService,
	) {
	}//end __construct()
}//end class
