<?php

/**
 * A bulk action: one named thing an operator can do to many objects at once.
 *
 * An action declares what it needs rather than a controller branching on it
 * (ADR-031). It says whether a justification is required, which guards the
 * engine must enforce before a single object is touched, and what it would do
 * to one object. A leaf app registers its own action on the
 * {@see \OCA\OpenRegister\Event\BulkActionRegistrationEvent} and writes no
 * loop of its own (ADR-022).
 *
 * @category BulkAction
 * @package  OCA\OpenRegister\BulkAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BulkAction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;

/**
 * The contract every bulk action implements.
 */
interface BulkActionInterface {

	/**
	 * Refuse a selection that spans more than one schema version.
	 *
	 * Right for an attribute write, wrong for a redistribution, which is why
	 * the guard belongs to the action and not to the engine (D-6).
	 *
	 * @var string
	 */
	public const GUARD_HOMOGENEITY = 'homogeneity';

	/**
	 * The action's stable id, in `app:action` form.
	 *
	 * @return string The action id.
	 */
	public function getId(): string;

	/**
	 * The label an operator reads.
	 *
	 * @return string The label.
	 */
	public function getLabel(): string;

	/**
	 * One sentence saying what the action does.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string;

	/**
	 * Whether the job may not commit without a written reason (D-7).
	 *
	 * @return bool True when a justification is required.
	 */
	public function requiresJustification(): bool;

	/**
	 * The guards the engine enforces before the first object is touched.
	 *
	 * @return array<int, string> The guard names.
	 */
	public function getGuards(): array;

	/**
	 * Check the parameters before a job is created.
	 *
	 * @param array<string, mixed> $parameters The parameters the caller sent.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the parameters do not make sense.
	 */
	public function validateParameters(array $parameters): void;

	/**
	 * What the action would do, or does, to one object.
	 *
	 * The preview and the commit call this same method, differing only in
	 * `$commit`. A second implementation for the rehearsal would be a promise
	 * rather than a rehearsal (D-1).
	 *
	 * @param ObjectEntity $object The object to act on.
	 * @param array<string, mixed> $parameters The job's parameters.
	 * @param bool $commit False to rehearse, true to write.
	 * @param IUser|null $actor The user the job runs as.
	 *
	 * @return BulkActionResult What happened, or what would happen.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) One executor for the
	 * rehearsal and the commit is the design property of D-1.
	 */
	public function apply(ObjectEntity $object, array $parameters, bool $commit, ?IUser $actor = null): BulkActionResult;
}//end interface
