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

use OCA\OpenRegister\Db\ConfigurationDeployment;
use OCA\OpenRegister\Db\ConfigurationDeploymentMapperInterface;
use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Db\ConfigurationDraftSetMapperInterface;
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
class DeploymentService implements DeploymentServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param ConfigurationDraftService     $drafts      The pending values.
	 * @param DeploymentPreviewService      $previews    The diff and its refusals.
	 * @param ConfigurationValueStore       $store       The live values.
	 * @param ConfigurationDeploymentMapperInterface $deployments The append-only history.
	 * @param ConfigurationDraftSetMapperInterface   $sets        The draft sets.
	 * @param IDBConnection                 $db          The database, for the transaction.
	 * @param IUserSession                  $session     The current session.
	 * @param LoggerInterface               $logger      The log.
	 */
	public function __construct(
		private readonly ConfigurationDraftService $drafts,
		private readonly DeploymentPreviewService $previews,
		private readonly ConfigurationValueStore $store,
		private readonly ConfigurationDeploymentMapperInterface $deployments,
		private readonly ConfigurationDraftSetMapperInterface $sets,
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

		$plan = new DeploymentPlan(
			changes: array_values(array_map(self::changeFromVerdict(...), $preview['changes'])),
			name: ($name ?? $set->getName() ?? 'configuration deployment'),
			origin: DeploymentOrigin::ofSet(set: $set)
		);

		return $this->apply(plan: $plan, closeSet: $set);

	}//end deploy()

	/**
	 * The change a deployment records for one preview verdict: both sides of the value.
	 *
	 * @param array<string, mixed> $verdict The preview verdict.
	 *
	 * @return array<string, mixed>
	 */
	private static function changeFromVerdict(array $verdict): array {
		return [
			'layer' => $verdict['layer'],
			'layerRef' => $verdict['layerRef'],
			'key' => $verdict['key'],
			'previous' => $verdict['from'],
			'previousPresent' => ($verdict['fromPresent'] ?? false),
			'value' => $verdict['to'],
			'removes' => ($verdict['status'] === 'remove'),
		];

	}//end changeFromVerdict()

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
		$label = $original->getName() ?? $deploymentUuid;
		self::requireRestorable(original: $original, label: $label);

		$plan = new DeploymentPlan(
			changes: $this->restoringChanges(original: $original),
			name: ($name ?? sprintf('rollback of %s', $label)),
			origin: DeploymentOrigin::rollbackOf(restoresUuid: (string)$original->getUuid(), actor: $this->actor())
		);

		return $this->apply(plan: $plan, closeSet: null);

	}//end rollback()

	/**
	 * Refuse to roll back a deployment that changed no value.
	 *
	 * @param ConfigurationDeployment $original The deployment.
	 * @param string                  $label    Its name, or its UUID.
	 *
	 * @return void
	 *
	 * @throws DeploymentRefusedException When there is nothing to restore.
	 */
	private static function requireRestorable(ConfigurationDeployment $original, string $label): void {
		if ($original->readChanges() === []) {
			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_EMPTY,
				message: sprintf('deployment "%s" changed no value, so there is nothing to restore', $label)
			);
		}

	}//end requireRestorable()

	/**
	 * The changes that put every value a deployment moved back to what it was.
	 *
	 * @param ConfigurationDeployment $original The deployment being rolled back.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function restoringChanges(ConfigurationDeployment $original): array {
		$changes = [];
		foreach ($original->readChanges() as $change) {
			$restore = $this->restoringChange(change: $change);
			if ($restore !== null) {
				$changes[] = $restore;
			}
		}

		return $changes;

	}//end restoringChanges()

	/**
	 * The change that restores one recorded change, against the value live now; null for a change without a key.
	 *
	 * A value that did not exist before the deployment is removed again.
	 *
	 * @param array<string, mixed> $change The recorded change.
	 *
	 * @return array<string, mixed>|null
	 */
	private function restoringChange(array $change): ?array {
		$layer = (string)($change['layer'] ?? ConfigurationLayer::INSTANCE);
		$layerRef = ($change['layerRef'] ?? null);
		$configKey = (string)($change['key'] ?? '');
		if ($configKey === '') {
			return null;
		}

		$live = $this->store->read(layer: $layer, layerRef: $layerRef, configKey: $configKey);
		$restoresToNothing = (($change['previousPresent'] ?? false) === false);
		$value = match ($restoresToNothing) { true => null, false => ($change['previous'] ?? null) };

		return [
			'layer' => $layer, 'layerRef' => $layerRef, 'key' => $configKey,
			'previous' => $live->value, 'previousPresent' => $live->present, 'value' => $value, 'removes' => $restoresToNothing,
		];

	}//end restoringChange()

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
	 * Apply a plan's changes as one deployment, or none of them.
	 *
	 * @param DeploymentPlan             $plan     The changes, the name and the origin.
	 * @param ConfigurationDraftSet|null $closeSet The set to mark deployed, if any.
	 *
	 * @return ConfigurationDeployment The recorded deployment.
	 *
	 * @throws DeploymentRefusedException When a write fails; every earlier write is undone first.
	 */
	private function apply(DeploymentPlan $plan, ?ConfigurationDraftSet $closeSet): ConfigurationDeployment {
		$run = new DeploymentRun(uuid: Uuid::v4()->toRfc4122(), actor: $this->actor());

		$this->db->beginTransaction();

		try {
			foreach ($plan->changes as $change) {
				$run->undo[] = $this->writeOne(run: $run, change: $change);
			}

			return $this->record(row: $plan->row(uuid: $run->uuid, deployedBy: $run->actor), closeSet: $closeSet);
		} catch (Throwable $throwable) {
			throw $this->undo(run: $run, throwable: $throwable);
		}

	}//end apply()

	/**
	 * Record the deployment row, mark the deployed set as deployed by it, and commit.
	 *
	 * @param array<string, mixed>       $row      The deployment row.
	 * @param ConfigurationDraftSet|null $closeSet The set, or null for a rollback.
	 *
	 * @return ConfigurationDeployment
	 */
	private function record(array $row, ?ConfigurationDraftSet $closeSet): ConfigurationDeployment {
		$deployment = $this->deployments->createFromArray($row);
		if ($closeSet !== null) {
			$closeSet->setState(ConfigurationDraftSet::STATE_DEPLOYED);
			$closeSet->setDeploymentUuid($row['uuid']);
			$this->sets->save($closeSet);
		}

		$this->db->commit();
		return $deployment;

	}//end record()

	/**
	 * Undo a deployment that failed mid-apply: roll the rows back, restore the written values, log.
	 *
	 * @param DeploymentRun $run       The deployment, with the undo entries of the writes so far.
	 * @param Throwable     $throwable The failure.
	 *
	 * @return DeploymentRefusedException The refusal to throw.
	 */
	private function undo(DeploymentRun $run, Throwable $throwable): DeploymentRefusedException {
		$this->db->rollBack();
		$restored = $this->store->restore(undoEntries: $run->undo);

		$this->logger->error(
			'Configuration deployment refused mid-apply and was undone',
			['app' => 'openregister', 'deployment' => $run->uuid, 'restored' => $restored, 'exception' => $throwable]
		);

		return new DeploymentRefusedException(
			reason: DeploymentRefusedException::REASON_VALUE_REFUSED,
			message: sprintf('the deployment was undone in full after a write refused: %s', $throwable->getMessage()),
			refusals: [],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
		);

	}//end undo()

	/**
	 * Write one address, returning its undo entry.
	 *
	 * @param DeploymentRun        $run    The deployment doing the writing, and who does it.
	 * @param array<string, mixed> $change The address and its new value.
	 *
	 * @return array<string, mixed> The undo entry.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function writeOne(DeploymentRun $run, array $change): array {
		$layer = (string)($change['layer'] ?? ConfigurationLayer::INSTANCE);
		$layerRef = ($change['layerRef'] ?? null);
		$configKey = (string)($change['key'] ?? '');

		if (($change['removes'] ?? false) === true) {
			return $this->store->remove(layer: $layer, layerRef: $layerRef, configKey: $configKey);
		}

		$value = ($change['value'] ?? null);
		return $this->store->write(
			address: new ConfigurationAddress(layer: $layer, layerRef: $layerRef, configKey: $configKey),
			write: new ConfigurationWrite(value: $value, deploymentUuid: $run->uuid, actor: $run->actor)
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
		$deployable = [ConfigurationDraftSet::STATE_OPEN, ConfigurationDraftSet::STATE_APPROVED];
		if (in_array($set->getState(), $deployable, true) === false) {
			$label = $set->getName() ?? (string)$set->getUuid();
			$message = sprintf('draft set "%s" is %s and cannot be deployed', $label, (string)$set->getState());
			throw new DeploymentRefusedException(reason: DeploymentRefusedException::REASON_STATE, message: $message);
		}

		if ($this->drafts->requiresFourEyes() === true) {
			$this->requireFourEyes(set: $set);
		}

	}//end requireDeployable()

	/**
	 * Under four-eyes, refuse a set that is not approved, or that its own author approved.
	 *
	 * @param ConfigurationDraftSet $set The set.
	 *
	 * @return void
	 *
	 * @throws DeploymentRefusedException When the set lacks a second pair of eyes.
	 */
	private function requireFourEyes(ConfigurationDraftSet $set): void {
		if ($set->isApproved() === false) {
			throw self::fourEyesRefusal(message: 'this instance requires an approval before a set can be deployed');
		}

		if ($set->getApprovedBy() === $set->getCreatedBy()) {
			throw self::fourEyesRefusal(
				message: sprintf('this instance requires an approver other than the author, and "%s" is both', (string)$set->getCreatedBy())
			);
		}

	}//end requireFourEyes()

	/**
	 * A four-eyes refusal, answered with 403.
	 *
	 * @param string $message The message.
	 *
	 * @return DeploymentRefusedException
	 */
	private static function fourEyesRefusal(string $message): DeploymentRefusedException {
		return new DeploymentRefusedException(
			reason: DeploymentRefusedException::REASON_FOUR_EYES,
			message: $message,
			refusals: [],
			statusCode: Http::STATUS_FORBIDDEN
		);

	}//end fourEyesRefusal()

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
			throw self::valueRefusal(refusals: $refusals);
		}

		if ($preview['changes'] === []) {
			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_EMPTY,
				message: sprintf('draft set "%s" would change nothing', $set->getName() ?? (string)$set->getUuid())
			);
		}

	}//end requireNoRefusals()

	/**
	 * The refusal of a set holding values that would not apply, naming the first.
	 *
	 * @param array<int, array<string, mixed>> $refusals The refusals, at least one.
	 *
	 * @return DeploymentRefusedException
	 */
	private static function valueRefusal(array $refusals): DeploymentRefusedException {
		$first = $refusals[0];
		return new DeploymentRefusedException(
			reason: DeploymentRefusedException::REASON_VALUE_REFUSED,
			message: sprintf(
				'nothing was applied: "%s" refused (%s)',
				(string)($first['key'] ?? 'a value'),
				(string)($first['reason'] ?? 'no reason given')
			),
			refusals: $refusals
		);

	}//end valueRefusal()

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
