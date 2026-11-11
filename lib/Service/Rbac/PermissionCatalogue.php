<?php

/**
 * The set of permissions that can be granted on this instance.
 *
 * WHY THIS EXISTS. The five canonical verbs are in the spec, `manage` is in the
 * spec, and a custom verb existed only as a vote at evaluation time. Nothing
 * could enumerate them. So an administrator opening a role editor had nothing to
 * offer, which is why every consumer in the fleet invented its own vocabulary:
 * dossiq scopes a mandate by untyped `decisionTypes` and `caseTypes` strings and
 * publishes exactly two role names, because the layer under it never published a
 * set to name.
 *
 * A set nobody can read cannot be granted. This class is the read.
 *
 * TWO HALVES, deliberately separate (design D-2):
 *
 *  - DECLARATION, here, through {@see PermissionsDeclaringEvent}. It makes a
 *    verb offerable and auditable.
 *  - EVALUATION, unchanged, through `CustomScopeEvaluatingEvent`. First vote
 *    wins, and it still decides.
 *
 * A verb that is not in the catalogue cannot be named in an authorization block
 * or in a role's `actions` array. That refusal is the point of publishing the
 * set: a typo in a verb used to deny silently for a year, because an unknown
 * verb simply never matched anything and the block looked fine.
 *
 * FAIL-CLOSED. A declaration the grammar cannot address is refused rather than
 * accepted, because a verb an administrator can grant and never deny is worse
 * than a verb that does not exist. A verb declared by two apps is refused for
 * both, because otherwise listener order decides who owns `publish` and an
 * administrator cannot tell which app they granted.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Event\PermissionsDeclaringEvent;
use OCA\OpenRegister\Exception\AuthorizationBlockException;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Publishes the grantable permission set, and refuses a verb that is not in it.
 *
 * @SuppressWarnings(PHPMD.UnusedPrivateField)
 * Reason: a false positive of the whole-tree run, not of the code. Every private
 *         field here is written and read through `$this->field[...]`, and phpmd
 *         run against this one file reports nothing (exit 0). The same sniff
 *         fires on about forty untouched files in this repo for the same
 *         reason, which is why `composer phpmd` is red on `development` too.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
class PermissionCatalogue implements PermissionCatalogueInterface {

	/**
	 * The resolved catalogue for this request, or null before it is built.
	 *
	 * Memoised because the declaration event is dispatched to every installed
	 * app, and the answer cannot change inside one request.
	 *
	 * @var array<string, array{verb: string, app: string, description: string, levels: array<int, string>, canonical: bool}>|null
	 */
	private ?array $resolved = null;

	/**
	 * Declarations that were refused while building the catalogue.
	 *
	 * @var array<string, string>
	 */
	private array $rejected = [];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher|null $eventDispatcher Dispatcher for the declaration
	 *                                               event; null yields the canonical
	 *                                               verbs alone, which is the correct
	 *                                               answer for a caller with no app
	 *                                               context rather than an error.
	 */
	public function __construct(
		private readonly ?IEventDispatcher $eventDispatcher = null,
	) {
	}//end __construct()

	/**
	 * Every grantable permission, keyed by verb.
	 *
	 * @return array<string, array{verb: string, app: string, description: string, levels: array<int, string>, canonical: bool}>
	 *         The catalogue.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function all(): array {
		if ($this->resolved !== null) {
			return $this->resolved;
		}

		$catalogue = self::canonicalEntries();
		foreach ($this->collectDeclarations() as $verb => $declaration) {
			// A canonical verb is never redeclarable. An app that shadowed
			// `delete` with its own meaning would change what an existing grant
			// does without any block being edited.
			if (isset($catalogue[$verb]) === true) {
				$this->rejectRedeclaration(verb: $verb, declaration: $declaration);
				continue;
			}

			$catalogue[$verb] = array_merge($declaration, ['canonical' => false]);
		}

		$this->resolved = $catalogue;

		return $catalogue;
	}//end all()

	/**
	 * The canonical verbs as catalogue entries, owned by this app at every level.
	 *
	 * @return array<string, array{verb: string, app: string, description: string, levels: array<int, string>, canonical: bool}>
	 */
	private static function canonicalEntries(): array {
		$catalogue = [];
		foreach (self::CANONICAL as $verb => $description) {
			$catalogue[$verb] = [
				'verb' => $verb,
				'app' => self::CORE_APP,
				'description' => $description,
				'levels' => PermissionsDeclaringEvent::DEFAULT_LEVELS,
				'canonical' => true,
			];
		}

		return $catalogue;
	}//end canonicalEntries()

	/**
	 * Record why an app's declaration of a canonical verb was refused.
	 *
	 * @param string               $verb        The canonical verb.
	 * @param array<string, mixed> $declaration The refused declaration.
	 *
	 * @return void
	 */
	private function rejectRedeclaration(string $verb, array $declaration): void {
		$this->rejected[$verb] = sprintf(
			'The app "%s" declared "%s", which is a canonical verb and cannot be redefined.',
			$declaration['app'],
			$verb
		);
	}//end rejectRedeclaration()

	/**
	 * The declarations refused while the catalogue was built.
	 *
	 * Reported rather than dropped, so an app whose verb is missing learns why
	 * instead of finding an empty selector.
	 *
	 * @return array<string, string> Verb to reason.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function rejectedDeclarations(): array {
		// Building the catalogue is what fills this, so build it first.
		$this->all();

		return $this->rejected;
	}//end rejectedDeclarations()

	/**
	 * The verbs, as a flat list.
	 *
	 * @return array<int, string> Every grantable verb.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function verbs(): array {
		return array_keys($this->all());
	}//end verbs()

	/**
	 * Whether one verb may be granted here.
	 *
	 * @param string $verb The verb.
	 *
	 * @return bool True when the catalogue names it.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function isGrantable(string $verb): bool {
		return isset($this->all()[$verb]);
	}//end isGrantable()

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
	public function declarationFor(string $verb): ?array {
		return ($this->all()[$verb] ?? null);
	}//end declarationFor()

	/**
	 * The verbs an authorization block names that the catalogue does not.
	 *
	 * Walks the block's own action keys and, one level down, the deny block's,
	 * because a deny uses the same grammar and a denied verb nobody declared is
	 * the same mistake in the other direction. Control keys are skipped by name.
	 *
	 * @param array|null $authorization The block as written.
	 *
	 * @return array<int, string> The unknown verbs, in the order they appear.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function unknownVerbsIn(?array $authorization): array {
		if (is_array($authorization) === false || $authorization === []) {
			return [];
		}

		$unknown = $this->unknownKeysOf(authorization: $authorization);

		$deny = ($authorization[DenyResolverInterface::DENY_KEY] ?? null);
		if (is_array($deny) === true) {
			$unknown += array_fill_keys($this->unknownVerbsIn(authorization: $deny), true);
		}

		return array_keys($unknown);
	}//end unknownVerbsIn()

	/**
	 * The top-level keys of a block that are neither a control key nor a grantable verb.
	 *
	 * @param array $authorization The block as written.
	 *
	 * @return array<string, true> The unknown verbs as keys, in the order they appear.
	 */
	private function unknownKeysOf(array $authorization): array {
		$unknown = [];
		foreach (array_keys($authorization) as $key) {
			if (is_string($key) === true && in_array($key, self::CONTROL_KEYS, true) === false
				&& $this->isGrantable(verb: $key) === false
			) {
				$unknown[$key] = true;
			}
		}

		return $unknown;
	}//end unknownKeysOf()

	/**
	 * The verbs a set of role definitions names that the catalogue does not.
	 *
	 * A register's `configuration.roles` is a list of definitions, each with a
	 * `name` and an `actions` array. This is where a typo used to survive
	 * longest: an unknown action in a role simply granted nothing, and the role
	 * looked correctly configured in every screen that showed it.
	 *
	 * @param mixed $roleDefinitions The register's role definitions.
	 *
	 * @return array<string, array<int, string>> Role name to its unknown verbs.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function unknownActionsInRoles(mixed $roleDefinitions): array {
		if (is_array($roleDefinitions) === false) {
			return [];
		}

		$findings = [];
		foreach ($roleDefinitions as $index => $definition) {
			if (is_array($definition) === false) {
				continue;
			}

			$name = (string)($definition['name'] ?? $index);
			$actions = ($definition['actions'] ?? null);
			if (is_array($actions) === false) {
				continue;
			}

			$unknown = [];
			foreach ($actions as $action) {
				if (is_string($action) === true && $this->isGrantable(verb: $action) === false) {
					$unknown[] = $action;
				}
			}

			if ($unknown !== []) {
				$findings[$name] = $unknown;
			}
		}

		return $findings;
	}//end unknownActionsInRoles()

	/**
	 * Refuse a block or a role set naming a verb nobody declared.
	 *
	 * Every finding is collected before anything is thrown. A validator that
	 * stopped at the first unknown verb would turn one bad paste into five round
	 * trips.
	 *
	 * @param GrantDeclaration $declaration The block and role definitions as written, and what they belong to.
	 *
	 * @return void
	 *
	 * @throws AuthorizationBlockException When a verb is not in the catalogue.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function assertGrantable(GrantDeclaration $declaration): void {
		$this->refuseAny(
			findings: array_merge(
				$this->verbFindings(authorization: $declaration->authorization, subject: $declaration->subject),
				$this->roleFindings(roleDefinitions: $declaration->roleDefinitions, subject: $declaration->subject)
			)
		);
	}//end assertGrantable()

	/**
	 * Throw every finding at once, when there is any.
	 *
	 * @param string[] $findings The findings.
	 *
	 * @return void
	 *
	 * @throws AuthorizationBlockException When there is at least one finding.
	 */
	private function refuseAny(array $findings): void {
		if ($findings !== []) {
			throw new AuthorizationBlockException(message: implode(' ', $findings));
		}
	}//end refuseAny()

	/**
	 * The finding for the verbs a block names that nobody declared, if any.
	 *
	 * @param array|null $authorization The block as written.
	 * @param string     $subject       What this belongs to, for the message.
	 *
	 * @return string[] At most one finding.
	 */
	private function verbFindings(?array $authorization, string $subject): array {
		$unknown = $this->unknownVerbsIn(authorization: $authorization);
		if ($unknown === []) {
			return [];
		}

		return [
			sprintf(
				'%s names %s, which no app declares. Grantable here: %s.',
				ucfirst($subject),
				$this->quoteList(values: $unknown),
				$this->quoteList(values: $this->verbs())
			),
		];
	}//end verbFindings()

	/**
	 * One finding per role that names a verb nobody declared.
	 *
	 * @param mixed  $roleDefinitions The register's role definitions, when there are any.
	 * @param string $subject         What this belongs to, for the message.
	 *
	 * @return string[] The findings.
	 */
	private function roleFindings(mixed $roleDefinitions, string $subject): array {
		$findings = [];
		foreach ($this->unknownActionsInRoles(roleDefinitions: $roleDefinitions) as $role => $verbs) {
			$findings[] = sprintf(
				'The role "%s" on %s names %s, which no app declares.',
				$role,
				$subject,
				$this->quoteList(values: $verbs)
			);
		}

		return $findings;
	}//end roleFindings()

	/**
	 * Dispatch the declaration event and take what the apps wrote.
	 *
	 * A dispatcher that throws yields the canonical verbs alone. That is the
	 * fail-closed direction here: a catalogue that is too SMALL refuses a grant
	 * an administrator wanted, loudly and at save time. A catalogue that guessed
	 * at the missing half would accept a verb nothing evaluates.
	 *
	 * @return array<string, array{verb: string, app: string, description: string, levels: array<int, string>}>
	 *         The declarations.
	 */
	private function collectDeclarations(): array {
		if ($this->eventDispatcher === null) {
			return [];
		}

		$event = new PermissionsDeclaringEvent();

		try {
			$this->eventDispatcher->dispatchTyped($event);
		} catch (\Throwable $e) {
			$this->rejected['*'] = sprintf(
				'The declaration round failed, so only the canonical verbs are offered: %s',
				$e->getMessage()
			);
			return [];
		}

		$this->rejected = array_merge($this->rejected, $event->getRejected());

		return $event->getDeclared();
	}//end collectDeclarations()

	/**
	 * Render a list of names for a message.
	 *
	 * @param array<int, string> $values The names.
	 *
	 * @return string The quoted, comma-joined list.
	 */
	private function quoteList(array $values): string {
		return implode(', ', array_map(static fn (string $value): string => sprintf('"%s"', $value), $values));
	}//end quoteList()
}//end class
