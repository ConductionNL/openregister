<?php

/**
 * DeploymentService — apply a set as one named unit, and undo it as another.
 *
 * D-2: a deployment that applies six of nine values leaves an instance in a
 * state nobody designed and nobody can describe. So the walk is in two halves.
 * The first decides, through the preview, and writes nothing; if any value
 * refuses, the deployment ends there, naming it. Only a set where every value
 * would apply reaches the second half.
 *
 * The second half is a transaction, with one compensation hanging off it: the
 * instance layer writes through to Nextcloud's app config, which no database
 * transaction can roll back. Every write therefore hands back an undo entry,
 * and a failure restores them before rethrowing. Without that, a rollback of
 * the rows would leave the app config values applied, which is the partial
 * state D-2 exists to forbid.
 *
 * D-3: a rollback is a new deployment. The row it restores is never edited and
 * never deleted, so the record of the weekend the instance was broken survives
 * the fix.
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
use OCA\OpenRegister\Db\ConfigurationDeployment;
use OCA\OpenRegister\Db\ConfigurationDeploymentMapper;
use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Db\ConfigurationDraftSetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Deploy a draft set, and roll a deployment back.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The lifecycle coordinates the
 * set, the preview, the value store, the history and the transaction. Splitting
 * it would put the all-or-nothing rule in two places.
 */
class DeploymentService {

	/**
	 * Constructor.
	 *
	 * @param ConfigurationDraftService     $drafts      The pending values.
	 * @param DeploymentPreviewService      $previews    The diff and its refusals.
	 * @param ConfigurationValueStore       $store       The live values.
	 * @param ConfigurationDeploymentMapper $deployments The append-only history.
	 * @param ConfigurationDraftSetMapper   $sets        The draft sets.
	 * @param IDBConnection                 $db          The database, for the transaction.
	 * @param IUserSession                  $session     The current session.
	 * @param LoggerInterface               $logger      The log.
	 */
	public function __construct(
		private readonly ConfigurationDraftService $drafts,
		private readonly DeploymentPreviewService $previews,
		private readonly ConfigurationValueStore $store,
		private readonly ConfigurationDeploymentMapper $deployments,
		private readonly ConfigurationDraftSetMapper $sets,
		private readonly IDBConnection $db,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger
	) {

	}//end __construct()

	/**
	 * Deploy a draft set.
	 *
	 * @param string      $setUuid The set to deploy.
	 * @param string|null $name    The name the deployment is recorded under.
	 *
	 * @return ConfigurationDeployment The recorded deployment.
	 *
	 * @throws DeploymentRefusedException When the set refuses, in full, before anything is written.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function deploy(string $setUuid, ?string $name = null): ConfigurationDeployment {
		$set = $this->drafts->loadSet(uuid: $setUuid);
		$this->requireDeployable(set: $set);

		$preview = $this->previews->preview(set: $set);
		$this->requireNoRefusals(set: $set, preview: $preview);

		$changes = [];
		foreach ($preview['changes'] as $verdict) {
			$changes[] = [
				'layer' => $verdict['layer'],
				'layerRef' => $verdict['layerRef'],
				'key' => $verdict['key'],
				'previous' => $verdict['from'],
				'previousPresent' => ($verdict['fromPresent'] ?? false),
				'value' => $verdict['to'],
				'removes' => ($verdict['status'] === 'remove'),
			];
		}

		return $this->apply(
			changes: $changes,
			name: ($name ?? $set->getName() ?? 'configuration deployment'),
			setUuid: (string)$set->getUuid(),
			author: $set->getCreatedBy(),
			approver: $set->getApprovedBy(),
			restoresUuid: null,
			closeSet: $set
		);

	}//end deploy()

	/**
	 * Roll a deployment back, as a new deployment.
	 *
	 * @param string      $deploymentUuid The deployment to restore the values of.
	 * @param string|null $name           The name the rollback is recorded under.
	 *
	 * @return ConfigurationDeployment The recorded rollback.
	 *
	 * @throws DeploymentRefusedException When there is nothing to restore.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function rollback(string $deploymentUuid, ?string $name = null): ConfigurationDeployment {
		$original = $this->loadDeployment(uuid: $deploymentUuid);

		if ($original->readChanges() === []) {
			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_EMPTY,
				message: sprintf(
					'deployment "%s" changed no value, so there is nothing to restore',
					$original->getName() ?? $deploymentUuid
				)
			);
		}

		$changes = [];
		foreach ($original->readChanges() as $change) {
			$layer = (string)($change['layer'] ?? ConfigurationLayer::INSTANCE);
			$layerRef = ($change['layerRef'] ?? null);
			$configKey = (string)($change['key'] ?? '');
			if ($configKey === '') {
				continue;
			}

			$live = $this->store->read(layer: $layer, layerRef: $layerRef, configKey: $configKey);
			$restoresToNothing = (($change['previousPresent'] ?? false) === false);

			$changes[] = [
				'layer' => $layer,
				'layerRef' => $layerRef,
				'key' => $configKey,
				'previous' => $live->value,
				'previousPresent' => $live->present,
				'value' => match ($restoresToNothing) {
					true => null,
					false => ($change['previous'] ?? null),
				},
				'removes' => $restoresToNothing,
			];
		}//end foreach

		return $this->apply(
			changes: $changes,
			name: ($name ?? sprintf('rollback of %s', $original->getName() ?? $deploymentUuid)),
			setUuid: null,
			author: $this->actor(),
			approver: null,
			restoresUuid: (string)$original->getUuid(),
			closeSet: null
		);

	}//end rollback()

	/**
	 * Read a deployment back, or refuse by name.
	 *
	 * @param string $uuid The deployment uuid.
	 *
	 * @return ConfigurationDeployment The deployment.
	 *
	 * @throws DeploymentRefusedException When no such deployment exists.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function loadDeployment(string $uuid): ConfigurationDeployment {
		try {
			return $this->deployments->findByUuid(uuid: $uuid);
		} catch (DoesNotExistException $exception) {
			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_UNKNOWN,
				message: sprintf('no deployment "%s"', $uuid),
				refusals: [],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

	}//end loadDeployment()

	/**
	 * The deployment history, newest first.
	 *
	 * @param integer $limit How many at most.
	 *
	 * @return ConfigurationDeployment[] The history.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function history(int $limit = 100): array {
		return $this->deployments->findHistory(limit: $limit);

	}//end history()

	/**
	 * Apply a list of changes as one deployment, or none of them.
	 *
	 * @param array<int, array<string, mixed>> $changes      The addresses to move.
	 * @param string                           $name         The deployment name.
	 * @param string|null                      $setUuid      The set being published, when there is one.
	 * @param string|null                      $author       The author of the set.
	 * @param string|null                      $approver     The approver, when one was required.
	 * @param string|null                      $restoresUuid The deployment being restored, on a rollback.
	 * @param ConfigurationDraftSet|null       $closeSet     The set to close once the values are live.
	 *
	 * @return ConfigurationDeployment The recorded deployment.
	 *
	 * @throws DeploymentRefusedException When a write throws; nothing stays applied.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function apply(
		array $changes,
		string $name,
		?string $setUuid,
		?string $author,
		?string $approver,
		?string $restoresUuid,
		?ConfigurationDraftSet $closeSet
	): ConfigurationDeployment {
		$deploymentUuid = Uuid::v4()->toRfc4122();
		$actor = $this->actor();
		$undo = [];

		$this->db->beginTransaction();

		try {
			foreach ($changes as $change) {
				$undo[] = $this->writeOne(change: $change, deploymentUuid: $deploymentUuid, actor: $actor);
			}

			$deployment = $this->deployments->createFromArray(
				[
					'uuid' => $deploymentUuid,
					'name' => $name,
					'setUuid' => $setUuid,
					'author' => $author,
					'approver' => $approver,
					'deployedBy' => $actor,
					'deployedAt' => new DateTime(),
					'restoresUuid' => $restoresUuid,
					'changes' => $changes,
					'state' => match ($restoresUuid) {
						null => ConfigurationDeployment::STATE_APPLIED,
						default => ConfigurationDeployment::STATE_ROLLED_BACK,
					},
				]
			);

			if ($closeSet !== null) {
				$closeSet->setState(ConfigurationDraftSet::STATE_DEPLOYED);
				$closeSet->setDeploymentUuid($deploymentUuid);
				$this->sets->save($closeSet);
			}

			$this->db->commit();
		} catch (Throwable $throwable) {
			$this->db->rollBack();
			$restored = $this->store->restore(undoEntries: $undo);

			$this->logger->error(
				'Configuration deployment refused mid-apply and was undone',
				[
					'app' => 'openregister',
					'deployment' => $deploymentUuid,
					'restored' => $restored,
					'exception' => $throwable,
				]
			);

			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_VALUE_REFUSED,
				message: sprintf(
					'the deployment was undone in full after a write refused: %s',
					$throwable->getMessage()
				),
				refusals: [],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		return $deployment;

	}//end apply()

	/**
	 * Write one address, returning its undo entry.
	 *
	 * @param array<string, mixed> $change         The address and its new value.
	 * @param string               $deploymentUuid The deployment doing the writing.
	 * @param string|null          $actor          Who is doing the writing.
	 *
	 * @return array<string, mixed> The undo entry.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function writeOne(array $change, string $deploymentUuid, ?string $actor): array {
		$layer = (string)($change['layer'] ?? ConfigurationLayer::INSTANCE);
		$layerRef = ($change['layerRef'] ?? null);
		$configKey = (string)($change['key'] ?? '');

		if (($change['removes'] ?? false) === true) {
			return $this->store->remove(layer: $layer, layerRef: $layerRef, configKey: $configKey);
		}

		return $this->store->write(
			layer: $layer,
			layerRef: $layerRef,
			configKey: $configKey,
			value: ($change['value'] ?? null),
			deploymentUuid: $deploymentUuid,
			actor: $actor
		);

	}//end writeOne()

	/**
	 * Refuse a set that may not be deployed.
	 *
	 * @param ConfigurationDraftSet $set The set.
	 *
	 * @return void
	 *
	 * @throws DeploymentRefusedException When the state or the approval refuses it.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function requireDeployable(ConfigurationDraftSet $set): void {
		if (in_array(
			$set->getState(),
			[ConfigurationDraftSet::STATE_OPEN, ConfigurationDraftSet::STATE_APPROVED],
			true
		) === false
		) {
			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_STATE,
				message: sprintf(
					'draft set "%s" is %s and cannot be deployed',
					$set->getName() ?? (string)$set->getUuid(),
					(string)$set->getState()
				)
			);
		}

		if ($this->drafts->requiresFourEyes() === false) {
			return;
		}

		if ($set->isApproved() === false) {
			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_FOUR_EYES,
				message: 'this instance requires an approval before a set can be deployed',
				refusals: [],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		if ($set->getApprovedBy() === $set->getCreatedBy()) {
			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_FOUR_EYES,
				message: sprintf(
					'this instance requires an approver other than the author, and "%s" is both',
					(string)$set->getCreatedBy()
				),
				refusals: [],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

	}//end requireDeployable()

	/**
	 * Refuse a set holding a value that would not apply.
	 *
	 * @param ConfigurationDraftSet $set     The set.
	 * @param array<string, mixed>  $preview The preview of that set.
	 *
	 * @return void
	 *
	 * @throws DeploymentRefusedException Naming the value that refused.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function requireNoRefusals(ConfigurationDraftSet $set, array $preview): void {
		$refusals = $preview['refusals'];

		if ($refusals !== []) {
			$first = $refusals[0];

			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_VALUE_REFUSED,
				message: sprintf(
					'nothing was applied: "%s" refused (%s)',
					(string)($first['key'] ?? 'a value'),
					(string)($first['reason'] ?? 'no reason given')
				),
				refusals: $refusals
			);
		}

		if ($preview['changes'] === []) {
			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_EMPTY,
				message: sprintf(
					'draft set "%s" would change nothing',
					$set->getName() ?? (string)$set->getUuid()
				)
			);
		}

	}//end requireNoRefusals()

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
