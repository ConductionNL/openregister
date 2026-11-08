<?php

/**
 * JSON-Schema types of PHP reflection types
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Maps PHP parameter and return types to JSON-Schema types for an
 * attributed tool's inferred schemas.
 */
final class ReflectionJsonType {

	/**
	 * Map a (possibly nullable/union) reflection type to a JSON-Schema type.
	 *
	 * @param ReflectionType|null $type The parameter/return reflection type, or null when untyped.
	 *
	 * @return string|list<string>|null A JSON-Schema type, a `[type, 'null']` pair, or null when unconstrained.
	 */
	public static function of(?ReflectionType $type): string|array|null {
		if ($type instanceof ReflectionUnionType) {
			return self::ofUnion(type: $type);
		}

		if ($type instanceof ReflectionNamedType) {
			return self::ofNamed(type: $type);
		}

		// Untyped, a ReflectionIntersectionType or another exotic form — unconstrained.
		return null;
	}//end of()

	/**
	 * Map one PHP named type to its JSON-Schema type, or null when the type
	 * carries no useful schema information (`mixed`, `void`, `never`).
	 *
	 * @param ReflectionNamedType $type The named type to map.
	 *
	 * @return string|null The JSON-Schema type, or null when unconstrained.
	 */
	public static function named(ReflectionNamedType $type): ?string {
		if ($type->isBuiltin() === false) {
			// A class/interface/enum type hint — best-effort "object".
			return 'object';
		}

		return match ($type->getName()) {
			'int' => 'integer',
			'float' => 'number',
			'string' => 'string',
			'bool' => 'boolean',
			'array', 'iterable' => 'array',
			default => null,
		};
	}//end named()

	/**
	 * A named type, as a `[type, 'null']` pair when it allows null.
	 *
	 * @param ReflectionNamedType $type The named type.
	 *
	 * @return string|list<string>|null The JSON-Schema type.
	 */
	private static function ofNamed(ReflectionNamedType $type): string|array|null {
		$jsonType = self::named(type: $type);
		if ($jsonType === null) {
			return null;
		}

		if ($type->allowsNull() === true) {
			return [$jsonType, 'null'];
		}

		return $jsonType;
	}//end ofNamed()

	/**
	 * A union type: its distinct mapped members, `null` last when nullable,
	 * collapsed to a single type when only one remains.
	 *
	 * @param ReflectionUnionType $type The union type.
	 *
	 * @return string|list<string>|null The JSON-Schema type.
	 */
	private static function ofUnion(ReflectionUnionType $type): string|array|null {
		$types = self::memberTypes(type: $type);
		if (count($types) === 0) {
			return null;
		}

		if (count($types) === 1) {
			return $types[0];
		}

		return $types;
	}//end ofUnion()

	/**
	 * The distinct mapped member types of a union, then `null` when it is a member.
	 *
	 * @param ReflectionUnionType $type The union type.
	 *
	 * @return list<string> The member types.
	 */
	private static function memberTypes(ReflectionUnionType $type): array {
		$members = array_map([self::class, 'memberType'], $type->getTypes());
		$types   = array_values(array_unique(array_filter($members, static fn (?string $member): bool => $member !== null && $member !== 'null')));
		if (in_array('null', $members, true) === true) {
			$types[] = 'null';
		}

		return $types;
	}//end memberTypes()

	/**
	 * One union member: `null` for the null type, its JSON-Schema type, or
	 * null when it is not a named type or carries no schema information.
	 *
	 * @param ReflectionType $member The member.
	 *
	 * @return string|null The member type.
	 */
	public static function memberType(ReflectionType $member): ?string {
		if (($member instanceof ReflectionNamedType) === false) {
			return null;
		}

		if ($member->getName() === 'null') {
			return 'null';
		}

		return self::named(type: $member);
	}//end memberType()
}//end class
