<?php

/**
 * ConfigurationDraftService — the half of the lifecycle before anything is live.
 *
 * A set is opened, values are drafted into it against the live values they
 * would replace, somebody approves it, and only then does a deployment apply
 * it. Everything here writes drafts and NOTHING here writes a live value:
 * REQ-CAD-001's first scenario is that reading the setting still returns the
 * live one, and the cheapest way to keep that true is for this class to have
 * no way to write one.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

use DateTime;
use OCA\OpenRegister\Db\ConfigurationDraft;
use OCA\OpenRegister\Db\ConfigurationDraftMapper;
use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Db\ConfigurationDraftSetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IUserSession;

/**
 * Open a set, draft values into it, approve it.
 */
class ConfigurationDraftService {

	/**
	 * The app config key holding the four-eyes requirement.
	 *
	 * @var string
	 */
	public const FOUR_EYES_KEY = 'configuration_four_eyes';

	/**
	 * Constructor.
	 *
	 * @param ConfigurationDraftSetMapper $sets      The draft sets.
	 * @param ConfigurationDraftMapper    $drafts    The pending values.
	 * @param ConfigurationValueStore     $store     The live values.
	 * @param ConfigurationKeyRegistry    $registry  The declared vocabulary.
	 * @param IUserSession                $session   The current session.
	 * @param IAppConfig                  $appConfig Nextcloud's app configuration.
	 * @param string                      $appName   The app the instance keys live under.
	 */
	public function __construct(
		private readonly ConfigurationDraftSetMapper $sets,
		private readonly ConfigurationDraftMapper $drafts,
		private readonly ConfigurationValueStore $store,
		private readonly ConfigurationKeyRegistry $registry,
		private readonly IUserSession $session,
		private readonly IAppConfig $appConfig,
		private readonly string $appName = 'openregister'
	) {

	}//end __construct()

	/**
	 * Whether this instance requires an approver other than the author.
	 *
	 * @return boolean True when four eyes are required.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function requiresFourEyes(): bool {
		return $this->appConfig->getValueBool($this->appName, self::FOUR_EYES_KEY, false);

	}//end requiresFourEyes()

	/**
	 * Open a set.
	 *
	 * @param string      $name        The name an operator will recognise it by.
	 * @param string|null $description What it is for.
	 *
	 * @return ConfigurationDraftSet The opened set.
	 *
	 * @throws DeploymentRefusedException When the name is empty.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function openSet(string $name, ?string $description = null): ConfigurationDraftSet {
		$name = trim($name);
		if ($name === '') {
			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_INVALID,
				message: 'a draft set needs a name',
				refusals: [],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return $this->sets->createFromArray(
			[
				'name' => $name,
				'description' => $description,
				'state' => ConfigurationDraftSet::STATE_OPEN,
				'createdBy' => $this->actor(),
			]
		);

	}//end openSet()

	/**
	 * Read a set back, or refuse by name.
	 *
	 * @param string $uuid The set uuid.
	 *
	 * @return ConfigurationDraftSet The set.
	 *
	 * @throws DeploymentRefusedException When no such set exists.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function loadSet(string $uuid): ConfigurationDraftSet {
		try {
			return $this->sets->findByUuid(uuid: $uuid);
		} catch (DoesNotExistException $exception) {
			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_UNKNOWN,
				message: sprintf('no draft set "%s"', $uuid),
				refusals: [],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

	}//end loadSet()

	/**
	 * Draft one value into a set, against the live value it would replace.
	 *
	 * Drafting the same address twice replaces the pending value rather than
	 * adding a second one: D-1 is one pending value against one live value,
	 * and two rows for one address would make the deployment order-dependent.
	 *
	 * @param string      $setUuid   The set to draft into.
	 * @param string      $layer     The layer.
	 * @param string|null $layerRef  The layer reference, null at instance level.
	 * @param string      $configKey The configuration key.
	 * @param mixed       $value     The pending value.
	 * @param boolean     $removes   Whether the draft removes the value.
	 *
	 * @return ConfigurationDraft The pending value.
	 *
	 * @throws DeploymentRefusedException When the set is closed or the address is not draftable.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function draftValue(
		string $setUuid,
		string $layer,
		?string $layerRef,
		string $configKey,
		mixed $value,
		bool $removes = false
	): ConfigurationDraft {
		$set = $this->loadSet(uuid: $setUuid);
		$this->requireEditable(set: $set);
		$this->requireDraftableAddress(layer: $layer, layerRef: $layerRef, configKey: $configKey, value: $value);

		$live = $this->store->read(layer: $layer, layerRef: $layerRef, configKey: $configKey);
		$existing = $this->drafts->findAtAddress(
			setUuid: $setUuid,
			layer: $layer,
			layerRef: $layerRef,
			configKey: $configKey
		);

		if ($existing !== null) {
			$existing->setDraftValue(['value' => $value]);
			$existing->setBaseValue(['value' => $live->value]);
			$existing->setBasePresent($live->present);
			$existing->setRemoves($removes);

			return $this->drafts->save($existing);
		}

		return $this->drafts->createFromArray(
			[
				'setUuid' => $setUuid,
				'layer' => $layer,
				'layerRef' => $layerRef,
				'configKey' => $configKey,
				'baseValue' => ['value' => $live->value],
				'basePresent' => $live->present,
				'draftValue' => ['value' => $value],
				'removes' => $removes,
				'createdBy' => $this->actor(),
			]
		);

	}//end draftValue()

	/**
	 * The pending values in a set.
	 *
	 * @param string $setUuid The set uuid.
	 *
	 * @return ConfigurationDraft[] The pending values.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function draftsIn(string $setUuid): array {
		return $this->drafts->findBySet(setUuid: $setUuid);

	}//end draftsIn()

	/**
	 * Approve a set for deployment.
	 *
	 * When the instance requires four eyes, an author approving their own set
	 * is refused HERE as well as at deploy time. Refusing only at deploy time
	 * would let a set sit in the approved state it never earned, and the state
	 * is what the next reader trusts.
	 *
	 * @param string $setUuid The set to approve.
	 *
	 * @return ConfigurationDraftSet The approved set.
	 *
	 * @throws DeploymentRefusedException When the set is closed, empty, or self-approved under four eyes.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function approveSet(string $setUuid): ConfigurationDraftSet {
		$set = $this->loadSet(uuid: $setUuid);
		$this->requireEditable(set: $set);

		if ($this->draftsIn(setUuid: $setUuid) === []) {
			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_EMPTY,
				message: sprintf('draft set "%s" holds no pending value to approve', $set->getName() ?? $setUuid)
			);
		}

		$approver = $this->actor();
		if ($this->requiresFourEyes() === true && $approver === $set->getCreatedBy()) {
			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_FOUR_EYES,
				message: sprintf(
					'this instance requires an approver other than the author, and "%s" wrote this set',
					(string)$approver
				),
				refusals: [],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		$set->setState(ConfigurationDraftSet::STATE_APPROVED);
		$set->setApprovedBy($approver);
		$set->setApprovedAt(new DateTime());

		return $this->sets->save($set);

	}//end approveSet()

	/**
	 * Abandon a set without deploying it.
	 *
	 * @param string $setUuid The set to discard.
	 *
	 * @return ConfigurationDraftSet The discarded set.
	 *
	 * @throws DeploymentRefusedException When the set has already been deployed.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function discardSet(string $setUuid): ConfigurationDraftSet {
		$set = $this->loadSet(uuid: $setUuid);

		if ($set->getState() === ConfigurationDraftSet::STATE_DEPLOYED) {
			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_STATE,
				message: 'a deployed set cannot be discarded; roll its deployment back instead'
			);
		}

		$set->setState(ConfigurationDraftSet::STATE_DISCARDED);

		return $this->sets->save($set);

	}//end discardSet()

	/**
	 * List sets, newest first.
	 *
	 * @param string|null $state Optional state filter.
	 * @param integer     $limit How many at most.
	 *
	 * @return ConfigurationDraftSet[] The sets.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function listSets(?string $state = null, int $limit = 100): array {
		return $this->sets->findAllSets(state: $state, limit: $limit);

	}//end listSets()

	/**
	 * Refuse a set that can no longer take values.
	 *
	 * @param ConfigurationDraftSet $set The set.
	 *
	 * @return void
	 *
	 * @throws DeploymentRefusedException When the set is not open.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function requireEditable(ConfigurationDraftSet $set): void {
		if ($set->isEditable() === true) {
			return;
		}

		throw new DeploymentRefusedException(
			reason: DeploymentRefusedException::REASON_STATE,
			message: sprintf(
				'draft set "%s" is %s and no longer takes values',
				$set->getName() ?? (string)$set->getUuid(),
				(string)$set->getState()
			)
		);

	}//end requireEditable()

	/**
	 * Refuse an address the vocabulary does not allow.
	 *
	 * @param string      $layer     The layer.
	 * @param string|null $layerRef  The layer reference.
	 * @param string      $configKey The configuration key.
	 * @param mixed       $value     The proposed value.
	 *
	 * @return void
	 *
	 * @throws DeploymentRefusedException When the address or the value is refused.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function requireDraftableAddress(
		string $layer,
		?string $layerRef,
		string $configKey,
		mixed $value
	): void {
		$refusal = $this->registry->refusalFor(layer: $layer, configKey: $configKey);
		if ($refusal === null && ConfigurationLayer::requiresReference($layer) === true && $layerRef === null) {
			$refusal = sprintf('the %s layer addresses one subject and needs a reference', $layer);
		}

		if ($refusal === null) {
			$refusal = $this->registry->valueRefusalFor(configKey: $configKey, value: $value);
		}

		if ($refusal === null) {
			return;
		}

		throw new DeploymentRefusedException(
			reason: DeploymentRefusedException::REASON_INVALID,
			message: $refusal,
			refusals: [
				[
					'layer' => $layer,
					'layerRef' => $layerRef,
					'key' => $configKey,
					'reason' => $refusal,
				],
			],
			statusCode: Http::STATUS_BAD_REQUEST
		);

	}//end requireDraftableAddress()

	/**
	 * The current user id, or null when there is no session.
	 *
	 * @return string|null The actor.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function actor(): ?string {
		return $this->session->getUser()?->getUID();

	}//end actor()
}//end class
