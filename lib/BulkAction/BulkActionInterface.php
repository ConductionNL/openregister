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


/**
 * The contract every bulk action implements.
 *
 * This base declares no `apply()`, so an action written for either published
 * signature loads. Implement {@see StepBulkActionInterface} (one `BulkStep`)
 * for new actions; actions that still take
 * `(ObjectEntity $object, array $parameters, bool $commit, ?IUser $actor = null)`
 * keep working and may say so with {@see LegacyBulkActionInterface}. The job
 * executor calls either form through {@see BulkActionInvoker}.
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
}//end interface
