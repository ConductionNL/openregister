<?php

/**
 * The event an app declares its permission verbs on.
 *
 * `CustomScopeEvaluatingEvent` already lets an app DECIDE a custom verb: it
 * votes, first vote wins, and the answer is final. What no app could do until
 * now is SAY which verbs it has. So a role editor had nothing to offer, and
 * every consumer in the fleet invented its own vocabulary in its own screen,
 * which is how dossiq ended up scoping a mandate by untyped strings.
 *
 * Declaration and evaluation stay separate on purpose (design D-2). This event
 * makes a verb offerable and auditable. The voting event still decides it. An
 * app that declares a verb and registers no listener fails CLOSED, and the
 * refusal names the app that owes the listener, because the alternative is a
 * verb that is offered in an editor, granted by an administrator, and quietly
 * refused for a year.
 *
 * A listener declares like this:
 *
 *   $event->declareVerb(
 *       verb: 'publish',
 *       app: 'opencatalogi',
 *       description: 'Publish an object to the open catalogue.',
 *       levels: ['register', 'schema', 'object']
 *   );
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
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
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Collects the custom permission verbs the installed apps declare.
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */
class PermissionsDeclaringEvent extends Event {

	/**
	 * The scope levels a verb may be granted at, when the declaration says
	 * nothing.
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_LEVELS = ['register', 'schema', 'object'];

	/**
	 * The characters a verb may use.
	 *
	 * The same shape the deny resolver accepts before a verb reaches a JSON
	 * path, so a verb that can be declared can also be denied. A declaration
	 * the catalogue accepted and the resolver could not address would be a verb
	 * an administrator can grant and never take away.
	 *
	 * @var string
	 */
	public const VERB_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

	/**
	 * The declarations collected so far, keyed by verb.
	 *
	 * @var array<string, array{verb: string, app: string, description: string, levels: array<int, string>}>
	 */
	private array $declared = [];

	/**
	 * The declarations that were refused, keyed by verb, with the reason.
	 *
	 * Kept rather than dropped so the catalogue can report a bad declaration
	 * instead of an app silently having no verbs.
	 *
	 * @var array<string, string>
	 */
	private array $rejected = [];

	/**
	 * Declare one verb.
	 *
	 * A verb already declared by another app is REFUSED rather than overwritten.
	 * Two apps that both mean something by `publish` would otherwise take turns
	 * owning it depending on listener order, and an administrator granting it
	 * would not be able to tell which one they had granted.
	 *
	 * @param string             $verb        The verb, matching {@see VERB_PATTERN}.
	 * @param string             $app         The app id that owns it.
	 * @param string             $description One sentence a person can read.
	 * @param array<int, string> $levels      The scope levels it may be granted at.
	 *
	 * @return bool True when the declaration was accepted.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function declareVerb(
		string $verb,
		string $app,
		string $description,
		array $levels = self::DEFAULT_LEVELS,
	): bool {
		if (preg_match(self::VERB_PATTERN, $verb) !== 1) {
			$this->rejected[$verb] = sprintf(
				'The app "%s" declared a verb the grammar does not accept.',
				$app
			);
			return false;
		}

		if (isset($this->declared[$verb]) === true) {
			$this->rejected[$verb] = sprintf(
				'The app "%s" declared the verb "%s", which "%s" already owns.',
				$app,
				$verb,
				$this->declared[$verb]['app']
			);
			return false;
		}

		$this->declared[$verb] = [
			'verb' => $verb,
			'app' => $app,
			'description' => $description,
			'levels' => array_values(array_filter($levels, 'is_string')),
		];

		return true;
	}//end declareVerb()

	/**
	 * Every accepted declaration, keyed by verb.
	 *
	 * @return array<string, array{verb: string, app: string, description: string, levels: array<int, string>}> The declarations.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function getDeclared(): array {
		return $this->declared;
	}//end getDeclared()

	/**
	 * Every refused declaration, keyed by verb, with its reason.
	 *
	 * @return array<string, string> The refusals.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function getRejected(): array {
		return $this->rejected;
	}//end getRejected()
}//end class
