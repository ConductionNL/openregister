<?php

/**
 * Release a run-scoped lock this run holds.
 *
 * THIS NODE IS A CONVENIENCE, NOT THE RELEASE MECHANISM.
 * -----------------------------------------------------
 * The engine releases every lock a run holds when the run reaches ANY
 * terminal outcome, from a listener on `FlowRunTerminalEvent` rather than
 * from a node, precisely so that a run which crashed, failed or was reaped
 * still releases. A release that depended on reaching an unlock step would be
 * no release at all in exactly the cases that matter.
 *
 * What this node adds is EARLINESS: a flow that locks a case, edits it, and
 * then waits three days for a human should not hold the lock across the wait.
 * Releasing here narrows the window from "until the run ends" to "until the
 * work is done".
 *
 * IT IS IDEMPOTENT AND IT DOES NOT BREAK LOCKS. Unlocking an object this run
 * does not hold is refused rather than forced: a step that could release
 * somebody else's lock would defeat the mutual exclusion the lock exists for.
 * Unlocking one that is already free is a no-op, because a flow re-entering
 * this step after a retry upstream must not fail for tidying up twice.
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
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\WorkflowEngine\IManager;
use RuntimeException;
use UnexpectedValueException;

/**
 * Release a run-scoped object lock.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class UnlockObjectNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm, IFlowNodeTaxonomy, UnlockObjectNodeInterface {

	/**
	 * The step type this node answers to.
	 *
	 * @var string
	 */
	public const TYPE = 'openregister.unlock-object';

	/**
	 * Refusal when the run did not identify itself.
	 *
	 * @var string
	 */
	private const REFUSE_NO_RUN = 'An unlock step needs the run it belongs to; this run did not identify itself.';

	/**
	 * Refusal when the run names no acting identity: a unlock must be attributable.
	 *
	 * @var string
	 */
	private const REFUSE_NO_IDENTITY = 'This flow run has no acting identity (runAs); an unlock must be attributable.';

	/**
	 * Refusal when an item names no object.
	 *
	 * @var string
	 */
	private const REFUSE_NO_TARGET = 'An unlock step could not work out which object to release from the item it received.';

	/**
	 * The run, owner and target checks this step shares with the other lock step.
	 *
	 * @var LockStepContext
	 */
	private readonly LockStepContext $lockContext;

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects Object lock operations, RBAC-enforcing.
	 * @param IUserManager $userManager Resolves the run's acting identity.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		IUserManager $userManager,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {
		$this->lockContext = new LockStepContext(userManager: $userManager, l10n: $l10n);
	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The node id.
	 */
	public function getId(): string {
		return self::TYPE;
	}//end getId()

	/**
	 * The palette label.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Unlock an object');
	}//end getDisplayName()

	/**
	 * The palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Release an object this run is holding, so others can change it again. The run releases its locks when it ends anyway; this frees them sooner.'
		);
	}//end getDescription()

	/**
	 * The palette icon.
	 *
	 * @return string The icon path.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-node-the-engine-registers-is-offered-in-the-palette
	 */
	public function getIcon(): string {
		// The APP's icon: see LockObjectNode::getIcon(). `actions/unlock.svg`
		// is not part of NC 33's or 34's core icon set either.
		return $this->urls->imagePath('openregister', 'unlock.svg');
	}//end getIcon()

	/**
	 * Available in both flow scopes.
	 *
	 * @param int $scope The workflow engine scope.
	 *
	 * @return bool True when available.
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * Top-level configuration keys.
	 *
	 * @return array<int, string> The keys.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
	 */
	public function configKeys(): array {
		return ['uuid'];
	}//end configKeys()

	/**
	 * The editor form.
	 *
	 * @return array<int, array<string, mixed>> The fields.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'uuid',
				'label' => $this->l10n->t('Object'),
				'type' => 'text',
				'help' => $this->l10n->t('Which object to release. Leave empty to release the object the step receives.'),
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
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
	 */
	public function validateConfig(array $config): void {
		if (array_key_exists('uuid', $config) === true
			&& is_string($config['uuid']) === false
			&& $config['uuid'] !== null
		) {
			throw new UnexpectedValueException($this->l10n->t('An unlock step\'s object must be text.'));
		}
	}//end validateConfig()

	/**
	 * Release the lock.
	 *
	 * @param array $items The incoming items.
	 * @param array $config The step configuration.
	 * @param array $context The run context.
	 *
	 * @return array The items, unchanged.
	 *
	 * @throws RuntimeException When the step cannot act.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
	 */
	public function execute(array $items, array $config, array $context): array {
		if ($items === []) {
			return $items;
		}

		$this->validateConfig(config: $config);

		$runUuid = $this->lockContext->runUuid(context: $context, refusal: $this->l10n->t(self::REFUSE_NO_RUN));
		$owner = $this->lockContext->owner(context: $context, refusal: $this->l10n->t(self::REFUSE_NO_IDENTITY));

		foreach ($this->lockContext->targets(items: $items, config: $config, refusal: $this->l10n->t(self::REFUSE_NO_TARGET)) as $uuid) {
			$this->objects->runAs(
				$owner,
				fn (): bool => $this->objects->unlockObject(
					identifier: $uuid,
					advisory: false,
					runUuid: $runUuid
				)
			);
		}

		return $items;
	}//end execute()

	/**
	 * What kind of step this is. Calls the register to unlock.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SERVICE_TASK;

	}//end getKind()

	/**
	 * Where an author should look for this step.
	 *
	 * @return string The palette category.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-palette-category-independent-of-its-kind
	 */
	public function getCategory(): string {
		return IFlowNodeTaxonomy::CATEGORY_OBJECTS;

	}//end getCategory()
}//end class
