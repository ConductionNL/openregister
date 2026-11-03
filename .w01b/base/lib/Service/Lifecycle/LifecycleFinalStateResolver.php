<?php

/**
 * Whether a lifecycle value is an end, when the value is a reference.
 *
 * 🔴 A STATIC LIST OF FINAL STATES CANNOT DESCRIBE A LIFECYCLE WHOSE STATES ARE
 * ROWS. When a schema's lifecycle field is a `$ref`, the value an object carries
 * is the uuid of a row in another schema, and every tenant has its own rows. A
 * `final` list written in the schema would have to name uuids that do not exist
 * yet, so nothing can be declared and nothing is ever terminal. That is how an
 * object could close without anything noticing: no error, no log, no nomination.
 *
 * 🔴 SO `final` ALSO TAKES THE REFERENCE FORM `initial` ALREADY TAKES:
 * `{ "from": "<schema>", "field": "<property>" }`. The referenced row is read
 * and the named property answers the question. The row is configuration, not
 * user content, so it is read as the system: terminality must not depend on who
 * happened to make the transition, or the same closure would nominate for one
 * user and silently not for another.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the referenced row behind a lifecycle value and answers whether it ends.
 *
 * @psalm-suppress UnusedClass
 */
class LifecycleFinalStateResolver {

	/**
	 * Answers already read this request, keyed by declaration and state.
	 *
	 * A transition sweep asks the same question for every object in the same
	 * state, and the answer is a row that cannot change inside one request.
	 *
	 * @var array<string, bool>
	 */
	private array $memo = [];

	/**
	 * Constructor.
	 *
	 * The declaration reader is defaulted rather than required because it is a
	 * pure shape check with no collaborators; the parameter exists so a test
	 * can substitute one.
	 *
	 * @param MagicMapper                $objects     Loads the referenced row.
	 * @param LoggerInterface            $logger      Where an unresolvable reference is reported.
	 * @param LifecycleDeclarationForms  $declaration Tells the two forms of `final` apart.
	 */
	public function __construct(
		private readonly MagicMapper $objects,
		private readonly LoggerInterface $logger,
		private readonly LifecycleDeclarationForms $declaration = new LifecycleDeclarationForms(),
	) {
	}//end __construct()

	/**
	 * Is this value the reference form of `final` rather than a list of states?
	 *
	 * Delegates to {@see LifecycleDeclarationForms}, which owns the shape rule
	 * for every reader of `final`. Kept here so a caller that already holds the
	 * resolver does not have to wire a second collaborator to ask.
	 *
	 * @param mixed $final The declared `final` value.
	 *
	 * @return bool True when the value is `{ from, field }`.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md
	 */
	public function isReferenceForm(mixed $final): bool {
		return $this->declaration->isReferenceForm($final);
	}//end isReferenceForm()

	/**
	 * Does the row this state points at say the lifecycle ends here?
	 *
	 * Unresolvable is NOT terminal, and it is said out loud. Guessing yes would
	 * nominate a live dossier for destruction; guessing no in silence is the
	 * failure this whole file exists to end, so it is logged.
	 *
	 * @param array<string, mixed> $declaration The `{ from, field }` block off `final`.
	 * @param string               $state       The lifecycle value the object reached.
	 *
	 * @return bool True when the referenced row's named property is true.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md
	 */
	public function isFinalByReference(array $declaration, string $state): bool {
		$from = trim((string)($declaration['from'] ?? ''));
		$field = trim((string)($declaration['field'] ?? ''));
		if ($from === '' || $field === '' || trim($state) === '') {
			return false;
		}

		$key = $from . '|' . $field . '|' . $state;
		if (array_key_exists($key, $this->memo) === true) {
			return $this->memo[$key];
		}

		$this->memo[$key] = $this->read(from: $from, field: $field, state: $state);

		return $this->memo[$key];
	}//end isFinalByReference()

	/**
	 * Read the named property off the referenced row.
	 *
	 * @param string $from  The schema the referenced row belongs to.
	 * @param string $field The property on that row that says whether it ends.
	 * @param string $state The lifecycle value, which identifies the row.
	 *
	 * @return bool True when the property is true.
	 */
	private function read(string $from, string $field, string $state): bool {
		try {
			// Read as the system: see the file header for why terminality must
			// not depend on the actor who made the transition.
			$resolved = $this->objects->findAcrossAllSources(
				identifier: $state,
				includeDeleted: false,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $error) {
			$this->logger->warning(
				'[LifecycleFinalStateResolver] lifecycle value "' . $state
				. '" resolves to no ' . $from . ' row, so nothing can say whether it is an end: '
				. $error->getMessage()
			);

			return false;
		}

		if ($this->belongsTo(schema: ($resolved['schema'] ?? null), from: $from) === false) {
			$this->logger->warning(
				'[LifecycleFinalStateResolver] lifecycle value "' . $state
				. '" resolves to a row outside the declared schema "' . $from . '".'
			);

			return false;
		}

		$data = ($resolved['object']->getObject() ?? []);
		if (is_array($data) === false || array_key_exists($field, $data) === false) {
			$this->logger->warning(
				'[LifecycleFinalStateResolver] the ' . $from . ' row behind lifecycle value "'
				. $state . '" declares no "' . $field . '" property.'
			);

			return false;
		}

		return $this->truthy(value: $data[$field]);
	}//end read()

	/**
	 * Is the resolved row's schema the one the annotation named?
	 *
	 * Checked rather than assumed: the lookup is by identifier across every
	 * magic table, so without this a uuid that happens to name a row in an
	 * unrelated schema could answer for a status.
	 *
	 * @param mixed  $schema The schema the lookup resolved, if any.
	 * @param string $from   The schema the annotation named.
	 *
	 * @return bool True when they are the same schema.
	 */
	private function belongsTo(mixed $schema, string $from): bool {
		if (($schema instanceof Schema) === false) {
			return false;
		}

		$needle = strtolower($from);
		foreach ([$schema->getSlug(), $schema->getUuid(), $schema->getTitle(), (string)$schema->getId()] as $candidate) {
			if ($candidate !== null && strtolower((string)$candidate) === $needle) {
				return true;
			}
		}

		return false;
	}//end belongsTo()

	/**
	 * Read a stored boolean that may have arrived as a string or a number.
	 *
	 * JSON storage and form posts both flatten booleans, so `"true"` and `1`
	 * have to mean what `true` means. `"false"` is the one string that looks
	 * true to PHP and is not, which is why this is not a bare cast.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return bool What it means.
	 */
	private function truthy(mixed $value): bool {
		if (is_bool($value) === true) {
			return $value;
		}

		if (is_string($value) === true) {
			return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'ja'], true);
		}

		if (is_int($value) === true || is_float($value) === true) {
			return ((int)$value) === 1;
		}

		return false;
	}//end truthy()
}//end class
