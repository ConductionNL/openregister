<?php

/**
 * LifecycleConditionEvaluatorInterface: what callers outside the component use of LifecycleConditionEvaluator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * Judge the conditions on a lifecycle transition.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface LifecycleConditionEvaluatorInterface {

	/**
	 * Build the document a condition is evaluated against.
	 *
	 * Exactly four keys, and deliberately NOT the flow engine's `json` /
	 * `binary` / `itemIndex` shape: a schema author writing a lifecycle rule is
	 * looking at an object and a transition, not at a flow item. The expression
	 * language is shared with flows; the document it reads is not.
	 *
	 * `user` is empty under `occ`, which has no session. A condition reading
	 * `user.uid` therefore refuses on the CLI unless it allows for that.
	 *
	 * PUBLIC so that the rule run log can trace a refusal against the SAME
	 * document the refusal was decided on. A tracer that rebuilt the document
	 * itself would be a second opinion about what the condition read, and would
	 * drift the first time this shape changes.
	 *
	 * @param array<string, mixed> $newData The object as it would be saved.
	 * @param array<string, mixed> $oldData The object as currently stored.
	 * @param string $action The matched transition's name.
	 * @param string $from The lifecycle value being moved away from.
	 * @param string $to The lifecycle value being moved to.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function document(array $newData, array $oldData, string $action, string $from, string $to): array;

	/**
	 * The refusal for a transition, or null when its condition lets it through.
	 *
	 * A transition declaring no condition always yields null.
	 *
	 * @param array<string, mixed> $spec The matched transition's spec.
	 * @param array<string, mixed> $newData The object as it would be saved.
	 * @param array<string, mixed> $oldData The object as currently stored.
	 * @param string $action The matched transition's name.
	 * @param string $from The lifecycle value being moved away from.
	 * @param string $to The lifecycle value being moved to.
	 * @param string $schemaSlug The schema's slug, for the log line.
	 * @param string $field The lifecycle field's name.
	 *
	 * @return array{code: string, field: string, action: string, message: string}|null
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function refusal(
		array $spec,
		array $newData,
		array $oldData,
		string $action,
		string $from,
		string $to,
		string $schemaSlug,
		string $field,
	): ?array;
}//end interface
