<?php

/**
 * Reads an object's audit trail onto the item.
 *
 * The endpoint pipeline's `audit_trail` rule had no flow equivalent, so an
 * integration that needed to hand an object's history to its caller had to be
 * built as an endpoint rule chain. This node is that capability as a step.
 *
 * Two departures from the rule, both deliberate:
 *
 *  - The read runs AS the run's acting identity (`runAs`), and the object is
 *    first read through the RBAC-enforcing object read. The audit trail is the
 *    object's history; someone who may not read the object may not read that
 *    either.
 *  - An object or entry that does not resolve FAILS the step. The rule answered
 *    a missing entry with a 404 response; a flow has no response at that point,
 *    and completing with an empty history would be indistinguishable from an
 *    object that simply has none.
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

use JsonSerializable;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCP\IUser;
use OCP\IUserManager;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Puts an object's audit trail, or one entry of it, on each item.
 */
class AuditTrailNode extends ObjectNodeBase implements IFlowNode, AuditTrailNodeInterface, IFlowNodeConfigKeys, IFlowNodeTaxonomy {

	/**
	 * The step type.
	 *
	 * @var string
	 */
	public const NODE_ID = 'openregister.audit-trail';

	/**
	 * Where the trail lands when the step names no `output`.
	 *
	 * @var string
	 */
	private const DEFAULT_OUTPUT_KEY = 'auditTrail';

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects      Object reads (RBAC-enforcing) and the audit-trail read.
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
		return $this->presentation->l10n->t('Read audit trail');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
	 */
	public function getDescription(): string {
		return $this->presentation->l10n->t('Put the audit trail of an object, or one entry of it, on the item.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
	 */
	public function getIcon(): string {
		return $this->presentation->urls->imagePath('core', 'actions/history.svg');
	}//end getIcon()

	/**
	 * The config vocabulary of an audit-trail step.
	 *
	 * `object` names the object (a template over the item, e.g. `{{uuid}}`),
	 * `entry` optionally narrows to one audit-trail entry by its uuid, and
	 * `output` is the item key the result lands under.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
	 */
	public function configKeys(): array {
		return ['object', 'entry', 'output'];
	}//end configKeys()

	/**
	 * Refuse a step that names no object.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When no object is named.
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
	 */
	public function validateConfig(array $config): void {
		if (is_string($config['object'] ?? null) === false || trim($config['object']) === '') {
			throw new UnexpectedValueException($this->presentation->l10n->t('An audit-trail step needs an object.'));
		}

		if (array_key_exists('entry', $config) === true && is_string($config['entry']) === false) {
			throw new UnexpectedValueException($this->presentation->l10n->t('An audit-trail step\'s entry must be text.'));
		}
	}//end validateConfig()

	/**
	 * Read the trail once per item.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array The items, each with the trail (or the one entry) added.
	 *
	 * @throws RuntimeException When the run has no usable acting identity, or the object or entry does not resolve.
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
	 */
	public function execute(FlowNodeCall $call): array {
		if ($call->items === []) {
			return [];
		}

		$this->validateConfig(config: $call->config);
		$owner  = $this->resolveOwner(context: $call->context);
		$output = $this->outputKey(config: $call->config);

		$out = [];
		foreach ($call->items as $index => $item) {
			$json   = (array)($item[FlowItems::JSON] ?? []);
			$object = $this->render(template: (string)$call->config['object'], json: $json, key: 'object');
			$entry  = null;
			if (trim((string)($call->config['entry'] ?? '')) !== '') {
				$entry = $this->render(template: (string)$call->config['entry'], json: $json, key: 'entry');
			}

			$json[$output] = $this->trailFor(owner: $owner, object: $object, entry: $entry);
			$out[]         = FlowItems::item(
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
	 * The trail of one object, or one entry of it, read as the acting identity.
	 *
	 * The object is read through the RBAC-enforcing find first: the trail read
	 * itself does not check access, so without this a flow could read the
	 * history of an object its acting identity may not see.
	 *
	 * @param IUser       $owner  The acting identity.
	 * @param string      $object The object uuid or id.
	 * @param string|null $entry  The audit-trail entry uuid, or null for the whole trail.
	 *
	 * @return array The trail as a list of entries, or the one entry.
	 *
	 * @throws RuntimeException When the object cannot be read or the entry does not exist.
	 *
	 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
	 */
	private function trailFor(IUser $owner, string $object, ?string $entry): array {
		$filters = [];
		if ($entry !== null) {
			$filters['uuid'] = $entry;
		}

		try {
			$logs = $this->objects->runAs(
				$owner,
				function () use ($object, $filters): array {
					$found = $this->objects->find(id: $object);
					if ($found === null) {
						throw new RuntimeException('not found');
					}

					return $this->objects->getLogs(uuid: (string)$found->getUuid(), filters: $filters);
				}
			);
		} catch (Throwable $e) {
			throw new RuntimeException(
				$this->presentation->l10n->t('The audit-trail step could not read object "%s": %s', [$object, $e->getMessage()]),
				0,
				$e
			);
		}

		$entries = array_values(array_map(static fn ($log): array => self::plain(log: $log), (array)$logs));

		if ($entry === null) {
			return $entries;
		}

		if (count($entries) !== 1) {
			throw new RuntimeException(
				$this->presentation->l10n->t('Object "%s" has no audit-trail entry "%s".', [$object, $entry])
			);
		}

		return $entries[0];
	}//end trailFor()

	/**
	 * One audit-trail entry as a plain record.
	 *
	 * @param mixed $log The entry as the read returned it.
	 *
	 * @return array The record.
	 */
	private static function plain(mixed $log): array {
		if ($log instanceof JsonSerializable) {
			return (array)$log->jsonSerialize();
		}

		return (array)$log;
	}//end plain()

	/**
	 * Render a config template against the item, refusing an empty result.
	 *
	 * An identifier that renders empty would read a different object than the
	 * author meant — or none — and report success either way.
	 *
	 * @param string $template The template.
	 * @param array  $json     The item's record.
	 * @param string $key      The config key, for the message.
	 *
	 * @return string The rendered identifier.
	 *
	 * @throws RuntimeException When the template renders empty or leaves a placeholder unresolved.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function render(string $template, array $json, string $key): string {
		$rendered = FlowValueTemplate::renderTracked(value: $template, json: $json);
		$value    = trim((string)$rendered['value']);
		if ($rendered['unresolved'] !== [] || $value === '') {
			throw new RuntimeException(
				$this->presentation->l10n->t(
					'The audit-trail step\'s %s could not be rendered from this item.',
					[$key]
				)
			);
		}

		return $value;
	}//end render()

	/**
	 * The item key the result lands under.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return string The key.
	 */
	private function outputKey(array $config): string {
		$key = trim((string)($config['output'] ?? ''));
		if ($key === '') {
			return self::DEFAULT_OUTPUT_KEY;
		}

		return $key;
	}//end outputKey()

	/**
	 * The run's acting identity, whose rights bound the read.
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
				$this->presentation->l10n->t('This flow run has no acting identity (runAs); an audit-trail read must be attributable.')
			);
		}

		$user = $this->userManager->get(trim($uid));
		if ($user === null || $user->isEnabled() === false) {
			throw new RuntimeException(
				$this->presentation->l10n->t(
					'This flow run\'s acting identity "%s" (runAs) is not an enabled user account; the audit-trail read is refused.',
					[trim($uid)]
				)
			);
		}

		return $user;
	}//end resolveOwner()
}//end class
