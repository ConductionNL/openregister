<?php

/**
 * The action rights of Open Register, described, with their groups.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;

/**
 * Reads and changes Open Register's action matrix as a described list.
 */
interface ActionRightsCatalogueInterface {

	/**
	 * Every action right: its app, description and, for node rights, the node types that need it.
	 *
	 * @return array<int, array{action: string, app: string, description: string, nodeTypes: array<int, string>}>
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function actions(): array;

	/**
	 * Every action right as actions() lists it, with the groups that hold it.
	 *
	 * @return array<int, array{action: string, app: string, description: string, nodeTypes: array<int, string>, groups: array<int, string>}>
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function actionsWithGroups(): array;

	/**
	 * Change the groups of known actions. The caller MUST have checked the user is an administrator.
	 *
	 * @param array<string, array<int, string>> $groups Groups per action; actions left out keep theirs.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException Naming every action that is not a known right, or a malformed group list.
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function setGroups(array $groups): void;
}//end interface
