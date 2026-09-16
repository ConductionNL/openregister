<?php

/**
 * ConfigurationExplainer — which value is in effect, set where, by which deployment.
 *
 * D-4: "which value is in effect" is one third of the question. A support
 * conversation needs all three answers at once, so they come from one read.
 *
 * The three answers that matter, and the three ways a naive implementation
 * gets one of them wrong:
 *
 * - The VALUE. Read from the highest layer that holds one, not from the
 *   instance with the lower layers bolted on afterwards.
 * - The LAYER. Reported even when it is the instance, because "the instance
 *   sets it" is the answer to "why does my register not honour this".
 * - The DEPLOYMENT. Null is a fact here, not a gap: it means the value has
 *   never been moved by a deployment, and REQ-CAD-003 requires that to be
 *   reported as predating the first deployment rather than as unknown. The
 *   difference matters because "unknown" sends somebody looking for a record
 *   that was never going to exist.
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
use OCA\OpenRegister\Db\ConfigurationDeploymentMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Answer why this instance behaves like this.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ConfigurationLayer is a closed
 * vocabulary of compile-time constants. The precedence order it holds is the
 * one REQ-CAD-003 names, and an injected copy could disagree with the order the
 * deployment writes against.
 */
class ConfigurationExplainer {

	/**
	 * Constructor.
	 *
	 * @param ConfigurationValueStore       $store       The live values.
	 * @param ConfigurationDeploymentMapper $deployments The append-only history.
	 */
	public function __construct(
		private readonly ConfigurationValueStore $store,
		private readonly ConfigurationDeploymentMapper $deployments
	) {

	}//end __construct()

	/**
	 * Explain one setting.
	 *
	 * @param string      $configKey The configuration key.
	 * @param string|null $register  The register to read within, when there is one.
	 * @param string|null $bundle    The bundle bound to the subject, when there is one.
	 * @param string|null $subject   The schema, case type or role, when there is one.
	 *
	 * @return array<string, mixed> The effective value, its layer, and its deployment.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function explain(
		string $configKey,
		?string $register = null,
		?string $bundle = null,
		?string $subject = null
	): array {
		$chain = $this->chainFor(
			configKey: $configKey,
			register: $register,
			bundle: $bundle,
			subject: $subject
		);

		$effective = null;
		foreach ($chain as $snapshot) {
			if ($snapshot->present === true) {
				$effective = $snapshot;
			}
		}

		if ($effective === null) {
			return [
				'key' => $configKey,
				'found' => false,
				'value' => null,
				'layer' => null,
				'layerRef' => null,
				'deployment' => null,
				'predatesFirstDeployment' => false,
				'explanation' => sprintf('no layer sets "%s"', $configKey),
				'chain' => array_map(static fn ($item) => $item->jsonSerialize(), $chain),
			];
		}

		$deployment = $this->deploymentFor(uuid: $effective->deploymentUuid);
		$predates = ($effective->hasProvenance() === false);

		return [
			'key' => $configKey,
			'found' => true,
			'value' => $effective->value,
			'layer' => $effective->layer,
			'layerRef' => $effective->layerRef,
			'deployment' => $deployment,
			'predatesFirstDeployment' => $predates,
			'explanation' => $this->explanationFor(
				configKey: $configKey,
				effective: $effective,
				deployment: $deployment,
				predates: $predates
			),
			'chain' => array_map(static fn ($item) => $item->jsonSerialize(), $chain),
		];

	}//end explain()

	/**
	 * Every layer's answer for one key, lowest precedence first.
	 *
	 * @param string      $configKey The configuration key.
	 * @param string|null $register  The register, when there is one.
	 * @param string|null $bundle    The bundle, when there is one.
	 * @param string|null $subject   The subject, when there is one.
	 *
	 * @return array<int, ConfigurationSnapshot> The chain.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function chainFor(
		string $configKey,
		?string $register = null,
		?string $bundle = null,
		?string $subject = null
	): array {
		$references = [
			ConfigurationLayer::INSTANCE => null,
			ConfigurationLayer::REGISTER => $register,
			ConfigurationLayer::BUNDLE => $bundle,
			ConfigurationLayer::SUBJECT => $subject,
		];

		$chain = [];
		foreach (ConfigurationLayer::ORDER as $layer) {
			$reference = $references[$layer];
			if (ConfigurationLayer::requiresReference($layer) === true && $reference === null) {
				// A layer the caller did not name is not in the chain. Reading
				// it with a null reference would read the instance row again
				// and report the instance value as the register's.
				continue;
			}

			$chain[] = $this->store->read(layer: $layer, layerRef: $reference, configKey: $configKey);
		}

		return $chain;

	}//end chainFor()

	/**
	 * The deployment that last moved a value, rendered for a reader.
	 *
	 * @param string|null $uuid The deployment uuid, when the value has one.
	 *
	 * @return array<string, mixed>|null The deployment, or null.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function deploymentFor(?string $uuid): ?array {
		if ($uuid === null) {
			return null;
		}

		try {
			$deployment = $this->deployments->findByUuid(uuid: $uuid);
		} catch (DoesNotExistException $exception) {
			// The provenance names a deployment the history no longer holds.
			// Saying so is better than answering null, which would read as
			// "never deployed" and send a reader to the wrong conclusion.
			return ['uuid' => $uuid, 'name' => null, 'deployedAt' => null, 'missing' => true];
		}

		return [
			'uuid' => $deployment->getUuid(),
			'name' => $deployment->getName(),
			'deployedAt' => $deployment->getDeployedAt()?->format(DateTime::ATOM),
			'deployedBy' => $deployment->getDeployedBy(),
			'isRollback' => $deployment->isRollback(),
			'missing' => false,
		];

	}//end deploymentFor()

	/**
	 * The one-sentence answer a support conversation starts from.
	 *
	 * @param string                    $configKey  The configuration key.
	 * @param ConfigurationSnapshot     $effective  The winning layer's answer.
	 * @param array<string, mixed>|null $deployment The deployment that set it.
	 * @param boolean                   $predates   Whether it predates the first deployment.
	 *
	 * @return string The explanation.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function explanationFor(
		string $configKey,
		ConfigurationSnapshot $effective,
		?array $deployment,
		bool $predates
	): string {
		$where = match ($effective->layerRef) {
			null => sprintf('at the %s layer', $effective->layer),
			default => sprintf('at the %s layer by %s', $effective->layer, $effective->layerRef),
		};

		if ($predates === true) {
			return sprintf(
				'"%s" is set %s and predates the first deployment, so no deployment record names it',
				$configKey,
				$where
			);
		}

		return sprintf(
			'"%s" is set %s, last moved by the deployment "%s"',
			$configKey,
			$where,
			(string)($deployment['name'] ?? $deployment['uuid'] ?? 'unknown')
		);

	}//end explanationFor()
}//end class
