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
 * uses it, and no type check in this repository notices. This test pins the names,
 * their order and their defaults as published in v2.1.47, and the trailing
 * options-object parameter that takes the newer form by name.
 */
class LegacyArgumentNamesTest extends TestCase {

	/**
	 * Marks a parameter that was required in the release and is optional now.
	 */
	private const REQUIRED = '__required__';

	/**
	 * The release's parameters per method, in order, with their defaults, then the options parameter.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
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
				'options',
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
				'options',
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
				'options',
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
				'deletion',
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
				'options',
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
				'request',
			],
			'unlockObject' => [
				'unlockObject',
				[
					'identifier' => self::REQUIRED,
					'advisory'   => false,
					'runUuid'    => null,
				],
				'request',
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
				'patch',
			],
		];
	}//end contract()

	/**
	 * Every published method on every class or interface a consuming app resolves.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: array<string, mixed>, 3: string}>
	 */
	public static function publishedMethods(): array {
		$cases = [];
		foreach ([ObjectServiceInterface::class, ObjectOperationsInterface::class, ObjectService::class] as $class) {
			foreach (self::contract() as $key => [$method, $params, $options]) {
				$cases[$class.'::'.$key] = [$class, $method, $params, $options];
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
			$cases[$class.'::renderEntity'] = [$class, 'renderEntity', $render, 'selection'];
		}

		return $cases;
	}//end publishedMethods()

	/**
	 * The release's parameters keep their names, positions and defaults; the options form follows them.
	 *
	 * @param class-string         $class   The class or interface.
	 * @param string               $method  The method.
	 * @param array<string, mixed> $params  The release's parameters and defaults, in order.
	 * @param string               $options The name of the options-object parameter.
	 *
	 * @return void
	 */
	#[DataProvider('publishedMethods')]
	public function testTheReleaseParametersAreStillThere(string $class, string $method, array $params, string $options): void {
		$parameters = (new ReflectionMethod($class, $method))->getParameters();

		$expected = array_keys($params);
		$expected[] = $options;
		$this->assertSame(
			$expected,
			array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $parameters),
			"{$class}::{$method}() must keep the parameter names and order consuming apps call it with."
		);

		foreach (array_values($params) as $position => $default) {
			if ($default === self::REQUIRED) {
				continue;
			}

			$parameter = $parameters[$position];
			$this->assertTrue($parameter->isDefaultValueAvailable(), "{$class}::{$method}() \${$parameter->getName()} must stay optional.");
			$this->assertSame($default, $parameter->getDefaultValue(), "{$class}::{$method}() \${$parameter->getName()} must keep its default.");
		}

		$last = end($parameters);
		$this->assertTrue($last->allowsNull() && $last->isDefaultValueAvailable() && $last->getDefaultValue() === null, "{$class}::{$method}() \${$options} must default to null.");
	}//end testTheReleaseParametersAreStillThere()
}//end class
