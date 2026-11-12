<?php

/**
 * ObjectActionDeclarations: the reads of declared actions that callers outside lib/Service make.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
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

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Flow\MacroActionBinding;

/**
 * Reads the `x-openregister-action` block for callers outside lib/Service.
 *
 * The schema save checks and the object-actions endpoint used the named
 * constructors and static readers of ObjectActionDefinition and
 * MacroActionBinding directly, and built ObjectActionSelection with `new`.
 * Each method here makes exactly that call and hands the result back, typed
 * against ObjectActionDefinitionInterface where a definition comes back, so
 * the callers name none of those classes.
 */
final class ObjectActionDeclarations {

	/**
	 * The shape refusals of the declared actions.
	 *
	 * @param array<string, mixed>      $configuration The schema configuration.
	 * @param array<string, mixed>|null $authorization The schema's authorization block, for `elevate`.
	 *
	 * @return list<string> The refusals, each naming its action; empty when the shape is sound.
	 */
	public static function refusals(array $configuration, ?array $authorization = []): array {
		return ObjectActionDefinition::refusals(configuration: $configuration, authorization: $authorization);
	}//end refusals()

	/**
	 * The refusals of the macro (flow) bindings among the declared actions.
	 *
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return string[] The refusals, empty when the shape is sound.
	 */
	public static function macroRefusals(array $configuration): array {
		return MacroActionBinding::refusals(configuration: $configuration);
	}//end macroRefusals()

	/**
	 * Every declared action, as a definition.
	 *
	 * @param array<string, mixed> $configuration A schema or register configuration.
	 * @param array<string, mixed> $lifecycle     The lifecycle a `transition` must name, or [] to skip that check.
	 *
	 * @return array<string, ObjectActionDefinitionInterface> Action key => definition.
	 */
	public static function parse(array $configuration, array $lifecycle = []): array {
		return ObjectActionDefinition::parse(configuration: $configuration, lifecycle: $lifecycle);
	}//end parse()

	/**
	 * Every declared action entry, as written.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return array<string, array<string, mixed>> Action key => entry.
	 */
	public static function declarations(array $configuration): array {
		return ObjectActionDefinition::declarations(configuration: $configuration);
	}//end declarations()

	/**
	 * A selection of objects of one schema an action runs on.
	 *
	 * @param Schema       $schema    The selection's schema.
	 * @param string       $register  The register as the caller named it.
	 * @param string       $schemaRef The schema as the caller named it.
	 * @param list<string> $ids       The selected object ids.
	 *
	 * @return ObjectActionSelection
	 */
	public static function selection(Schema $schema, string $register, string $schemaRef, array $ids): ObjectActionSelection {
		return new ObjectActionSelection(schema: $schema, register: $register, schemaRef: $schemaRef, ids: $ids);
	}//end selection()
}//end class
