<?php

/**
 * PermissionExplanationInterface: Explains which verbs a caller holds on a row and which rule decided each.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * Permitted actions, provenance and the deny rules of PermissionHandler.
 *
 * Callers that need only this role depend on it, not on the whole handler.
 */
interface PermissionExplanationInterface {

	/**
	 * The deny rule that is allowed to change this answer, or null.
	 *
	 * The ENFORCED verdict, which is the pure resolution in
	 * {@see denialFor()} read through the switch in
	 * {@see \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode}. Every
	 * enforcement point calls this one and none of them reads the switch itself,
	 * so the object read and the list query cannot end up in different modes
	 * inside one request.
	 *
	 * THE THREE MODES, in the order they cost anything:
	 *
	 *  - `off`       nothing is resolved and nothing is recorded.
	 *  - `staging`   the denial is resolved, recorded and dropped. The default.
	 *  - `enforcing` the denial is resolved and returned.
	 *
	 * The administrator exemption and the precedence rule live one method down,
	 * in the resolution itself, because they are properties of the deny and not
	 * of the rollout.
	 *
	 * @param array|null           $authorization The already-cascaded authorization block.
	 * @param string               $action        The verb being decided.
	 * @param string|null          $userId        The caller, or null to resolve from the session.
	 * @param ObjectEntity|null    $object        The row under evaluation, when there is one.
	 * @param array<string, mixed> $context       Where this happened, for the staging record.
	 *
	 * @return array{rule: mixed, principal: string, action: string, conditional: bool}|null
	 *         The denial that decides it, or null when none reaches this caller
	 *         or the instance is not enforcing yet.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function enforcedDenialFor(
		?array $authorization,
		string $action,
		?string $userId = null,
		?ObjectEntity $object = null,
		array $context = [],
	): ?array;

	/**
	 * The verbs this caller may exercise on one row.
	 *
	 * Resolved through {@see hasPermission()}, verb by verb, which is the same
	 * decision the read itself made and is memoised per request on
	 * `(user, schema, action, owner, uuid)`. So a record returned with its
	 * actions costs the resolutions the client would have had to provoke
	 * anyway, and the client stops guessing: today it either renders a delete
	 * button nobody may press, or hides one somebody may (design D-9).
	 *
	 * THE SCHEMA VERBS ARE NOT IN THE LIST. `create`, `list` and `manage` are
	 * answers about the schema and the register, not about this row, and a
	 * record that carried them would invite a client to read them as rights ON
	 * the row. The row verbs are `read`, `update`, `delete`, `destroy` and every
	 * custom verb an app declared, because those are the ones an object screen
	 * offers.
	 *
	 * @param Schema            $schema The schema the row belongs to.
	 * @param ObjectEntity|null $object The row, when the question is about one.
	 * @param string|null       $userId The caller, or null to resolve from the session.
	 *
	 * @return array<int, string> The verbs, in catalogue order.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function permittedActionsFor(
		Schema $schema,
		?ObjectEntity $object = null,
		?string $userId = null,
	): array;

	/**
	 * Why this caller may or may not do each of these verbs.
	 *
	 * The answer per action names the rule that decided it: the object's own
	 * block, the schema rule, the register default, the named role, or the deny
	 * that removed it. A security officer asking "why can this person update
	 * this dossier" got a yes before this existed, which answers a different
	 * question, and an absence had no reason at all.
	 *
	 * THE COST IS A FIELD, NOT A PASS. The cascade is resolved once here, the
	 * same resolution the verdict uses, and the levels are then read back for
	 * the naming. Nothing is evaluated twice (ADR-009).
	 *
	 * STAGED DENIES RIDE ALONG. Below `enforcing` the grant stands and the deny
	 * that would remove it is reported as `stagedDeny`, so the field that says
	 * why a person may act also says what stops them the day the switch moves.
	 *
	 * @param Schema             $schema  The schema being reported on.
	 * @param array<int, string> $actions The verbs to report.
	 * @param string|null        $userId  The caller, or null to resolve from the session.
	 * @param ObjectEntity|null  $object  The row, when the question is about one.
	 *
	 * @return array<string, array<string, mixed>> The provenance, keyed by action.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function provenanceFor(Schema $schema, array $actions, ?string $userId = null, ?ObjectEntity $object = null): array;

	/**
	 * The deny rule that removes this verb from this caller, or null.
	 *
	 * The PURE resolution, with no reference to the enforcement switch. It
	 * answers "which rule denies this", which is the question provenance asks in
	 * every mode, including `staging` where the answer is reported and not
	 * applied. Callers that want the enforced verdict call
	 * {@see enforcedDenialFor()} instead.
	 *
	 * This is the whole of the negative half of the model, and it is one method
	 * so that the object read, the relation-path check and both list emitters
	 * cannot honour different halves of it.
	 *
	 * PRECEDENCE. A deny is checked before every grant, including the owner
	 * bypass and a grant inherited from an ancestor object. Deny wins; that is
	 * the only rule that keeps the answer predictable, and letting a more
	 * specific grant put the verb back is how a deny quietly stops working.
	 *
	 * THE ADMINISTRATOR IS EXEMPT, on purpose and in one place. A deny that
	 * reached the `admin` group could remove the right to edit the rules that
	 * removed it, and an instance in that state is recoverable only from the
	 * database. The save-time refusal in {@see
	 * \OCA\OpenRegister\Service\Rbac\AuthorizationDenyValidator} guards the
	 * register's own `manage` verb; this guards the platform administrator.
	 *
	 * A CONDITIONAL DENY NEEDS A ROW. An entry carrying a `match` clause removes
	 * the verb only for the objects the clause selects, so with no object in
	 * hand it denies nothing: the caller is asking about the schema, not about a
	 * row, and refusing there would turn a row-scoped rule into a blanket one.
	 *
	 * @param array|null        $authorization The already-cascaded authorization block.
	 * @param string            $action        The verb being decided.
	 * @param string|null       $userId        The caller, or null to resolve from the session.
	 * @param ObjectEntity|null $object        The row under evaluation, when there is one.
	 *
	 * @return array{rule: mixed, principal: string, action: string, conditional: bool}|null
	 *         The denial that decided it, or null when no deny reaches this caller.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function denialFor(?array $authorization, string $action, ?string $userId = null, ?ObjectEntity $object = null): ?array;
}//end interface
