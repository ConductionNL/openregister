<?php

/**
 * The contract callers of PermissionCatalogue type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Exception\AuthorizationBlockException;

/**
 * The narrow contract of PermissionCatalogue, bound in ContractBindings.
 *
 * Every method is described on PermissionCatalogue.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
interface PermissionCatalogueInterface {

	/**
	 * The app that owns the canonical verbs.
	 *
	 * @var string
	 */
	public const CORE_APP = 'openregister';

	/**
	 * The canonical verbs, with the sentence each one is offered by.
	 *
	 * `manage` is here rather than beside the five because it is the verb that
	 * edits the rules themselves, and the one a deny may not take from the last
	 * principal holding it.
	 *
	 * `assign` is here because handing work to somebody is neither reading nor
	 * writing (ledger row 13.40), and ADR-010 anticipates exactly this case: a
	 * concept core Nextcloud has no bit for, which enters the GOVERNED
	 * vocabulary rather than becoming a parallel model. It is grantable on its
	 * own and holding `update` does not imply it, which is the whole point:
	 * reassignment was gated on a coordinator check that resolved to isAdmin,
	 * so handing work over was an administrator's right rather than an axis.
	 *
	 * `destroy` is here because `delete-window-and-recorded-destruction` made it
	 * a second, narrower right than `delete`: deleting puts an object in the
	 * trash, where it can come back, and destroying ends it. `PermissionHandlerInterface`
	 * has carried it in its canonical set since that change landed, and
	 * `DestroyRightService` resolves it on every destruction, so a catalogue
	 * without it refused a block naming a verb this instance already enforces
	 * (task 8.5, decision D10).
	 *
	 * `export` is here because reading a record and taking a dataset off the
	 * instance are different acts, and the AVG treats them differently. It is
	 * the verb `ExportRightService` resolves on every export path. While no
	 * administrator has written it into a schema's block, it falls back to that
	 * schema's `read` grant, so an upgraded instance keeps exporting; the
	 * catalogue publishes it so the narrowing can be made at all.
	 *
	 * `readGrants` admits a caller to an object's grants list beyond its owner,
	 * owning group, administrators and `manage` holders. Unlike `export` it has
	 * no fallback: a block that does not write it admits nobody through it,
	 * because an ordinary reader of a record has no business listing the people
	 * on it ({@see AccessReviewGate}).
	 *
	 * @var array<string, string>
	 */
	public const CANONICAL = [
		'read' => 'Open one object and read its contents.',
		'create' => 'Add a new object to the schema.',
		'update' => 'Change an object that already exists.',
		'delete' => 'Remove an object.',
		'destroy' => 'End a deleted object for good, before its recovery window closes.',
		'list' => 'See the objects of a schema as a list, with totals and facets.',
		'export' => 'Take the objects of a schema off this instance as a file.',
		'manage' => 'Change the access rules themselves, including roles and grants.',
		'assign' => 'Hand the work on an object to somebody else.',
		'readFiles' => 'Read the files of an object.',
		'addFiles' => 'Add a file to an object, or change one of its files.',
		'deleteFiles' => 'Delete a file of an object.',
		'readGrants' => 'See who can open an object and where each grant came from.',
	];

	/**
	 * Keys of an authorization block that are settings rather than verbs.
	 *
	 * A block mixes rules and behaviour toggles in one object. Reading a toggle
	 * as a verb would refuse every block that carries one, which is most of
	 * them, so the list is explicit rather than guessed.
	 *
	 * @var array<int, string>
	 */
	public const CONTROL_KEYS = [
		'roles',
		'public',
		'inheritFromPublic',
		ObjectScopeResolverInterface::SCOPE_KEY,
		DenyResolverInterface::DENY_KEY,
		// 🔴 THE MATRIX IS A DECLARATION, NOT A VERB, and leaving it out of this
		// list made the whole of `rbac-department-role-matrix` unreachable: a
		// block carrying `matrix` had that key read as a verb, `isGrantable()`
		// answered no, and `assertGrantable()` refused the schema save with
		// "unknown verb: matrix". Every unit test of that compiler passed,
		// because none of them goes through this check, and its e2e could not
		// be run in the phase that built it. Caught here rather than in
		// production, which is the only reason this comment is short.
		DepartmentMatrixCompiler::KEY,
		// The effective token grant a request carries, written into the block
		// by `TokenGrantNarrower`. Listed here for exactly the reason above:
		// a key in a block that is not a control key is read as a VERB, and
		// every schema whose block had been narrowed would then be refused at
		// save with "unknown verb: x-openregister-token-grant". The defect the
		// comment above records cost a whole change; this is the same shape,
		// and the list is the cure.
		TokenGrantNarrower::MARKER,
		// `audit: true` on a PROPERTY's block asks for every reveal of that
		// value to be recorded (RevealCollector, sensitive-field-reveal-audit).
		// It is a flag, not a verb: read as a verb it refused every schema that
		// declared it with "Invalid authorization action 'audit'", so the
		// feature could be declared nowhere. SchemaMapper::validateRevealAudit()
		// still checks its shape (only on a property with a read rule).
		RevealCollector::AUDIT_KEY,
		// `reveal: explicit` on a PROPERTY's block keeps the value masked until
		// a read asks for it (ExplicitReveal). A flag, like `audit` above;
		// Schema::validateRevealValue() checks its value.
		ExplicitReveal::KEY,
	];

	/**
	 * Every grantable permission, keyed by verb.
	 *
	 * @return array<string, array{verb: string, app: string, description: string, levels: array<int, string>, canonical: bool}>
	 *         The catalogue.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function all(): array;

	/**
	 * The declarations refused while the catalogue was built.
	 *
	 * @return array<string, string> Verb to reason.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function rejectedDeclarations(): array;

	/**
	 * The verbs, as a flat list.
	 *
	 * @return array<int, string> Every grantable verb.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function verbs(): array;

	/**
	 * Whether one verb may be granted here.
	 *
	 * @param string $verb The verb.
	 *
	 * @return bool True when the catalogue names it.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function isGrantable(string $verb): bool;

	/**
	 * The declaration behind one verb, or null when nobody declared it.
	 *
	 * @param string $verb The verb.
	 *
	 * @return array{verb: string, app: string, description: string, levels: array<int, string>, canonical: bool}|null
	 *         The entry, or null.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function declarationFor(string $verb): ?array;

	/**
	 * The verbs an authorization block names that the catalogue does not.
	 *
	 * @param array|null $authorization The block as written.
	 *
	 * @return array<int, string> The unknown verbs, in the order they appear.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function unknownVerbsIn(?array $authorization): array;

	/**
	 * The verbs a set of role definitions names that the catalogue does not.
	 *
	 * @param mixed $roleDefinitions The register's role definitions.
	 *
	 * @return array<string, array<int, string>> Role name to its unknown verbs.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function unknownActionsInRoles(mixed $roleDefinitions): array;

	/**
	 * Refuse a block or a role set naming a verb nobody declared.
	 *
	 * @param array|null $authorization   The block as written.
	 * @param mixed      $roleDefinitions The register's role definitions, when there are any.
	 * @param string     $subject         What this belongs to, for the message.
	 *
	 * @return void
	 * @throws AuthorizationBlockException When a verb is not in the catalogue.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function assertGrantable(?array $authorization, mixed $roleDefinitions, string $subject): void;
}//end interface
