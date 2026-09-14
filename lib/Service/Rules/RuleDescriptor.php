<?php

/**
 * OpenRegister RuleDescriptor
 *
 * One entry of the rule inventory: a rule that can act on a schema's objects,
 * with where it is declared, what it does and whether it is switched on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use JsonSerializable;

/**
 * A projection of one declared rule, never a stored record.
 *
 * Per D-1 the inventory stores nothing of its own: every descriptor is built
 * from a schema annotation or from the flow trigger index on the way out of the
 * read. That is what makes "a rule not in the schema cannot appear, and a rule
 * in the schema cannot be missing" a property of the design rather than a
 * promise about a synchronisation job.
 *
 * The id is derived from the same three facts every time, so a rule keeps its
 * id across reads, across restarts and across the machine it is read on, which
 * is what lets the run log key on it without a registry to allocate ids.
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
final class RuleDescriptor implements JsonSerializable {

	/**
	 * The separator between the three parts of a derived rule id.
	 *
	 * A colon, because it is legal unescaped in a URL path segment and is not
	 * legal in a schema slug or a property name, so the id round-trips through
	 * a route without a rule ever having to be looked up by a second key.
	 *
	 * @var string
	 */
	public const ID_SEPARATOR = ':';

	/**
	 * Constructor.
	 *
	 * @param string $kind One of the RuleVocabulary KIND_ constants.
	 * @param string $schemaSlug The slug of the schema the rule acts on.
	 * @param string $key The rule's own key within its kind.
	 * @param string $label What the rule is called on screen.
	 * @param string $source The annotation path the rule was read from.
	 * @param bool $enabled Whether the rule is switched on.
	 * @param array<int, string> $actions What the rule does when it fires.
	 * @param mixed $condition The rule's condition, as the author declared it.
	 * @param int|null $maxObjects The ceiling the rule declares for one run.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $kind,
		private readonly string $schemaSlug,
		private readonly string $key,
		private readonly string $label,
		private readonly string $source,
		private readonly bool $enabled = true,
		private readonly array $actions = [],
		private readonly mixed $condition = null,
		private readonly ?int $maxObjects = null,
	) {
	}//end __construct()

	/**
	 * The rule's derived, stable id.
	 *
	 * @return string The id, as the run log and the routes address it.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function getId(): string {
		return ($this->kind . self::ID_SEPARATOR . $this->schemaSlug . self::ID_SEPARATOR . $this->key);
	}//end getId()

	/**
	 * The id a rule of this kind, schema and key will have.
	 *
	 * The hot paths that record an evaluation know those three facts and have
	 * no descriptor in hand, and building one just to read its id would mean
	 * carrying the whole declaration through the save pipeline. This is the
	 * same derivation {@see self::getId()} performs, and a test holds the two
	 * together.
	 *
	 * @param string $kind One of the RuleVocabulary KIND_ constants.
	 * @param string $schemaSlug The slug of the schema the rule acts on.
	 * @param string $key The rule's own key within its kind.
	 *
	 * @return string The derived id.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public static function idFor(string $kind, string $schemaSlug, string $key): string {
		return ($kind . self::ID_SEPARATOR . $schemaSlug . self::ID_SEPARATOR . $key);
	}//end idFor()

	/**
	 * The rule's kind.
	 *
	 * @return string One of the RuleVocabulary KIND_ constants.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function getKind(): string {
		return $this->kind;
	}//end getKind()

	/**
	 * The slug of the schema the rule acts on.
	 *
	 * @return string The schema slug.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function getSchemaSlug(): string {
		return $this->schemaSlug;
	}//end getSchemaSlug()

	/**
	 * The rule's own key within its kind.
	 *
	 * @return string The key: a calculation's name, a transition's name, a state's name or a flow's uuid.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function getKey(): string {
		return $this->key;
	}//end getKey()

	/**
	 * Whether the rule is switched on.
	 *
	 * @return bool True when the rule will be evaluated on the next save.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function isEnabled(): bool {
		return $this->enabled;
	}//end isEnabled()

	/**
	 * The rule's condition, as the author declared it.
	 *
	 * @return mixed The condition, or null when the rule has none.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function getCondition(): mixed {
		return $this->condition;
	}//end getCondition()

	/**
	 * The ceiling the rule declares for one run.
	 *
	 * @return int|null The ceiling, or null when the rule declares none.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function getMaxObjects(): ?int {
		return $this->maxObjects;
	}//end getMaxObjects()

	/**
	 * The entry as the inventory returns it, before the summary is merged on.
	 *
	 * @return array<string, mixed> The descriptor.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'kind' => $this->kind,
			'schema' => $this->schemaSlug,
			'key' => $this->key,
			'label' => $this->label,
			'source' => $this->source,
			'enabled' => $this->enabled,
			'actions' => $this->actions,
			'condition' => $this->condition,
			'maxObjects' => $this->maxObjects,
			'order' => (new RuleVocabulary())->orderOf(kind: $this->kind),
		];
	}//end jsonSerialize()
}//end class
