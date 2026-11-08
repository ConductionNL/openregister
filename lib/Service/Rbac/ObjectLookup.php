<?php

/**
 * Resolves an object, its children, and their registers and schemas.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The mappers an ownership transfer resolves records through.
 *
 * A constructor bundle, autowired by Nextcloud: it carries no behaviour.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class ObjectLookup {

	/**
	 * Bundle the collaborators.
	 *
	 * @param MagicMapperInterface    $magicMapper    Resolves objects and their children.
	 * @param RegisterMapperInterface $registerMapper Resolves a child's register.
	 * @param SchemaMapperInterface   $schemaMapper   Resolves a child's schema.
	 */
	public function __construct(
		public readonly MagicMapperInterface $magicMapper,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()
}//end class
