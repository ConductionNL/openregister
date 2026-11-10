<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\Support;

use Closure;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * Reads a SaveObject::saveObject() invocation as the fifteen values it is
 * made of, in the order register, schema, data, uuid, _rbac, _multitenancy,
 * persist, silent, validation, uploadedFiles, currentUser, failIfExists,
 * unowned, dedupOverride, owner, so recorded calls and goldens keep one shape.
 */
final class SaveObjectArgs {

	/**
	 * The names of the fifteen values, in their order.
	 *
	 * @var list<string>
	 */
	public const NAMES = [
		'register', 'schema', 'data', 'uuid', '_rbac', '_multitenancy', 'persist', 'silent', '_validation',
		'uploadedFiles', 'currentUser', 'failIfExists', '_unowned', '_dedupOverride', '_owner',
	];

	/**
	 * The fifteen values of one invocation.
	 *
	 * @param array<int, mixed> $args The invocation's arguments: data, options and the trailing flags.
	 *
	 * @return array<int, mixed> The values in the order above.
	 */
	public static function flat(array $args): array {
		$options = $args[1] ?? new ObjectSaveOptions();
		\assert($options instanceof ObjectSaveOptions);

		return [
			$options->target->register,
			$options->target->schema,
			$args[0],
			$options->uuid,
			$args[2] ?? true,
			$args[3] ?? true,
			$args[4] ?? true,
			$options->policy->silent,
			$options->policy->validation,
			$options->uploadedFiles,
			$options->actor->currentUser,
			$options->policy->failIfExists,
			$options->actor->unowned,
			$options->policy->dedupOverride,
			$options->actor->owner,
		];
	}//end flat()

	/**
	 * A mock callback for saveObject() that receives the fifteen values.
	 *
	 * @param callable $callback Takes register, schema, data, uuid and the rest, in the order above.
	 *
	 * @return Closure The callback to hand willReturnCallback().
	 */
	public static function callback(callable $callback): Closure {
		return static fn (mixed ...$args): mixed => $callback(...self::flat(args: $args));
	}//end callback()
}//end class
