<?php

/**
 * Replaces object references on the item with the objects they name.
 *
 * The endpoint pipeline's `extend_input` rule had no flow equivalent, so an
 * integration that needed the referenced objects next to the data it received
 * had to be built as an endpoint rule chain. This node is that capability as a
 * step: each configured property holding a reference (a uuid, an id, or a URL
 * ending in one) is replaced by the object it names; a property holding a list
 * of references becomes the list of objects.
 *
 * Two departures from the rule, both deliberate:
 *
 *  - The reads run AS the run's acting identity (`runAs`) through the
 *    RBAC-enforcing object read. A flow must not hand on an object its acting
 *    identity may not read.
 *  - A reference that does not resolve FAILS the step. The rule skipped it and
 *    left the raw reference in place; downstream that is indistinguishable from
 *    an extended item whose author forgot the property, and the step would be
 *    recorded as completed having done nothing.
 *
 * A property that is absent, null or empty holds no reference, and a property
 * that already holds an object (a keyed array) has been extended: both are left
 * as they are.
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
 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FieldAssignment;
use OCA\OpenRegister\Service\Flow\FlowFieldPath;
use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCP\IUser;
use OCP\IUserManager;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Puts the objects that configured properties refer to on each item.
 */
class ExtendInputNode extends ObjectNodeBase implements IFlowNode, ExtendInputNodeInterface, IFlowNodeConfigKeys, IFlowNodeConfigForm, IFlowNodeTaxonomy {

	/**
	 * The step type.
	 *
	 * @var string
	 */
	public const NODE_ID = 'openregister.extend-input';

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects      Object reads (RBAC-enforcing).
	 * @param IUserManager              $userManager  Resolves the acting identity.
	 * @param FlowNodePresentation      $presentation Translations and the palette icon.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly IUserManager $userManager,
		private readonly FlowNodePresentation $presentation,
	) {
	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
	 */
	public function getId(): string {
		return self::NODE_ID;
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
	 */
	public function getDisplayName(): string {
		return $this->presentation->l10n->t('Extend references');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
	 */
	public function getDescription(): string {
		return $this->presentation->l10n->t('Replace the object references in chosen fields of the item with the objects they name.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
	 */
	public function getIcon(): string {
		return $this->presentation->urls->imagePath('core', 'actions/external.svg');
	}//end getIcon()

	/**
	 * The config vocabulary of an extend-input step.
	 *
	 * `properties` lists the item fields (dotted paths allowed) whose references
	 * are replaced by the objects they name.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
	 */
	public function configKeys(): array {
		return ['properties'];
	}//end configKeys()

	/**
	 * The editor form.
	 *
	 * @return array<int, array<string, mixed>> The fields.
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
	 */
	public function configForm(): array {
		$l10n = $this->presentation->l10n;

		return [
			[
				'key' => 'properties',
				'label' => $l10n->t('Fields'),
				'type' => 'text',
				'required' => true,
				'help' => $l10n->t('The fields holding object references, separated by commas. Use dots for nested fields, such as case.owner.'),
			],
		];
	}//end configForm()

	/**
	 * Refuse a step that names no field to extend.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When no field, or a field that is not text, is named.
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
	 */
	public function validateConfig(array $config): void {
		$this->properties(config: $config);
	}//end validateConfig()

	/**
	 * Extend every item.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array The items, each with its references replaced by the objects.
	 *
	 * @throws RuntimeException When the run has no usable acting identity, or a reference does not resolve.
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
	 */
	public function execute(FlowNodeCall $call): array {
		if ($call->items === []) {
			return [];
		}

		$properties = $this->properties(config: $call->config);
		$owner      = $this->resolveOwner(context: $call->context);

		$out = [];
		foreach ($call->items as $index => $item) {
			$json = (array)($item[FlowItems::JSON] ?? []);
			foreach ($properties as $property) {
				$value = self::valueAt(json: $json, path: $property);
				if (self::holdsReferences(value: $value) === false) {
					continue;
				}

				FlowFieldPath::assign(
					json: $json,
					write: new FieldAssignment(path: $property, value: $this->extend(owner: $owner, value: $value, property: $property))
				);
			}

			$out[] = FlowItems::item(
				json: $json,
				options: new FlowItemOptions(binary: (array)($item[FlowItems::BINARY] ?? []), fromItemIndex: (int)$index)
			);
		}

		return $out;
	}//end execute()

	/**
	 * What kind of step this is: it reads from the register.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SERVICE_TASK;
	}//end getKind()

	/**
	 * The configured fields, from a list or a comma-separated text.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return array<int, string> The field paths.
	 *
	 * @throws UnexpectedValueException When no field, or a field that is not text, is named.
	 */
	private function properties(array $config): array {
		$raw = ($config['properties'] ?? null);
		if (is_string($raw) === true) {
			$raw = explode(',', $raw);
		}

		if (is_array($raw) === false) {
			throw new UnexpectedValueException($this->presentation->l10n->t('An extend-references step needs the fields to extend.'));
		}

		$properties = [];
		foreach ($raw as $property) {
			if (is_string($property) === false) {
				throw new UnexpectedValueException($this->presentation->l10n->t('An extend-references step\'s fields must be text.'));
			}

			$property = trim($property);
			if ($property !== '') {
				$properties[] = $property;
			}
		}

		if ($properties === []) {
			throw new UnexpectedValueException($this->presentation->l10n->t('An extend-references step needs the fields to extend.'));
		}

		return array_values(array_unique($properties));
	}//end properties()

	/**
	 * The value at a dotted path, or null when any segment is missing.
	 *
	 * @param array  $json The item's record.
	 * @param string $path The dotted path.
	 *
	 * @return mixed The value.
	 */
	private static function valueAt(array $json, string $path): mixed {
		$cursor = $json;
		foreach (explode('.', $path) as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return null;
			}

			$cursor = $cursor[$segment];
		}

		return $cursor;
	}//end valueAt()

	/**
	 * Whether a value is a reference or a list of references to resolve.
	 *
	 * Absent, null and empty hold none; a keyed array is an object already.
	 *
	 * @param mixed $value The field's value.
	 *
	 * @return bool True when there is something to resolve.
	 */
	private static function holdsReferences(mixed $value): bool {
		if (self::isReference(value: $value) === true) {
			return true;
		}

		if (is_array($value) === false || $value === [] || array_is_list($value) === false) {
			return false;
		}

		foreach ($value as $entry) {
			if (self::isReference(value: $entry) === false) {
				return false;
			}
		}

		return true;
	}//end holdsReferences()

	/**
	 * Whether a value is one reference: non-empty text or an id.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a reference.
	 */
	private static function isReference(mixed $value): bool {
		return is_int($value) === true || (is_string($value) === true && trim($value) !== '');
	}//end isReference()

	/**
	 * The object, or list of objects, a field's reference names.
	 *
	 * @param IUser  $owner    The acting identity.
	 * @param mixed  $value    One reference or a list of them.
	 * @param string $property The field, for the message.
	 *
	 * @return array The object, or the list of objects in the order of the references.
	 *
	 * @throws RuntimeException When a reference does not resolve for the acting identity.
	 */
	private function extend(IUser $owner, mixed $value, string $property): array {
		if (is_array($value) === false) {
			return $this->resolve(owner: $owner, reference: (string)$value, property: $property);
		}

		return array_map(fn ($entry): array => $this->resolve(owner: $owner, reference: (string)$entry, property: $property), $value);
	}//end extend()

	/**
	 * Read one referenced object as the acting identity.
	 *
	 * A URL names its object by its last path segment, as the endpoint rule
	 * read it.
	 *
	 * @param IUser  $owner     The acting identity.
	 * @param string $reference The uuid, id or URL.
	 * @param string $property  The field, for the message.
	 *
	 * @return array The object.
	 *
	 * @throws RuntimeException When the object cannot be read.
	 */
	private function resolve(IUser $owner, string $reference, string $property): array {
		$identifier = trim($reference);
		if (filter_var($identifier, FILTER_VALIDATE_URL) !== false) {
			$segments   = explode('/', rtrim($identifier, '/'));
			$identifier = (string)end($segments);
		}

		try {
			$object = $this->objects->runAs(
				$owner,
				// A lookup, not a person opening the record: read without an audit row.
				fn () => $this->objects->findSilent(id: $identifier)
			);
		} catch (Throwable $e) {
			throw new RuntimeException(
				$this->presentation->l10n->t('The extend-references step could not read object "%1$s" in field "%2$s": %3$s', [$identifier, $property, $e->getMessage()]),
				0,
				$e
			);
		}

		return (array)$object->jsonSerialize();
	}//end resolve()

	/**
	 * The run's acting identity, whose rights bound the reads.
	 *
	 * @param array $context Run-level metadata.
	 *
	 * @return IUser The acting identity.
	 *
	 * @throws RuntimeException When the run names no usable, enabled account.
	 */
	private function resolveOwner(array $context): IUser {
		$uid = ($context[FlowRunServiceInterface::RUN_AS_CONTEXT_KEY] ?? null);
		if (is_string($uid) === false || trim($uid) === '') {
			throw new RuntimeException(
				$this->presentation->l10n->t('This flow run has no acting identity (runAs); reading referenced objects must be attributable.')
			);
		}

		$user = $this->userManager->get(trim($uid));
		if ($user === null || $user->isEnabled() === false) {
			throw new RuntimeException(
				$this->presentation->l10n->t(
					'This flow run\'s acting identity "%s" (runAs) is not an enabled user account; reading referenced objects is refused.',
					[trim($uid)]
				)
			);
		}

		return $user;
	}//end resolveOwner()
}//end class
