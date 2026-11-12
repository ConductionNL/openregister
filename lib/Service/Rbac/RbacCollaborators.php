<?php

/**
 * RbacCollaborators: the RBAC helpers the query layer and the bulk endpoint build, behind their interfaces.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * Builds or looks up the RBAC helpers callers outside lib/Service use.
 *
 * Each method does exactly what the caller did itself before: a `new` with
 * the same collaborators, a container lookup under the same id handed back
 * as the container returns it, or the same static call. The caller names the
 * interface and never the class that implements it.
 *
 * Types only: what is granted, denied or refused, and when, stays with the
 * caller.
 */
final class RbacCollaborators {

	/**
	 * The shared per-object grant resolver, exactly as the container returns it.
	 *
	 * @param ContainerInterface $container The container the caller holds.
	 *
	 * @return mixed The shared ObjectGrantResolverInterface instance.
	 */
	public static function objectGrantsFrom(ContainerInterface $container): mixed {
		return $container->get(ObjectGrantResolver::class);
	}//end objectGrantsFrom()

	/**
	 * A fresh reader of the deny grammar.
	 *
	 * @return DenyResolverInterface
	 */
	public static function denyResolver(): DenyResolverInterface {
		return new DenyResolver();
	}//end denyResolver()

	/**
	 * An authorization block with its MCP scope entries removed.
	 *
	 * @param array<string, mixed>|null $authorization The authorization block.
	 *
	 * @return array<string, mixed>|null The block without MCP scope entries.
	 */
	public static function withoutMcpScope(?array $authorization): ?array {
		return McpScope::strip(authorization: $authorization);
	}//end withoutMcpScope()

	/**
	 * A fresh schema manage gate over the given collaborators.
	 *
	 * @param IUserSession          $userSession  The caller.
	 * @param IGroupManager         $groupManager The caller's groups and admin status.
	 * @param SchemaMapperInterface $schemaMapper Resolves a schema a row names.
	 *
	 * @return SchemaManageGateInterface
	 */
	public static function schemaManageGate(
		IUserSession $userSession,
		IGroupManager $groupManager,
		SchemaMapperInterface $schemaMapper
	): SchemaManageGateInterface {
		return new SchemaManageGate(userSession: $userSession, groupManager: $groupManager, schemaMapper: $schemaMapper);
	}//end schemaManageGate()
}//end class
