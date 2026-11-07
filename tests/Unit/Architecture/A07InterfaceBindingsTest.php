<?php

/**
 * The A07 role interfaces are implemented, bound, and add up to the interface they split.
 *
 * PermissionHandlerInterface, CacheHandlerInterface and ValidateObjectInterface were split
 * into role interfaces so a caller that needs one role depends on that role, not on the
 * whole class. Each original extends every role, so its method set must stay exactly what
 * it was; a role missing a binding is a runtime 500 that no other unit test sees, so this
 * test pins the bindings as well.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Object\AuthorizationResolutionInterface;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\Object\ObjectCacheInvalidationInterface;
use OCA\OpenRegister\Service\Object\ObjectCacheMaintenanceInterface;
use OCA\OpenRegister\Service\Object\ObjectEntityCacheInterface;
use OCA\OpenRegister\Service\Object\ObjectNameWriterInterface;
use OCA\OpenRegister\Service\Object\ObjectReadersInterface;
use OCA\OpenRegister\Service\Object\ObjectValidatorInterface;
use OCA\OpenRegister\Service\Object\PermissionCacheInterface;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\Object\PermissionEnforcementInterface;
use OCA\OpenRegister\Service\Object\PermissionExplanationInterface;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\PermissionScopeInterface;
use OCA\OpenRegister\Service\Object\SchemaUriResolverInterface;
use OCA\OpenRegister\Service\Object\TransitionAuthorizationInterface;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidateObjectInterface;
use OCA\OpenRegister\Service\Object\ValidationErrorResponseInterface;
use OCA\OpenRegister\Service\Object\WriteConstraintValidatorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @coversNothing
 */
final class A07InterfaceBindingsTest extends TestCase {

	/**
	 * Every public method each split interface declared before the split.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const SURFACES = [
		CacheHandlerInterface::class => [
			'clearAllCaches',
			'clearCache',
			'clearNameCache',
			'clearSearchCache',
			'getAllObjectNames',
			'getDistributedNameCacheCount',
			'getMultipleObjectNames',
			'getObject',
			'getSingleObjectName',
			'getStats',
			'invalidateForObjectChange',
			'preloadObjects',
			'setObjectName',
			'warmupNameCache',
		],
		PermissionHandlerInterface::class => [
			'checkPermission',
			'clearInheritFromPublicCache',
			'clearPermissionCache',
			'denialFor',
			'enforcedDenialFor',
			'expandRoles',
			'filterUuidsForPermissions',
			'getActiveOrganisationForContext',
			'getAuthorizedGroups',
			'getReadableByUsers',
			'hasGroupPermission',
			'hasPermission',
			'inheritFromPublicTenantDefault',
			'isTransitionAuthorized',
			'matrixRowsFromApps',
			'permittedActionsFor',
			'provenanceFor',
			'resolveAuthorization',
			'resolveDeclaredAuthorization',
			'resolveInheritFromPublic',
		],
		ValidateObjectInterface::class => [
			'generateErrorMessage',
			'handleValidationException',
			'resolveSchema',
			'validateImmutableConstraints',
			'validateObject',
			'validateObjectWithPlannedUsers',
			'validateReadOnlyConstraints',
		],
	];

	/**
	 * Role interface => the interface it was split from, and the class it is bound to.
	 *
	 * @var array<class-string, array{class-string, class-string}>
	 */
	private const ROLES = [
		AuthorizationResolutionInterface::class => [PermissionHandlerInterface::class, PermissionHandler::class],
		ObjectCacheInvalidationInterface::class => [CacheHandlerInterface::class, CacheHandler::class],
		ObjectCacheMaintenanceInterface::class => [CacheHandlerInterface::class, CacheHandler::class],
		ObjectEntityCacheInterface::class => [CacheHandlerInterface::class, CacheHandler::class],
		ObjectNameWriterInterface::class => [CacheHandlerInterface::class, CacheHandler::class],
		ObjectReadersInterface::class => [PermissionHandlerInterface::class, PermissionHandler::class],
		ObjectValidatorInterface::class => [ValidateObjectInterface::class, ValidateObject::class],
		PermissionCacheInterface::class => [PermissionHandlerInterface::class, PermissionHandler::class],
		PermissionCheckInterface::class => [PermissionHandlerInterface::class, PermissionHandler::class],
		PermissionEnforcementInterface::class => [PermissionHandlerInterface::class, PermissionHandler::class],
		PermissionExplanationInterface::class => [PermissionHandlerInterface::class, PermissionHandler::class],
		PermissionScopeInterface::class => [PermissionHandlerInterface::class, PermissionHandler::class],
		SchemaUriResolverInterface::class => [ValidateObjectInterface::class, ValidateObject::class],
		TransitionAuthorizationInterface::class => [PermissionHandlerInterface::class, PermissionHandler::class],
		ValidationErrorResponseInterface::class => [ValidateObjectInterface::class, ValidateObject::class],
		WriteConstraintValidatorInterface::class => [ValidateObjectInterface::class, ValidateObject::class],
	];

	/**
	 * One case per role interface.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function roles(): array {
		$cases = [];
		foreach (array_keys(self::ROLES) as $role) {
			$cases[$role] = [$role];
		}

		return $cases;
	}//end roles()

	/**
	 * One case per split interface.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function surfaces(): array {
		$cases = [];
		foreach (array_keys(self::SURFACES) as $interface) {
			$cases[$interface] = [$interface];
		}

		return $cases;
	}//end surfaces()

	/**
	 * The split interface still declares exactly the methods it declared before.
	 *
	 * @param class-string $interface The split interface.
	 *
	 * @return void
	 */
	#[DataProvider('surfaces')]
	public function testTheSplitSurfaceIsUnchanged(string $interface): void {
		$names = array_map(
			callback: static fn (ReflectionMethod $method): string => $method->getName(),
			array: (new ReflectionClass($interface))->getMethods()
		);
		sort(array: $names);

		$this->assertSame(self::SURFACES[$interface], $names);
	}//end testTheSplitSurfaceIsUnchanged()

	/**
	 * The role is part of the interface it was split from, the class implements it, and it is bound.
	 *
	 * @param class-string $role The role interface.
	 *
	 * @return void
	 */
	#[DataProvider('roles')]
	public function testTheRoleIsExtendedImplementedAndBound(string $role): void {
		[$origin, $class] = self::ROLES[$role];

		$this->assertTrue(is_subclass_of(object_or_class: $origin, class: $role), $origin . ' extends ' . $role);
		$this->assertTrue(is_subclass_of(object_or_class: $class, class: $role), $class . ' implements ' . $role);
		$this->assertSame($class, ContractBindings::BINDINGS[$role] ?? null, $role . ' is bound');
	}//end testTheRoleIsExtendedImplementedAndBound()

	/**
	 * No method is declared by two roles, so each role is a real slice of the handler.
	 *
	 * @return void
	 */
	public function testNoMethodBelongsToTwoRoles(): void {
		$owners = [];
		foreach (array_keys(self::ROLES) as $role) {
			foreach ((new ReflectionClass($role))->getMethods() as $method) {
				$owners[$method->getName()][] = $role;
			}
		}

		$shared = array_filter(array: $owners, callback: static fn (array $roles): bool => count(value: $roles) > 1);
		$this->assertSame([], $shared);
	}//end testNoMethodBelongsToTwoRoles()
}//end class
