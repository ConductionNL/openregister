<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\Support;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\Permission\PermissionCallerServices;
use OCA\OpenRegister\Service\Object\Permission\PermissionDataServices;
use OCA\OpenRegister\Service\Object\Permission\PermissionDenyServices;
use OCA\OpenRegister\Service\Object\Permission\PermissionDerivedGrantServices;
use OCA\OpenRegister\Service\Object\Permission\PermissionGrantServices;
use OCA\OpenRegister\Service\Object\Permission\PermissionRuleServices;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\DerivedGrantResolver;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCA\OpenRegister\Service\Rbac\GrantConstraints;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCA\OpenRegister\Service\Rbac\TokenGrantNarrower;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds PermissionHandler from its services as separate arguments, wrapped into its constructor bundles.
 */
final class PermissionHandlerFactory {
	/**
	 * The constructor arguments of PermissionHandler, in the order it took them before the bundles.
	 *
	 * @param IUserSession              $userSession          Constructor argument.
	 * @param IUserManager              $userManager          Constructor argument.
	 * @param IGroupManager             $groupManager         Constructor argument.
	 * @param SchemaMapperInterface     $schemaMapper         Constructor argument.
	 * @param MagicMapperInterface      $objectEntityMapper   Constructor argument.
	 * @param ConditionMatcher          $conditionMatcher     Constructor argument.
	 * @param IAppConfig                $appConfig            Constructor argument.
	 * @param LoggerInterface           $logger               Constructor argument.
	 * @param ContainerInterface        $container            Constructor argument.
	 * @param IEventDispatcher|null     $eventDispatcher      Constructor argument.
	 * @param ObjectScopeResolver|null  $objectScopeResolver  Constructor argument.
	 * @param ObjectGrantResolver|null  $objectGrantResolver  Constructor argument.
	 * @param DenyResolver|null         $denyResolver         Constructor argument.
	 * @param DenyEnforcementMode|null  $denyEnforcementMode  Constructor argument.
	 * @param PermissionCatalogue|null  $permissionCatalogue  Constructor argument.
	 * @param GrantConstraints|null     $grantConstraints     Constructor argument.
	 * @param DerivedGrantStore|null    $derivedGrantStore    Constructor argument.
	 * @param DerivedGrantResolver|null $derivedGrantResolver Constructor argument.
	 * @param TokenGrantSource|null     $tokenGrantSource     Constructor argument.
	 * @param TokenGrantNarrower|null   $tokenGrantNarrower   Constructor argument.
	 *
	 * @return array<int, object>
	 */
	public static function args(
		IUserSession $userSession,
		IUserManager $userManager,
		IGroupManager $groupManager,
		SchemaMapperInterface $schemaMapper,
		MagicMapperInterface $objectEntityMapper,
		ConditionMatcher $conditionMatcher,
		IAppConfig $appConfig,
		LoggerInterface $logger,
		ContainerInterface $container,
		?IEventDispatcher $eventDispatcher = null,
		?ObjectScopeResolver $objectScopeResolver = null,
		?ObjectGrantResolver $objectGrantResolver = null,
		?DenyResolver $denyResolver = null,
		?DenyEnforcementMode $denyEnforcementMode = null,
		?PermissionCatalogue $permissionCatalogue = null,
		?GrantConstraints $grantConstraints = null,
		?DerivedGrantStore $derivedGrantStore = null,
		?DerivedGrantResolver $derivedGrantResolver = null,
		?TokenGrantSource $tokenGrantSource = null,
		?TokenGrantNarrower $tokenGrantNarrower = null,
	): array {
		return [
			new PermissionCallerServices(
				userSession: $userSession,
				userManager: $userManager,
				groupManager: $groupManager,
				appConfig: $appConfig,
			),
			new PermissionDataServices(
				schemaMapper: $schemaMapper,
				objectEntityMapper: $objectEntityMapper,
				container: $container,
				logger: $logger,
			),
			new PermissionRuleServices(
				conditionMatcher: $conditionMatcher,
				eventDispatcher: $eventDispatcher,
				objectScopeResolver: $objectScopeResolver,
				objectGrantResolver: $objectGrantResolver,
			),
			new PermissionGrantServices(
				deny: new PermissionDenyServices(
					denyResolver: $denyResolver,
					denyEnforcementMode: $denyEnforcementMode,
					permissionCatalogue: $permissionCatalogue,
					grantConstraints: $grantConstraints,
				),
				derived: new PermissionDerivedGrantServices(
					derivedGrantStore: $derivedGrantStore,
					derivedGrantResolver: $derivedGrantResolver,
					tokenGrantSource: $tokenGrantSource,
					tokenGrantNarrower: $tokenGrantNarrower,
				),
			),
		];
	}

	/**
	 * Build PermissionHandler; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return PermissionHandler
	 */
	public static function create(mixed ...$args): PermissionHandler {
		return new PermissionHandler(...self::args(...$args));
	}
}
