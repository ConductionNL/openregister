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

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\WorkflowEngine\IManager;
use RuntimeException;
use UnexpectedValueException;

/**
 * Release a run-scoped object lock.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class UnlockObjectNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {

	/**
	 * The step type this node answers to.
	 *
	 * @var string
	 */
	public const TYPE = 'openregister.unlock-object';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects Object lock operations, RBAC-enforcing.
	 * @param IUserManager $userManager Resolves the run's acting identity.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly IUserManager $userManager,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {

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
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/unlock.svg');
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

		$runUuid = $this->resolveRunUuid(context: $context);
		$owner = $this->resolveOwner(context: $context);

		foreach ($this->resolveTargets(items: $items, config: $config) as $uuid) {
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
	 * Which objects to release.
	 *
	 * @param array $items The incoming items.
	 * @param array $config The step configuration.
	 *
	 * @return array<int, string> The distinct target uuids.
	 *
	 * @throws RuntimeException When a target cannot be resolved.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowValueTemplate is the engine's
	 * stateless template renderer and every node calls it this way.
	 */
	private function resolveTargets(array $items, array $config): array {
		$configured = ($config['uuid'] ?? null);
		$targets = [];

		foreach ($items as $item) {
			$json = ($item[FlowItems::JSON] ?? []);
			if (is_array($json) === false) {
				$json = [];
			}

			$uuid = trim((string)($json['uuid'] ?? ''));
			if (is_string($configured) === true && trim($configured) !== '') {
				$uuid = trim((string)FlowValueTemplate::render($configured, $json));
			}

			if ($uuid === '') {
				throw new RuntimeException(
					$this->l10n->t('An unlock step could not work out which object to release from the item it received.')
				);
			}

			$targets[$uuid] = $uuid;
		}

		return array_values($targets);
	}//end resolveTargets()

	/**
	 * The run's uuid, which identifies the lock holder.
	 *
	 * @param array $context The run context.
	 *
	 * @return string The run uuid.
	 *
	 * @throws RuntimeException When the run cannot identify itself.
	 */
	private function resolveRunUuid(array $context): string {
		$runUuid = trim((string)($context[FlowRunContext::CONTEXT_RUN] ?? ($context['runUuid'] ?? '')));
		if ($runUuid === '') {
			throw new RuntimeException(
				$this->l10n->t('An unlock step needs the run it belongs to; this run did not identify itself.')
			);
		}

		return $runUuid;
	}//end resolveRunUuid()

	/**
	 * The acting identity the release runs under.
	 *
	 * @param array $context The run context.
	 *
	 * @return IUser The acting user.
	 *
	 * @throws RuntimeException When there is no usable acting identity.
	 */
	private function resolveOwner(array $context): IUser {
		$uid = ($context[FlowRunService::RUN_AS_CONTEXT_KEY] ?? null);
		if (is_string($uid) === false || trim($uid) === '') {
			throw new RuntimeException(
				$this->l10n->t('This flow run has no acting identity (runAs); an unlock must be attributable.')
			);
		}

		$user = $this->userManager->get(trim($uid));
		if ($user === null) {
			throw new RuntimeException(
				$this->l10n->t('This flow run acts as "%s", which is not a user account.', [trim($uid)])
			);
		}

		if ($user->isEnabled() === false) {
			throw new RuntimeException(
				$this->l10n->t('This flow run acts as "%s", whose account is disabled.', [trim($uid)])
			);
		}

		return $user;
	}//end resolveOwner()
}//end class
