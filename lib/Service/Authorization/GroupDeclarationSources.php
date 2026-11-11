<?php

/**
 * OpenRegister — Declared Group Sources
 *
 * The three places {@see GroupReconciler} reads declared RBAC groups from: the
 * live registers, the live schemas, and each app's stored declaration list in
 * app config. They are only ever read together, by the same sweep, so they are
 * handed over as one collaborator. This bundle carries no behaviour.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IAppConfig;

/**
 * Where declared groups are read from, wired by DI autowiring.
 *
 * @spec openspec/specs/auth-system/spec.md
 */
final class GroupDeclarationSources {

	/**
	 * Live register lookup.
	 *
	 * @var RegisterMapperInterface
	 */
	public readonly RegisterMapperInterface $registerMapper;

	/**
	 * Live schema lookup.
	 *
	 * @var SchemaMapperInterface
	 */
	public readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Bundle the sources.
	 *
	 * @param SchemaCatalogInterface $catalog   Live register and schema lookups.
	 * @param IAppConfig             $appConfig Stores each app's declared group list.
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function __construct(
		SchemaCatalogInterface $catalog,
		public readonly IAppConfig $appConfig,
	) {
		$this->registerMapper = $catalog->registers();
		$this->schemaMapper = $catalog->schemas();
	}//end __construct()
}//end class
