<?php

/**
 * Flattens OpenRegister's object-service option objects into named arguments.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  __NS__
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.conduction.nl
 */

declare(strict_types=1);

namespace __NS__;

use OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\LockRequest;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\UnlockRequest;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\Constraint\IsAnything;
use PHPUnit\Framework\Constraint\IsEqual;

/**
 * Lets a recording test double keep reading the arguments it was written against
 * (`register`, `schema`, `uuid`, ...) while callers pass OpenRegister's option objects.
 * Only the values a caller set away from their defaults are listed, as a caller of the
 * named-argument form only listed those.
 */
final class LegacyObjectArgs {

	/**
	 * The release's default per position, for the positions that have one.
	 */
	private const DEFAULTS = [
		'find' => [1 => [], 2 => false, 3 => null, 4 => null, 5 => true, 6 => true, 7 => true, 8 => true],
		'findSilent' => [1 => [], 2 => false, 3 => null, 4 => null, 5 => true, 6 => true, 7 => true, 8 => true],
		'saveObject' => [1 => [], 2 => null, 3 => null, 4 => null, 5 => true, 6 => true, 7 => false, 8 => true, 9 => null, 10 => null, 11 => false, 12 => false, 13 => false, 14 => null],
		'deleteObject' => [1 => null, 2 => null, 3 => true, 4 => true, 5 => false, 6 => null, 7 => false],
		'patchObject' => [2 => null, 3 => null, 4 => true, 5 => true, 6 => null],
	];

	/**
	 * The release's parameter names, in order.
	 */
	private const RELEASE = [
		'find' => ['id', '_extend', 'files', 'register', 'schema', '_rbac', '_multitenancy', '_render', '_audit'],
		'findSilent' => ['id', '_extend', 'files', 'register', 'schema', '_rbac', '_multitenancy', '_render', '_audit'],
		'saveObject' => ['object', 'extend', 'register', 'schema', 'uuid', '_rbac', '_multitenancy', 'silent', '_validation', 'uploadedFiles', 'currentUser', 'failIfExists', '_unowned', '_dedupOverride', '_owner'],
		'deleteObject' => ['uuid', 'register', 'schema', '_rbac', '_multitenancy', '_retentionSweep', 'currentUser', 'permanent'],
		'patchObject' => ['objectId', 'data', 'register', 'schema', '_rbac', '_multitenancy', 'currentUser'],
	];

	/**
	 * Replace every option object among the arguments with the named values it carries.
	 *
	 * @param array<int|string, mixed> $args The arguments a variadic double received.
	 *
	 * @return array<int|string, mixed> The arguments with option objects flattened.
	 */
	public static function flatten(array $args): array {
		$flat = [];
		foreach ($args as $key => $value) {
			$named = self::named(value: $value);
			if ($named === null) {
				$flat[$key] = $value;
				continue;
			}

			foreach ($named as $name => $item) {
				$flat[$name] = $item;
			}
		}

		return $flat;
	}//end flatten()

	/**
	 * The release's positional argument list for a call made in main's form.
	 *
	 * @param string            $method The object-service method.
	 * @param array<int, mixed> $args   The arguments the double received.
	 * @param bool              $trim   True to drop trailing defaults, as a caller that stopped early passed none.
	 *
	 * @return array<int, mixed> The arguments in the release's order.
	 */
	public static function positional(string $method, array $args, bool $trim = false): array {
		$list = self::release(method: $method, args: array_values($args));
		if ($trim === true && isset(self::DEFAULTS[$method]) === true) {
			// A caller that stopped early passed none of the trailing defaults.
			while (count($list) > 1 && array_key_exists(count($list) - 1, self::DEFAULTS[$method]) === true && $list[count($list) - 1] === self::DEFAULTS[$method][count($list) - 1]) {
				array_pop($list);
			}
		}

		return $list;
	}//end positional()

	/**
	 * The release's positional argument list, defaults filled in.
	 *
	 * @param string            $method The object-service method.
	 * @param array<int, mixed> $args   The arguments in main's order.
	 *
	 * @return array<int, mixed> The arguments in the release's order.
	 */
	private static function release(string $method, array $args): array {
		$first = ($args[0] ?? null);
		$second = ($args[1] ?? null);
		if ($method === 'find' || $method === 'findSilent') {
			$o = ($second instanceof ObjectFindOptions) ? $second : new ObjectFindOptions();
			return [$first, $o->extend, $o->files, $o->register, $o->schema, ($args[2] ?? true), ($args[3] ?? true), $o->render, $o->audit];
		}

		if ($method === 'saveObject') {
			$o = ($second instanceof ObjectSaveOptions) ? $second : new ObjectSaveOptions();
			return [
				$first, $o->extend, $o->target->register, $o->target->schema, $o->uuid, ($args[2] ?? true), ($args[3] ?? true),
				$o->policy->silent, $o->policy->validation, $o->uploadedFiles, $o->actor->currentUser, $o->policy->failIfExists,
				$o->actor->unowned, $o->policy->dedupOverride, $o->actor->owner,
			];
		}

		if ($method === 'deleteObject' && $first instanceof ObjectDeletion) {
			return [$first->uuid, $first->target->register, $first->target->schema, ($args[1] ?? true), ($args[2] ?? true), ($args[3] ?? false), $first->currentUser, $first->permanent];
		}

		if ($method === 'patchObject' && $first instanceof ObjectPatch) {
			return [$first->objectId, $first->data, $first->target->register, $first->target->schema, ($args[1] ?? true), ($args[2] ?? true), $first->currentUser];
		}

		return $args;
	}//end release()

	/**
	 * A return callback answering from a map written against the release's positional arguments.
	 *
	 * @param string                        $method The object-service method.
	 * @param array<int, array<int, mixed>> $map    Rows of release-order arguments, the value to return last.
	 *
	 * @return \Closure The callback.
	 */
	public static function returnMap(string $method, array $map): \Closure {
		return static function (mixed ...$args) use ($method, $map): mixed {
			$actual = self::positional(method: $method, args: $args);
			foreach ($map as $row) {
				$expected = array_slice($row, 0, -1);
				if (array_slice($actual, 0, count($expected)) == $expected) {
					return $row[count($row) - 1];
				}
			}

			return null;
		};
	}//end returnMap()

	/**
	 * Argument constraints for a mock expectation written against the release's named arguments.
	 *
	 * @param string               $method   The object-service method.
	 * @param array<string, mixed> $expected Release argument names to values or constraints.
	 *
	 * @return array<int, Constraint> One constraint per argument of main's signature.
	 */
	public static function with(string $method, array $expected): array {
		$names = self::RELEASE[$method];
		$first = ($expected[$names[0]] ?? new IsAnything());
		if (in_array($method, ['deleteObject', 'patchObject'], true) === false) {
			unset($expected[$names[0]]);
		}

		// The access flags are arguments of their own after the option object.
		$flags = [];
		foreach (['_rbac', '_multitenancy'] as $flag) {
			$want = ($expected[$flag] ?? null);
			unset($expected[$flag]);
			$flags[] = ($want === null) ? new IsAnything() : (($want instanceof Constraint) ? $want : new IsEqual($want));
		}

		while ($flags !== [] && end($flags) instanceof IsAnything) {
			array_pop($flags);
		}

		$check = static function (array $actual) use ($names, $expected): bool {
			foreach ($expected as $name => $want) {
				$got = $actual[array_search($name, $names, true)];
				if ($want instanceof Constraint) {
					if ($want->evaluate($got, '', true) !== true) {
						return false;
					}

					continue;
				}

				if ($got != $want) {
					return false;
				}
			}

			return true;
		};

		if (in_array($method, ['deleteObject', 'patchObject'], true) === true) {
			return array_merge([new Callback(static fn (mixed $request): bool => $check(self::positional(method: $method, args: [$request])))], $flags);
		}

		return array_merge(
			[
				($first instanceof Constraint) ? $first : new IsEqual($first),
				new Callback(static fn (mixed $options): bool => $check(self::positional(method: $method, args: [null, $options]))),
			],
			$flags
		);
	}//end with()

	/**
	 * A method's parameters, the release's for the object-service methods whose signature changed.
	 *
	 * A recorder that names a mock's arguments by reflection keeps the names it was written against.
	 *
	 * @param string $class  The mocked class.
	 * @param string $method The method.
	 *
	 * @return array<int, \ReflectionParameter> The parameters.
	 */
	public static function parameters(string $class, string $method): array {
		if (isset(self::RELEASE[$method]) === true && preg_match('/ObjectService(Interface)?$/', $class) === 1) {
			return (new \ReflectionMethod(ReleaseObjectSignatures::class, $method))->getParameters();
		}

		return (new \ReflectionMethod($class, $method))->getParameters();
	}//end parameters()

	/**
	 * Named arguments in the release's parameter order, others after.
	 *
	 * @param string               $method The object-service method.
	 * @param array<string, mixed> $named  The named arguments.
	 *
	 * @return array<string, mixed> The same arguments, reordered.
	 */
	public static function ordered(string $method, array $named): array {
		if (isset(self::RELEASE[$method]) === false) {
			return $named;
		}

		$order = array_flip(self::RELEASE[$method]);
		uksort($named, static fn (int|string $a, int|string $b): int => (($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX)));
		return $named;
	}//end ordered()

	/**
	 * The named values an option object carries, or null when the value is not one.
	 *
	 * @param mixed $value An argument.
	 *
	 * @return array<string, mixed>|null The named values.
	 */
	private static function named(mixed $value): ?array {
		if ($value instanceof ObjectFindOptions) {
			return self::set(values: [
				'register' => [$value->register, null],
				'schema' => [$value->schema, null],
				'_extend' => [$value->extend, []],
				'files' => [$value->files, false],
				'_render' => [$value->render, true],
				'_audit' => [$value->audit, true],
			]);
		}

		if ($value instanceof ObjectSaveOptions) {
			return self::set(values: [
				'register' => [$value->target->register, null],
				'schema' => [$value->target->schema, null],
				'uuid' => [$value->uuid, null],
				'extend' => [$value->extend, []],
				'uploadedFiles' => [$value->uploadedFiles, null],
				'silent' => [$value->policy->silent, false],
				'_validation' => [$value->policy->validation, true],
				'failIfExists' => [$value->policy->failIfExists, false],
				'_dedupOverride' => [$value->policy->dedupOverride, false],
				'currentUser' => [$value->actor->currentUser, null],
				'_owner' => [$value->actor->owner, null],
				'_unowned' => [$value->actor->unowned, false],
			]);
		}

		if ($value instanceof ObjectDeletion) {
			return ['uuid' => $value->uuid] + self::set(values: [
				'register' => [$value->target->register, null],
				'schema' => [$value->target->schema, null],
				'currentUser' => [$value->currentUser, null],
				'permanent' => [$value->permanent, false],
			]);
		}

		if ($value instanceof ObjectPatch) {
			return ['objectId' => $value->objectId, 'data' => $value->data] + self::set(values: [
				'register' => [$value->target->register, null],
				'schema' => [$value->target->schema, null],
				'currentUser' => [$value->currentUser, null],
			]);
		}

		if ($value instanceof LockRequest) {
			return ['identifier' => $value->identifier] + self::set(values: [
				'process' => [$value->process, null],
				'duration' => [$value->duration, null],
				'runUuid' => [$value->runUuid, null],
				'nodeId' => [$value->nodeId, null],
			]);
		}

		if ($value instanceof UnlockRequest) {
			return ['identifier' => $value->identifier] + self::set(values: [
				'advisory' => [$value->advisory, false],
				'runUuid' => [$value->runUuid, null],
			]);
		}

		if ($value instanceof BulkSaveOptions) {
			return self::set(values: [
				'register' => [$value->defaults->register, null],
				'schema' => [$value->defaults->schema, null],
				'validation' => [$value->validation, false],
				'events' => [$value->events, false],
				'_audit' => [$value->audit, true],
			]);
		}

		return null;
	}//end named()

	/**
	 * The values that differ from their default.
	 *
	 * @param array<string, array{0: mixed, 1: mixed}> $values Each name with its value and default.
	 *
	 * @return array<string, mixed> The values set away from their default.
	 */
	private static function set(array $values): array {
		$set = [];
		foreach ($values as $name => [$value, $default]) {
			if ($value !== $default) {
				$set[$name] = $value;
			}
		}

		return $set;
	}//end set()
}//end class
