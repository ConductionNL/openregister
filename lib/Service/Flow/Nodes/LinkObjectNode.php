<?php

/**
 * Create or link an object related to the item's object.
 *
 * Two modes, one per config shape:
 *
 * - `{register, schema, data, relation?}` creates an object from `data`
 *   (rendered against the item) and, when `relation` is named, writes the new
 *   object's uuid into that property of the item's object (appended when the
 *   property holds a list);
 * - `{handoff: "<key>"}` runs the item's schema's declared
 *   `x-openregister-handoff` entry through the handoff engine, which resolves
 *   the app implementing the target kind, maps the fields and links both sides.
 *
 * 🔴 AN ABSENT IMPLEMENTER FAILS THE STEP with `not-available`. It never
 * succeeds silently: `whenUnavailable: hide` only governs whether a handoff is
 * LISTED, and a run that reached this step was asked to do the work.
 *
 * Both writes go through the normal object write path as the run's identity,
 * so the person's rights apply.
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
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WriteCause;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use RuntimeException;
use UnexpectedValueException;

/**
 * Creates a related object, or runs a declared handoff, per item.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) FlowValueTemplate::render() and FlowItems::item() are the
 *   stateless renderer and item builder every node uses; WriteCause::asLookup() marks a lookup.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
 */
class LinkObjectNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeTaxonomy {

	/**
	 * The step type.
	 *
	 * @var string
	 */
	public const TYPE = 'openregister.link-object';

	/**
	 * The failure code of a handoff whose implementer is absent.
	 *
	 * @var string
	 */
	public const NOT_AVAILABLE = 'not-available';

	/**
	 * Constructor.
	 *
	 * @param ObjectService  $objects  The object write path.
	 * @param HandoffService $handoffs The handoff engine.
	 * @param IL10N          $l10n     Translations.
	 * @param IURLGenerator  $urls     For the palette icon.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly HandoffService $handoffs,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function getId(): string {
		return self::TYPE;
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Create or link an object');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function getDescription(): string {
		return $this->l10n->t('Create a related object and link it, or hand the object over to another app.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/public.svg');
	}//end getIcon()

	/**
	 * Available in both scopes: every write is checked against the run's identity.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return bool Whether it is available.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The config vocabulary.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function configKeys(): array {
		return ['register', 'schema', 'data', 'relation', 'handoff'];
	}//end configKeys()

	/**
	 * Refuse a step that names neither a target nor a handoff, or both.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the configuration is unusable.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function validateConfig(array $config): void {
		$handoff = trim((string)($config['handoff'] ?? ''));
		$target = (trim((string)($config['register'] ?? '')) !== '' && trim((string)($config['schema'] ?? '')) !== '');

		if ($handoff !== '' && $target === true) {
			throw new UnexpectedValueException($this->l10n->t('Name either a "handoff" or a "register" and "schema", not both.'));
		}

		if ($handoff === '' && $target === false) {
			throw new UnexpectedValueException($this->l10n->t('Name a "handoff", or the "register" and "schema" to create the object in.'));
		}

		if (array_key_exists('data', $config) === true && is_array($config['data']) === false) {
			throw new UnexpectedValueException($this->l10n->t('"data" must be an object.'));
		}
	}//end validateConfig()

	/**
	 * Create and link, or hand over, once per item.
	 *
	 * @param array $items   The input items.
	 * @param array $config  The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The items, each carrying what was linked under `linked`.
	 *
	 * @throws RuntimeException `not-available` when a handoff's implementer is absent, or the write's refusal.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function execute(array $items, array $config, array $context): array {
		$this->validateConfig(config: $config);

		$out = [];
		foreach ($items as $index => $item) {
			$json = (array)($item[FlowItems::JSON] ?? []);

			$handoff = trim((string)($config['handoff'] ?? ''));
			$json['linked'] = match ($handoff) {
				'' => $this->createAndLink(json: $json, config: $config),
				default => $this->handOver(json: $json, handoff: $handoff),
			};

			$out[] = FlowItems::item(json: $json, binary: (array)($item[FlowItems::BINARY] ?? []), fromItemIndex: $index);
		}

		return $out;
	}//end execute()

	/**
	 * Run a declared handoff on the item's object.
	 *
	 * @param array<string, mixed> $json    The item's record.
	 * @param string               $handoff The declared handoff key.
	 *
	 * @return array<string, mixed> The handoff's answer.
	 *
	 * @throws RuntimeException `not-available` when the implementer is absent.
	 */
	private function handOver(array $json, string $handoff): array {
		$subject = $this->subjectOf(json: $json);

		try {
			return $this->handoffs->execute(
				register: $subject['register'],
				schema: $subject['schema'],
				id: $subject['uuid'],
				handoffId: $handoff
			);
		} catch (HandoffException $e) {
			if ($e->getErrorCode() === HandoffException::PROVIDER_UNAVAILABLE) {
				throw new RuntimeException(self::NOT_AVAILABLE . ': ' . $e->getMessage(), 0, $e);
			}

			throw new RuntimeException($e->getMessage(), 0, $e);
		}
	}//end handOver()

	/**
	 * Create the configured object and write its uuid into the relation.
	 *
	 * @param array<string, mixed> $json   The item's record.
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return array<string, mixed> The created object.
	 */
	private function createAndLink(array $json, array $config): array {
		$created = $this->objects->saveObject(
			object: (array)FlowValueTemplate::render(value: ($config['data'] ?? []), json: $json),
			register: (string)$config['register'],
			schema: (string)$config['schema']
		);

		$relation = trim((string)($config['relation'] ?? ''));
		if ($relation !== '') {
			$this->writeRelation(json: $json, relation: $relation, uuid: (string)$created->getUuid());
		}

		return $created->jsonSerialize();
	}//end createAndLink()

	/**
	 * Write a uuid into a relation property of the item's object.
	 *
	 * @param array<string, mixed> $json     The item's record.
	 * @param string               $relation The property.
	 * @param string               $uuid     The related object's uuid.
	 *
	 * @return void
	 */
	private function writeRelation(array $json, string $relation, string $uuid): void {
		$subject = $this->subjectOf(json: $json);
		// A lookup to write back to, not a person opening the object.
		$source = WriteCause::asLookup(fn () => $this->objects->find(id: $subject['uuid']));
		if ($source === null) {
			throw new RuntimeException($this->l10n->t('The object to link to no longer exists.'));
		}

		$data = $source->getObject();
		$current = ($data[$relation] ?? null);
		$data[$relation] = $uuid;
		if (is_array($current) === true) {
			$data[$relation] = array_values(array_unique(array_merge($current, [$uuid])));
		}

		$this->objects->saveObject(
			object: $data,
			register: $source->getRegister(),
			schema: $source->getSchema(),
			uuid: (string)$source->getUuid()
		);
	}//end writeRelation()

	/**
	 * The uuid, register and schema of the object an item is about.
	 *
	 * @param array<string, mixed> $json The item's record.
	 *
	 * @return array{uuid: string, register: string, schema: string} The subject.
	 *
	 * @throws RuntimeException When the item names no object.
	 */
	private function subjectOf(array $json): array {
		$self = (array)($json['@self'] ?? []);
		$uuid = trim((string)($json['uuid'] ?? ($self['id'] ?? ($json['id'] ?? ''))));
		if ($uuid === '') {
			throw new RuntimeException($this->l10n->t('The link step could not tell which object the item is.'));
		}

		return [
			'uuid' => $uuid,
			'register' => (string)($self['register'] ?? ''),
			'schema' => (string)($self['schema'] ?? ''),
		];
	}//end subjectOf()

	/**
	 * The BPMN kind.
	 *
	 * @return string The kind.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SERVICE_TASK;
	}//end getKind()

	/**
	 * The palette category.
	 *
	 * @return string The category.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-two-built-in-nodes-cover-transitions-and-linked-objects
	 */
	public function getCategory(): string {
		return IFlowNodeTaxonomy::CATEGORY_OBJECTS;
	}//end getCategory()
}//end class
