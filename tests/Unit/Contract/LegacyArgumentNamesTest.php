<?php

/**
 * The published object methods keep the parameter names and defaults consuming apps call them with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Consuming apps call these methods with named arguments (`register:`, `schema:`,
 * `uuid:`, `_extend:` and so on). PHP resolves a named argument against the
 * parameter NAME of the method that runs, so renaming, removing or moving one of
 * these parameters is a runtime "Unknown named parameter" error in every app that
 * uses it, and no type check in this repository notices. This test pins the names
 * and defaults published in v2.1.47, and main's value-object parameters in main's
 * positions, which test doubles and positional value-object calls rely on.
 */
class LegacyArgumentNamesTest extends TestCase {

	/**
	 * Marks a parameter that was required in the release and is optional now.
	 */
	private const REQUIRED = '__required__';

	/**
	 * The release's parameters per method, in order, with their defaults, then the options parameter.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: list<string>}>
	 */
	private static function contract(): array {
		return [
			'saveObject'   => [
				'saveObject',
				[
					'object'         => self::REQUIRED,
					'extend'         => [],
					'register'       => null,
					'schema'         => null,
					'uuid'           => null,
					'_rbac'          => true,
					'_multitenancy'  => true,
					'silent'         => false,
					'_validation'    => true,
					'uploadedFiles'  => null,
					'currentUser'    => null,
					'failIfExists'   => false,
					'_unowned'       => false,
					'_dedupOverride' => false,
					'_owner'         => null,
				],
				['object', 'options', '_rbac', '_multitenancy'],
			],
			'find'         => [
				'find',
				[
					'id'            => self::REQUIRED,
					'_extend'       => [],
					'files'         => false,
					'register'      => null,
					'schema'        => null,
					'_rbac'         => true,
					'_multitenancy' => true,
					'_render'       => true,
					'_audit'        => true,
				],
				['id', 'options', '_rbac', '_multitenancy'],
			],
			'findSilent'   => [
				'findSilent',
				[
					'id'            => self::REQUIRED,
					'_extend'       => [],
					'files'         => false,
					'register'      => null,
					'schema'        => null,
					'_rbac'         => true,
					'_multitenancy' => true,
				],
				['id', 'options', '_rbac', '_multitenancy'],
			],
			'deleteObject' => [
				'deleteObject',
				[
					'uuid'            => self::REQUIRED,
					'register'        => null,
					'schema'          => null,
					'_rbac'           => true,
					'_multitenancy'   => true,
					'_retentionSweep' => false,
					'currentUser'     => null,
					'permanent'       => false,
				],
				['deletion', '_rbac', '_multitenancy', '_retentionSweep'],
			],
			'saveObjects'  => [
				'saveObjects',
				[
					'objects'        => self::REQUIRED,
					'register'       => null,
					'schema'         => null,
					'_rbac'          => true,
					'_multitenancy'  => true,
					'validation'     => false,
					'events'         => false,
					'deduplicateIds' => true,
					'enrich'         => true,
					'_audit'         => true,
				],
				['objects', 'options', '_rbac', '_multitenancy'],
			],
			'lockObject'   => [
				'lockObject',
				[
					'identifier' => self::REQUIRED,
					'process'    => null,
					'duration'   => null,
					'advisory'   => false,
					'runUuid'    => null,
					'nodeId'     => null,
				],
				['request', 'advisory'],
			],
			'unlockObject' => [
				'unlockObject',
				[
					'identifier' => self::REQUIRED,
					'advisory'   => false,
					'runUuid'    => null,
				],
				['request'],
			],
			'patchObject'  => [
				'patchObject',
				[
					'objectId'      => self::REQUIRED,
					'data'          => self::REQUIRED,
					'register'      => null,
					'schema'        => null,
					'_rbac'         => true,
					'_multitenancy' => true,
					'currentUser'   => null,
				],
				['patch', '_rbac', '_multitenancy'],
			],
		];
	}//end contract()

	/**
	 * Every published method on every class or interface a consuming app resolves.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: array<string, mixed>, 3: list<string>}>
	 */
	public static function publishedMethods(): array {
		$cases = [];
		foreach ([ObjectServiceInterface::class, ObjectOperationsInterface::class, ObjectService::class] as $class) {
			foreach (self::contract() as $key => [$method, $params, $main]) {
				$cases[$class.'::'.$key] = [$class, $method, $params, $main];
			}
		}

		$render = [
			'entity'        => self::REQUIRED,
			'_extend'       => [],
			'depth'         => 0,
			'filter'        => [],
			'fields'        => [],
			'unset'         => [],
			'_rbac'         => true,
			'_multitenancy' => true,
		];
		foreach ([ObjectOperationsInterface::class, ObjectService::class] as $class) {
			$cases[$class.'::renderEntity'] = [$class, 'renderEntity', $render, ['entity', 'selection', '_rbac', '_multitenancy']];
		}

		return $cases;
	}//end publishedMethods()

	/**
	 * Main's parameters keep main's positions; every release parameter is still there by name, with its default.
	 *
	 * Main's positions are what test doubles and positional value-object calls rely on; the
	 * release's names are what consuming apps call with.
	 *
	 * @param class-string         $class  The class or interface.
	 * @param string               $method The method.
	 * @param array<string, mixed> $params The release's parameters and defaults.
	 * @param list<string>         $main   Main's parameter names, in order.
	 *
	 * @return void
	 */
	#[DataProvider('publishedMethods')]
	public function testTheReleaseParametersAreStillThere(string $class, string $method, array $params, array $main): void {
		$parameters = [];
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
			$parameters[$parameter->getName()] = $parameter;
		}

		$this->assertSame(
			$main,
			array_slice(array_keys($parameters), 0, count($main)),
			"{$class}::{$method}() must keep main's parameters in main's positions."
		);

		foreach ($params as $name => $default) {
			$this->assertArrayHasKey($name, $parameters, "{$class}::{$method}() must keep the parameter \${$name} consuming apps call it with.");
			if ($default === self::REQUIRED) {
				continue;
			}

			$parameter = $parameters[$name];
			$this->assertTrue($parameter->isDefaultValueAvailable(), "{$class}::{$method}() \${$name} must stay optional.");
			if ($parameter->getPosition() < count($main)) {
				continue;
			}

			$this->assertSame($default, $parameter->getDefaultValue(), "{$class}::{$method}() \${$name} must keep its default.");
		}
	}//end testTheReleaseParametersAreStillThere()
}//end class
