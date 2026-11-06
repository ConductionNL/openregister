<?php

/**
 * The save-time rules of an object-write step.
 *
 * Two of the delete guards live here rather than at execution: a flow that
 * could delete without a match, or without an explicit acknowledgement, must not
 * be PERSISTABLE. Catching that when the schedule fires at 3am is too late to be
 * called a guard.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCP\IL10N;
use UnexpectedValueException;

/**
 * Refuses a configuration the author cannot have meant.
 */
final class ObjectWriteConfigValidator {

	/**
	 * The complete operation vocabulary. Exactly four values, deliberately.
	 *
	 * @var string[]
	 */
	private const OPERATIONS = [
		ObjectWriteNode::OP_CREATE,
		ObjectWriteNode::OP_UPDATE,
		ObjectWriteNode::OP_UPSERT,
		ObjectWriteNode::OP_DELETE,
	];

	/**
	 * The operations that act on a matched object.
	 *
	 * @var string[]
	 */
	private const MATCHING_OPERATIONS = [
		ObjectWriteNode::OP_UPDATE,
		ObjectWriteNode::OP_UPSERT,
		ObjectWriteNode::OP_DELETE,
	];

	/**
	 * What an option that is not configured counts as; a key set to null is configured.
	 *
	 * @var array<string, mixed>
	 */
	private const OPTION_DEFAULTS = [
		'maxWrites' => 1,
		'onMissing' => ObjectWriteOptions::ON_MISSING_OMIT,
		'onNoMatch' => ObjectWriteOptions::ON_NO_MATCH_ERROR,
		'onConflict' => ObjectWriteOptions::ON_CONFLICT_OVERWRITE,
	];

	/**
	 * The options whose value must come from a fixed list.
	 *
	 * @var array<string, string[]>
	 */
	private const VOCABULARIES = [
		'onMissing' => ObjectWriteOptions::ON_MISSING,
		'onNoMatch' => ObjectWriteOptions::ON_NO_MATCH,
		'onConflict' => ObjectWriteOptions::ON_CONFLICT,
	];

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translations.
	 */
	public function __construct(private readonly IL10N $l10n) {
	}//end __construct()

	/**
	 * Refuse a configuration the author cannot have meant.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the configuration is unusable.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function validate(array $config): void {
		$this->validateTarget(config: $config);

		$operation = $this->operation(config: $config);
		$this->validateOperation(operation: $operation);
		$this->validateMatch(config: $config, operation: $operation);
		$this->validateOperationKeys(config: $config, operation: $operation);
		$this->validateOptionKeys(config: $config);

		if (array_key_exists('bulk', $config) === true) {
			$this->validateBulkKeys(config: $config, operation: $operation);
		}
	}//end validate()

	/**
	 * Read and normalise the configured match pairs.
	 *
	 * Equality on named properties only. No operators, no ranges, no negation,
	 * no raw store filters: the mandatory-match delete guard depends on a human
	 * being able to look at a delete step and see what it can reach, and an
	 * expression language would make that unauditable.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return array<int, array{property: string, value: mixed}> The pairs.
	 *
	 * @throws UnexpectedValueException When a pair is malformed.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function matchPairs(array $config): array {
		$pairs = [];
		foreach ($this->matchEntries(config: $config) as $entry) {
			$pairs[] = $this->matchPair(entry: $entry);
		}

		return $pairs;
	}//end matchPairs()

	/**
	 * Read the configured operation, normalised.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return string The operation, or an empty string when none is set.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function operation(array $config): string {
		$operation = ($config['operation'] ?? null);
		if (is_string($operation) === false) {
			return '';
		}

		return strtolower(trim($operation));
	}//end operation()

	/**
	 * Read a register / schema identifier from configuration.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return string The identifier, or an empty string when unusable.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function identifier(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_int($value) === true) {
			return (string)$value;
		}

		return '';
	}//end identifier()

	/**
	 * The configured match as a list of entries.
	 *
	 * Accepts the map shorthand — `{"status": "flagged"}` — alongside the
	 * canonical pair list. It is the shape every author reaches for first (it is
	 * how `fields` and `filters` are written on neighbouring nodes), and the two
	 * are unambiguous, because a pair list is a LIST and a map is not.
	 *
	 * Detected by key shape rather than by inspecting the values: a list has
	 * sequential integer keys, so anything else is a map of property => value.
	 * `[]` stays a pair list and means "no match", which is what an empty config
	 * should mean.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return array The entries.
	 *
	 * @throws UnexpectedValueException When the match is not a list or a map.
	 */
	private function matchEntries(array $config): array {
		$raw = ($config['match'] ?? []);
		$this->refuseIf(
			broken: is_array($raw) === false,
			message: $this->l10n->t('"match" must be a list of property and value pairs.')
		);

		if (array_is_list($raw) === true) {
			return $raw;
		}

		$entries = [];
		foreach ($raw as $property => $value) {
			$entries[] = ['property' => (string)$property, 'value' => $value];
		}

		return $entries;
	}//end matchEntries()

	/**
	 * Normalise one match entry, refusing each malformed shape by name.
	 *
	 * @param mixed $entry The configured entry.
	 *
	 * @return array{property: string, value: mixed} The pair.
	 *
	 * @throws UnexpectedValueException When the entry is malformed.
	 */
	private function matchPair(mixed $entry): array {
		$this->refuseIf(
			broken: is_array($entry) === false,
			message: $this->l10n->t(
				'Every match entry must name a property and a value. Write [{"property": "status", "value": "flagged"}] or {"status": '
				. '"flagged"}.'
			)
		);

		$property = $this->matchProperty(entry: $entry);
		$this->refuseIf(
			broken: array_key_exists('value', $entry) === false,
			message: $this->l10n->t('The match on "%s" has no value.', [$property])
		);

		return ['property' => $property, 'value' => $entry['value']];
	}//end matchPair()

	/**
	 * The property one match entry names.
	 *
	 * @param array $entry The configured entry.
	 *
	 * @return string The property, trimmed.
	 *
	 * @throws UnexpectedValueException When the entry names none.
	 */
	private function matchProperty(array $entry): string {
		$property = ($entry['property'] ?? null);
		$this->refuseIf(
			broken: is_string($property) === false || trim($property) === '',
			message: $this->l10n->t('Every match entry must name a property.')
		);

		return trim($property);
	}//end matchProperty()

	/**
	 * Refuse a step that names no register or schema.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When either is missing.
	 */
	private function validateTarget(array $config): void {
		$this->refuseIf(
			broken: $this->identifier(value: ($config['register'] ?? null)) === '',
			message: $this->l10n->t('An object-write step needs a register.')
		);
		$this->refuseIf(
			broken: $this->identifier(value: ($config['schema'] ?? null)) === '',
			message: $this->l10n->t('An object-write step needs a schema.')
		);
	}//end validateTarget()

	/**
	 * Refuse a missing or unknown operation.
	 *
	 * @param string $operation The configured operation.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When it is not one of the four.
	 */
	private function validateOperation(string $operation): void {
		$this->refuseIf(
			broken: $operation === '',
			message: $this->l10n->t('An object-write step needs an operation: create, update, upsert or delete.')
		);
		$this->refuseIf(
			broken: in_array($operation, self::OPERATIONS, true) === false,
			message: $this->l10n->t('Unknown operation "%s"; choose create, update, upsert or delete.', [$operation])
		);
	}//end validateOperation()

	/**
	 * Guard 1 of the delete guards, and the same rule for update / upsert:
	 * without a match there is nothing to act on but everything in scope.
	 *
	 * @param array  $config    The step configuration.
	 * @param string $operation The configured operation.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the match is malformed, or missing where it is mandatory.
	 */
	private function validateMatch(array $config, string $operation): void {
		$pairs = $this->matchPairs(config: $config);
		$this->refuseIf(
			broken: $pairs === [] && in_array($operation, self::MATCHING_OPERATIONS, true) === true,
			message: $this->l10n->t('An object-write step with operation "%s" needs at least one match property and value.', [$operation])
		);
	}//end validateMatch()

	/**
	 * Reject keys that have no meaning for the configured operation.
	 *
	 * @param array  $config    The step configuration.
	 * @param string $operation The configured operation.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When a key is meaningless or an acknowledgement is missing.
	 */
	private function validateOperationKeys(array $config, string $operation): void {
		if ($operation === ObjectWriteNode::OP_DELETE) {
			$this->validateDeleteKeys(config: $config);

			return;
		}

		$this->refuseIf(
			broken: array_key_exists('permanent', $config) === true,
			message: $this->l10n->t('"permanent" has no meaning for a "%s" step; it only qualifies a delete.', [$operation])
		);
		$this->refuseIf(
			broken: $operation === ObjectWriteNode::OP_CREATE && array_key_exists('replace', $config) === true,
			message: $this->l10n->t('"replace" has no meaning for a create step.')
		);

		$this->validatePayloadSource(config: $config, operation: $operation);
	}//end validateOperationKeys()

	/**
	 * Require exactly one of `fields` and `payloadFrom`.
	 *
	 * The two are alternatives, not layers: `fields` enumerates properties,
	 * `payloadFrom` writes an object whole. Accepting both would leave the
	 * author unable to tell which one produced the record that was written.
	 *
	 * @param array  $config    The step configuration.
	 * @param string $operation The configured operation.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When both or neither are configured.
	 */
	private function validatePayloadSource(array $config, string $operation): void {
		$sources = (int)((array)($config['fields'] ?? []) !== []) + (int)(trim((string)($config['payloadFrom'] ?? '')) !== '');
		$this->refuseIf(
			broken: $sources === 2,
			message: $this->l10n->t(
				'"fields" and "payloadFrom" are alternatives: "fields" lists the properties to write, "payloadFrom" writes the object '
				. 'at a path whole. Configure one, not both.'
			)
		);
		$this->refuseMissingSource(sources: $sources, operation: $operation);
	}//end validatePayloadSource()

	/**
	 * Refuse a write step with nothing to write.
	 *
	 * @param int    $sources   How many of `fields` and `payloadFrom` are configured.
	 * @param string $operation The configured operation.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When neither is configured.
	 */
	private function refuseMissingSource(int $sources, string $operation): void {
		$this->refuseIf(
			broken: $sources === 0,
			message: $this->l10n->t(
				'An object-write step with operation "%s" needs either at least one field to write, or a "payloadFrom" path naming the '
				. 'object to write whole.',
				[$operation]
			)
		);
	}//end refuseMissingSource()

	/**
	 * The save-time half of the delete guards.
	 *
	 * A delete names WHICH object goes, never what to write into it, so a key
	 * that says what to write is refused rather than left to suggest the payload
	 * mattered.
	 *
	 * Guard 3 is a second, deliberate key: changing one enum value from "update"
	 * to "delete" is not enough to reach a deletion, and the string "true" does
	 * not count. Guard 5 is the only one about IRREVERSIBILITY rather than aim:
	 * it stops a delete from being unrecoverable without the author having said
	 * so, and it takes a boolean because the string "false" is truthy and would
	 * turn a paste into a destruction.
	 *
	 * @param array<string, mixed> $config The step's configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When a key is meaningless for a delete or an acknowledgement is missing.
	 */
	private function validateDeleteKeys(array $config): void {
		$this->refuseIf(
			broken: array_key_exists('fields', $config) === true,
			message: $this->l10n->t('"fields" has no meaning for a delete step.')
		);
		$this->refuseIf(
			broken: array_key_exists('payloadFrom', $config) === true,
			message: $this->l10n->t('"payloadFrom" has no meaning for a delete step.')
		);
		$this->refuseIf(
			broken: array_key_exists('replace', $config) === true,
			message: $this->l10n->t('"replace" has no meaning for a delete step.')
		);
		$this->validateDeleteAcknowledgement(config: $config);
	}//end validateDeleteKeys()

	/**
	 * Guards 3 and 5: the keys that say the author meant this delete.
	 *
	 * @param array<string, mixed> $config The step's configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When an acknowledgement is missing or not a boolean.
	 */
	private function validateDeleteAcknowledgement(array $config): void {
		$this->refuseIf(
			broken: ($config['confirmDelete'] ?? null) !== true,
			message: $this->l10n->t('A delete step must carry "confirmDelete": true, as a boolean.')
		);
		$this->refuseIf(
			broken: array_key_exists('permanent', $config) === true && is_bool($config['permanent']) === false,
			message: $this->l10n->t('"permanent" must be true or false, as a boolean.')
		);
	}//end validateDeleteAcknowledgement()

	/**
	 * Reject an out-of-range option value.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When an option is out of range.
	 */
	private function validateOptionKeys(array $config): void {
		$options = array_merge(self::OPTION_DEFAULTS, $config);
		$this->refuseIf(
			broken: is_int($options['maxWrites']) === false || $options['maxWrites'] < 1,
			message: $this->l10n->t('"maxWrites" must be a whole number of one or more.')
		);

		foreach (self::VOCABULARIES as $key => $allowed) {
			$this->refuseIf(
				broken: in_array($options[$key], $allowed, true) === false,
				message: $this->l10n->t('"%1$s" must be one of: %2$s.', [$key, implode(', ', $allowed)])
			);
		}
	}//end validateOptionKeys()

	/**
	 * The save-time half of the bulk guards.
	 *
	 * Bulk mode swaps the per-item service round-trip for one `saveObjects()`
	 * call, and that path genuinely has fewer semantics than the single-object
	 * one: it replaces rather than patches, it cannot arbitrate a per-row claim,
	 * and it decides create-versus-update by row uuid rather than by a composite
	 * match. Every one of those gaps is refused HERE, at save time, rather than
	 * silently absorbed at 3am — the same rule the delete guards follow.
	 *
	 * `bulk` is a boolean, strictly: the string "false" is truthy, and this
	 * switch changes which write path a whole page of items goes down. And
	 * `saveObjects()` upserts silently, with no per-row failIfExists, so offering
	 * `onConflict: fail` would accept the claim semantics and not deliver them.
	 *
	 * @param array  $config    The step configuration.
	 * @param string $operation The configured operation.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When bulk is combined with semantics the bulk path does not have.
	 */
	private function validateBulkKeys(array $config, string $operation): void {
		$this->refuseIf(
			broken: is_bool($config['bulk']) === false,
			message: $this->l10n->t('"bulk" must be true or false, as a boolean.')
		);
		if ($config['bulk'] === false) {
			return;
		}

		$this->validateBulkOperation(operation: $operation);
		$this->validateBulkConflict(config: $config);
		if ($operation === ObjectWriteNode::OP_UPSERT) {
			$this->validateBulkUpsert(config: $config);
		}
	}//end validateBulkKeys()

	/**
	 * Refuse the operations the bulk path does not write.
	 *
	 * @param string $operation The configured operation.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When it is not a create or an upsert.
	 */
	private function validateBulkOperation(string $operation): void {
		$this->refuseIf(
			broken: in_array($operation, [ObjectWriteNode::OP_CREATE, ObjectWriteNode::OP_UPSERT], true) === false,
			message: $this->l10n->t(
				'"bulk" supports create and upsert only; a "%s" step writes one object at a time, keeping its per-item guards.',
				[$operation]
			)
		);
	}//end validateBulkOperation()

	/**
	 * Refuse the conflict policy the bulk path cannot honour.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When `onConflict` is `fail`.
	 */
	private function validateBulkConflict(array $config): void {
		$this->refuseIf(
			broken: ($config['onConflict'] ?? null) === ObjectWriteOptions::ON_CONFLICT_FAIL,
			message: $this->l10n->t(
				'"onConflict": "fail" is not available in bulk; the bulk path cannot arbitrate per-row claims. Use the single-object '
				. 'path for writes that are claims.'
			)
		);
	}//end validateBulkConflict()

	/**
	 * The bulk guards that apply to an upsert alone.
	 *
	 * The bulk path is PUT-semantic — MagicMapper writes whole rows and never
	 * consults the stored object first — so a bulk upsert without `replace: true`
	 * would silently null every field the mapping did not mention, and the author
	 * must say the word. `saveObjects()` decides create-versus-update by the
	 * row's uuid, so the uuid is the ONLY match a bulk upsert can honour: a
	 * composite match accepted here would be evaluated by nothing.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When replace is missing or the match is not the uuid alone.
	 */
	private function validateBulkUpsert(array $config): void {
		$this->refuseIf(
			broken: ($config['replace'] ?? null) !== true,
			message: $this->l10n->t('A bulk upsert must carry "replace": true — the bulk path replaces whole objects and cannot patch.')
		);

		$pairs = $this->matchPairs(config: $config);
		$this->refuseIf(
			broken: count($pairs) !== 1 || in_array(($pairs[0]['property'] ?? ''), ['uuid', '@self.uuid'], true) === false,
			message: $this->l10n->t(
				'A bulk upsert must match on exactly one property, "uuid" (or "@self.uuid"); a composite match needs the single-object '
				. 'path.'
			)
		);
	}//end validateBulkUpsert()

	/**
	 * Refuse with a message when a rule is broken.
	 *
	 * @param bool   $broken  Whether the rule is broken.
	 * @param string $message The translated refusal.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the rule is broken.
	 */
	private function refuseIf(bool $broken, string $message): void {
		if ($broken === true) {
			throw new UnexpectedValueException($message);
		}
	}//end refuseIf()
}//end class
