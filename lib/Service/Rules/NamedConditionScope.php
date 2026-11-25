<?php

/**
 * OpenRegister NamedConditionScope
 *
 * Which named conditions a rule may reference where it is evaluated: the
 * schema's own `x-openregister-conditions`, carried beside the annotation the
 * rule was read from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * Carries a schema's condition library alongside its lifecycle annotation.
 *
 * The lifecycle annotation travels through several value objects before a
 * state's field rule or entry condition is evaluated, and none of them carries
 * the schema. Attaching the library to the annotation once, where the schema is
 * read, lets every one of those evaluation points resolve `$condition` against
 * the SAME library the schema save checked (1.3b), without widening a
 * published signature.
 *
 * The library is always the schema's, never one an annotation declares inside
 * itself: whatever the annotation carried under the key is replaced.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */
final class NamedConditionScope {

	/**
	 * The key the library is carried under, inside the annotation.
	 *
	 * @var string
	 */
	public const KEY = NamedConditionLibrary::ANNOTATION;

	/**
	 * The annotation with the schema's condition library attached.
	 *
	 * @param array<string, mixed> $annotation    The lifecycle annotation.
	 * @param mixed                $configuration The schema's configuration.
	 *
	 * @return array<string, mixed> The annotation, carrying the library.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public static function withLibrary(array $annotation, mixed $configuration): array {
		$annotation[self::KEY] = self::libraryFrom(configuration: $configuration);

		return $annotation;
	}//end withLibrary()

	/**
	 * The schema's condition library, read from its configuration.
	 *
	 * @param mixed $configuration The schema's configuration.
	 *
	 * @return array<string, array{expression: mixed, description: string}> The library; empty when none is declared.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public static function libraryFrom(mixed $configuration): array {
		$declared = null;
		if (is_array($configuration) === true) {
			$declared = ($configuration[NamedConditionLibrary::ANNOTATION] ?? null);
		}

		if (is_array($declared) === false) {
			return [];
		}

		return (new NamedConditionLibrary())->libraryFrom(annotation: $declared);
	}//end libraryFrom()

	/**
	 * The library an annotation carries, or an empty one when none was attached.
	 *
	 * An empty library is not a pass: a `$condition` evaluated against it is
	 * refused as undeclared, so an annotation that reached an evaluation point
	 * without its library fails closed.
	 *
	 * @param array<string, mixed> $annotation The lifecycle annotation.
	 *
	 * @return array<string, mixed> The library.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public static function libraryOf(array $annotation): array {
		$library = ($annotation[self::KEY] ?? []);
		if (is_array($library) === false) {
			return [];
		}

		return $library;
	}//end libraryOf()
}//end class
