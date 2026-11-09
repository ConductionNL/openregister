<?php

/**
 * OpenRegister RetentionDestructionInterface
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Object
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-refuses-a-write-and-names-its-holder
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The retention-destruction frame, as the destruction run and the delete guard use it.
 *
 * The run opens the frame around its delete; the guard asks whether one is open.
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-refuses-a-write-and-names-its-holder
 */
interface RetentionDestructionInterface {

	/**
	 * Run a destruction.
	 *
	 * @param callable $operation The delete.
	 *
	 * @return mixed What the delete returns.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-refuses-a-write-and-names-its-holder
	 */
	public function run(callable $operation): mixed;

	/**
	 * Whether a destruction is running.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-refuses-a-write-and-names-its-holder
	 */
	public function isRunning(): bool;
}//end interface
