<?php

/**
 * The node-rights questions the flow controllers ask.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\IUser;

/**
 * Which right a step needs, and whether an author may save a flow holding it.
 */
interface FlowNodeRightsGuardInterface {

	/**
	 * The right a step of this type and configuration needs, or null.
	 *
	 * @param string               $type   The step type.
	 * @param array<string, mixed> $config The step's configuration.
	 *
	 * @return string|null The right, or null when it needs none.
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function rightFor(string $type, array $config): ?string;

	/**
	 * Refuse unless the user holds the right of every step in the new and the stored document.
	 *
	 * @param IUser                     $user     The author.
	 * @param array<string, mixed>      $document The document being saved (`nodes`, `edges`).
	 * @param array<string, mixed>|null $stored   The stored document of the flow being changed, or null.
	 *
	 * @return void
	 *
	 * @throws FlowNodeRightRefused For the first step whose right the user lacks.
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-with-a-restricted-step-is-saved-only-by-someone-who-holds-its-right
	 */
	public function assertMayAuthor(IUser $user, array $document, ?array $stored=null): void;

	/**
	 * The palette with each entry's `requiresRight` and, for this user, `locked`.
	 *
	 * @param IUser                            $user    The caller.
	 * @param array<int, array<string, mixed>> $palette The registry's palette.
	 *
	 * @return array<int, array<string, mixed>> The palette.
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function annotatePalette(IUser $user, array $palette): array;

	/**
	 * Every node right in use, with its description and the node types that require it.
	 *
	 * @return array<string, array{description: string, nodeTypes: array<int, string>}> Keyed by right.
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function nodeRights(): array;

	/**
	 * The administrator's map of node type to right (null lifts a node's own right).
	 *
	 * @return array<string, string|null>
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function adminMap(): array;

	/**
	 * Store the administrator's map of node type to right.
	 *
	 * The caller MUST have checked that the user is an administrator.
	 *
	 * @param array<string, string|null> $map The map.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function setAdminMap(array $map): void;
}//end interface
