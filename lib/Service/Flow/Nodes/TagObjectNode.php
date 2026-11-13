<?php

/**
 * Add or remove a Nextcloud system tag on the object of each item.
 *
 * AS THE RUN'S IDENTITY, NEVER AS THE SYSTEM
 * ------------------------------------------
 * The step acts as `runAs`, else the person who triggered the run. A run with
 * neither tags nothing and fails the step. Inside `runAs()` each target is
 * loaded with RBAC and multitenancy on and must be an object that identity
 * may UPDATE: a tag changes how a record is shown and filtered, so read is not
 * enough. A target it cannot load or may not update fails the step, naming
 * the object; the engine's `onError` policy decides what happens next.
 *
 * IDEMPOTENT, SO IT CANNOT LOOP ON ITSELF
 * ---------------------------------------
 * Adding a tag an object already has, or removing one it does not have,
 * assigns or unassigns nothing, so no tag event is raised and a flow triggered
 * on `tag.assigned` does not start again because of its own step.
 *
 * A colour given on the step is set on the tag when the tag is created, and
 * on an existing tag only when it has none. Items pass through unchanged.
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
 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\TaggingHandlerInterface;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\WriteCause;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\WorkflowEngine\IManager;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Puts a tag on, or takes it off, the object of each item.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) FlowValueTemplate::render() is the stateless renderer every node uses;
 *   WriteCause::asLookup() marks a lookup.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
 */
class TagObjectNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm, IFlowNodeTaxonomy, TagObjectNodeInterface {

	/**
	 * The step type this node answers to.
	 *
	 * @var string
	 */
	public const TYPE = 'openregister.tag-object';

	/**
	 * Translations.
	 *
	 * @var IL10N
	 */
	private readonly IL10N $l10n;

	/**
	 * For the palette icon.
	 *
	 * @var IURLGenerator
	 */
	private readonly IURLGenerator $urls;

	/**
	 * Reads and checks the step configuration.
	 *
	 * @var TagStepConfig
	 */
	private readonly TagStepConfig $config;

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects     Loads each target as the run identity, RBAC-enforcing.
	 * @param TaggingHandlerInterface   $tags        Reads, assigns and removes object tags.
	 * @param SchemaMapperInterface     $schemas     Resolves a target's schema for the update check.
	 * @param PermissionCheckInterface  $permissions Answers whether the run identity may update a target.
	 * @param IUserManager              $userManager Resolves the run's acting identity.
	 * @param FlowNodePresentation      $presentation Translations and the palette icon.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly TaggingHandlerInterface $tags,
		private readonly SchemaMapperInterface $schemas,
		private readonly PermissionCheckInterface $permissions,
		private readonly IUserManager $userManager,
		FlowNodePresentation $presentation,
	) {
		$this->l10n = $presentation->l10n;
		$this->urls = $presentation->urls;
		$this->config = new TagStepConfig(l10n: $this->l10n);
	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The node id.
	 *
	 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
	 */
	public function getId(): string {
		return self::TYPE;
	}//end getId()

	/**
	 * The palette label.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Tag an object');
	}//end getDisplayName()

	/**
	 * The palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
	 */
	public function getDescription(): string {
		return $this->l10n->t('Put a tag on the object, or take it off. A tag can carry a colour.');
	}//end getDescription()

	/**
	 * The palette icon, shipped by the app so it cannot go missing under it.
	 *
	 * @return string The icon path.
	 *
	 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('openregister', 'tag.svg');
	}//end getIcon()

	/**
	 * Available in both flow scopes, as the object-write node is.
	 *
	 * @param int $scope The workflow engine scope.
	 *
	 * @return bool True when available.
	 *
	 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * Top-level configuration keys.
	 *
	 * @return array<int, string> The keys.
	 *
	 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
	 */
	public function configKeys(): array {
		return ['operation', 'tag', 'color', 'uuid', 'createIfMissing'];
	}//end configKeys()

	/**
	 * The editor form.
	 *
	 * @return array<int, array<string, mixed>> The fields.
	 *
	 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'operation',
				'label' => $this->l10n->t('Action'),
				'type' => 'select',
				'options' => [
					['value' => 'add', 'label' => $this->l10n->t('Add the tag')],
					['value' => 'remove', 'label' => $this->l10n->t('Remove the tag')],
				],
			],
			[
				'key' => 'tag',
				'label' => $this->l10n->t('Tag'),
				'type' => 'text',
				'help' => $this->l10n->t('The tag name, such as "Large deal". It can use fields of the item, such as {{status}}.'),
			],
			[
				'key' => 'color',
				'label' => $this->l10n->t('Colour'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'Optional, six hex digits such as d94c3d. Set when the tag is created, or on a tag that has no colour yet.'
				),
			],
			[
				'key' => 'uuid',
				'label' => $this->l10n->t('Object'),
				'type' => 'text',
				'help' => $this->l10n->t('Which object to tag. Leave empty to tag the object the step receives.'),
			],
			[
				'key' => 'createIfMissing',
				'label' => $this->l10n->t('Create the tag when it does not exist'),
				'type' => 'boolean',
				'help' => $this->l10n->t('On by default. Off: adding a tag that does not exist fails the step.'),
			],
		];
	}//end configForm()

	/**
	 * Refuse a configuration the run could not act on.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the configuration cannot be run.
	 *
	 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
	 */
	public function validateConfig(array $config): void {
		$this->config->validate(config: $config);
	}//end validateConfig()

	/**
	 * Add or remove the tag on each item's object, as the run identity.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array The items, unchanged.
	 *
	 * @throws UnexpectedValueException When the configuration cannot be run.
	 * @throws RuntimeException When the run has no identity, or a target cannot be tagged.
	 *
	 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
	 */
	public function execute(FlowNodeCall $call): array {
		$items = $call->items;
		// An empty firing tags nothing and needs no identity: after a route,
		// the un-taken branch's steps still fire with zero items.
		if ($items === []) {
			return $items;
		}

		$this->validateConfig(config: $call->config);
		$changes = $this->changesFor(items: $items, config: $call->config);
		$actor = $this->actingUser(context: $call->context);
		$this->objects->runAs($actor, function () use ($changes, $call, $actor): void {
			foreach ($changes as $change) {
				$this->apply(change: $change, config: $call->config, actor: $actor);
			}
		});

		return $items;
	}//end execute()

	/**
	 * The distinct (object, tag) pairs this firing touches, in item order.
	 *
	 * @param array $items  The items.
	 * @param array $config The step configuration.
	 *
	 * @return list<array{uuid: string, tag: string}> The pairs.
	 *
	 * @throws RuntimeException When an item names no object or renders an empty tag.
	 */
	private function changesFor(array $items, array $config): array {
		$changes = [];
		foreach ($items as $index => $item) {
			$json = ($item[FlowItems::JSON] ?? []);
			if (is_array($json) === false) {
				$json = [];
			}

			$uuid = $this->targetOf(json: $json, configured: ($config['uuid'] ?? null));
			if ($uuid === '') {
				throw new RuntimeException(
					$this->l10n->t('Item %s names no object to tag: it has no uuid and the step names none.', [(string)$index])
				);
			}

			$tag = trim((string)FlowValueTemplate::render((string)$config['tag'], $json));
			if ($tag === '') {
				throw new RuntimeException($this->l10n->t('The tag name is empty for object %s.', [$uuid]));
			}

			$changes[$uuid."\0".$tag] = ['uuid' => $uuid, 'tag' => $tag];
		}

		return array_values($changes);
	}//end changesFor()

	/**
	 * The uuid of the object an item names: the rendered `uuid` template, else the item's own uuid.
	 *
	 * @param array $json       The item's JSON.
	 * @param mixed $configured The configured `uuid` template.
	 *
	 * @return string The uuid, or an empty string.
	 */
	private function targetOf(array $json, mixed $configured): string {
		if (is_string($configured) === true && trim($configured) !== '') {
			return trim((string)FlowValueTemplate::render($configured, $json));
		}

		return trim((string)($json['uuid'] ?? ''));
	}//end targetOf()

	/**
	 * The enabled account the run acts as: `runAs`, else `triggeredBy`.
	 *
	 * @param array $context The run context.
	 *
	 * @return IUser The account.
	 *
	 * @throws RuntimeException When the run names nobody, or nobody usable.
	 */
	private function actingUser(array $context): IUser {
		$uid = '';
		foreach (['runAs', 'triggeredBy'] as $key) {
			$uid = trim((string)($context[$key] ?? ''));
			if ($uid !== '') {
				break;
			}
		}

		if ($uid === '') {
			throw new RuntimeException(
				$this->l10n->t('This flow run acts as nobody, so a tag step cannot tag anything. Set who the flow runs as.')
			);
		}

		$user = $this->userManager->get($uid);
		if ($user === null || $user->isEnabled() === false) {
			throw new RuntimeException(
				$this->l10n->t('This flow run acts as "%s", which is not an enabled user account.', [$uid])
			);
		}

		return $user;
	}//end actingUser()

	/**
	 * Check the update right on one object and add or remove its tag.
	 *
	 * @param array{uuid: string, tag: string} $change The object and the tag.
	 * @param array                            $config The step configuration.
	 * @param IUser                            $actor  The run identity.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the object cannot be loaded, may not be updated, or tagged.
	 */
	private function apply(array $change, array $config, IUser $actor): void {
		$this->requireUpdate(uuid: $change['uuid'], actor: $actor);

		// Nothing to do is not an error: no tag is assigned or unassigned, so
		// no tag event is raised and a tag-triggered flow cannot loop on itself.
		$hasTag = $this->tags->hasObjectTag(objectUuid: $change['uuid'], tagName: $change['tag']);
		if ($config['operation'] === 'remove') {
			if ($hasTag === true) {
				$this->tags->removeObjectTag(objectUuid: $change['uuid'], tagName: $change['tag']);
			}

			return;
		}

		if ($hasTag === true) {
			return;
		}

		if ($this->config->mayCreate(config: $config) === false && in_array($change['tag'], $this->tags->getAllTags(), true) === false) {
			throw new RuntimeException(
				$this->l10n->t('The tag "%s" does not exist, and this step may not create it.', [$change['tag']])
			);
		}

		$this->tags->addObjectTag(objectUuid: $change['uuid'], tagName: $change['tag'], color: $this->config->colour(config: $config));
	}//end apply()

	/**
	 * Refuse an object the run identity cannot load or may not update.
	 *
	 * @param string $uuid  The object uuid.
	 * @param IUser  $actor The run identity.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the object cannot be loaded or may not be updated.
	 */
	private function requireUpdate(string $uuid, IUser $actor): void {
		try {
			// A lookup for the step, not a person opening the object: no read is recorded.
			$object   = WriteCause::asLookup(fn () => $this->objects->find(id: $uuid));
			$mayWrite = ($object instanceof ObjectEntity) && $this->mayUpdate(object: $object, actor: $actor);
		} catch (Throwable $e) {
			$mayWrite = false;
		}

		if ($mayWrite === false) {
			throw new RuntimeException(
				$this->l10n->t('This flow run may not change object %s, so it cannot tag it.', [$uuid])
			);
		}
	}//end requireUpdate()

	/**
	 * Whether the run identity holds `update` on the object.
	 *
	 * @param ObjectEntity $object The object.
	 * @param IUser        $actor  The run identity.
	 *
	 * @return bool True when it may update the object.
	 */
	private function mayUpdate(ObjectEntity $object, IUser $actor): bool {
		$schema = $this->schemas->find((string)$object->getSchema());

		return $this->permissions->hasPermission(
			schema: $schema,
			action: 'update',
			userId: $actor->getUID(),
			objectOwner: $object->getOwner(),
			object: $object
		);
	}//end mayUpdate()

	/**
	 * What kind of step this is. Calls the register to tag.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SERVICE_TASK;
	}//end getKind()

	/**
	 * Where an author should look for this step.
	 *
	 * @return string The palette category.
	 *
	 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
	 */
	public function getCategory(): string {
		return IFlowNodeTaxonomy::CATEGORY_OBJECTS;
	}//end getCategory()
}//end class
