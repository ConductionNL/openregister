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
use OCA\OpenRegister\Db\ConfigurationBindingMapperInterface;
use OCA\OpenRegister\Db\ConfigurationDeployment;
use OCA\OpenRegister\Db\ConfigurationDeploymentMapperInterface;
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
	 * @param ConfigurationValueStore                  $store       The live values.
	 * @param ConfigurationDeploymentMapperInterface   $deployments The append-only history.
	 * @param ConfigurationBindingMapperInterface|null $bindings    The bundle bindings, when the instance has them.
	 */
	public function __construct(
		private readonly ConfigurationValueStore $store,
		private readonly ConfigurationDeploymentMapperInterface $deployments,
		private readonly ?ConfigurationBindingMapperInterface $bindings = null
	) {

	}//end __construct()

	/**
	 * Explain one setting.
	 *
	 * @param string             $configKey The configuration key.
	 * @param ConfigurationScope $scope     The register, bundle and subject to read within; none by default.
	 *
	 * @return array<string, mixed> The effective value, its layer, and its deployment.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function explain(string $configKey, ConfigurationScope $scope = new ConfigurationScope()): array {
		return $this->answerFor(
			configKey: $configKey,
			chain: $this->chainFor(
				configKey: $configKey,
				register: $scope->register,
				bundle: $scope->bundle,
				subject: $scope->subject
			)
		);

	}//end explain()

	/**
	 * The explanation of one key from its chain.
	 *
	 * @param string                            $configKey The configuration key.
	 * @param array<int, ConfigurationSnapshot> $chain     Every layer's answer, lowest precedence first.
	 *
	 * @return array<string, mixed> The effective value, its layer, and its deployment.
	 */
	private function answerFor(string $configKey, array $chain): array {
		$effective = $this->effectiveOf(chain: $chain);

		$answer = $this->notFoundAnswer(configKey: $configKey);
		if ($effective !== null) {
			$answer = $this->foundAnswer(configKey: $configKey, effective: $effective);
		}

		return ['key' => $configKey] + $answer + [
			'chain' => array_map(static fn ($item) => $item->jsonSerialize(), $chain),
		];

	}//end answerFor()

	/**
	 * The answer for a key no layer sets, between its key and its chain.
	 *
	 * @param string $configKey The configuration key.
	 *
	 * @return array<string, mixed> found, value, layer, layerRef, deployment, predatesFirstDeployment and explanation.
	 */
	private function notFoundAnswer(string $configKey): array {
		return [
			'found' => false,
			'value' => null,
			'layer' => null,
			'layerRef' => null,
			'deployment' => null,
			'predatesFirstDeployment' => false,
			'explanation' => sprintf('no layer sets "%s"', $configKey),
		];

	}//end notFoundAnswer()

	/**
	 * The answer for a key from the layer that sets it, between its key and its chain.
	 *
	 * @param string                $configKey The configuration key.
	 * @param ConfigurationSnapshot $effective The highest-precedence snapshot holding a value.
	 *
	 * @return array<string, mixed> found, value, layer, layerRef, deployment, predatesFirstDeployment and explanation.
	 */
	private function foundAnswer(string $configKey, ConfigurationSnapshot $effective): array {
		$deployment = $this->deploymentFor(uuid: $effective->deploymentUuid);
		$predates   = ($effective->hasProvenance() === false);

		return [
			'found' => true,
			'value' => $effective->value,
			'layer' => $effective->layer,
			'layerRef' => $effective->layerRef,
			'deployment' => $deployment,
			'predatesFirstDeployment' => $predates,
			'explanation' => self::explanationFor(
				setAt: self::setAt(configKey: $configKey, effective: $effective),
				deployment: $deployment
			),
		];

	}//end foundAnswer()

	/**
	 * The highest-precedence snapshot that holds a value.
	 *
	 * @param array<int, ConfigurationSnapshot> $chain Every layer's answer, lowest precedence first.
	 *
	 * @return ConfigurationSnapshot|null The effective snapshot, or null when no layer holds one.
	 */
	private function effectiveOf(array $chain): ?ConfigurationSnapshot {
		$effective = null;
		foreach ($chain as $snapshot) {
			if ($snapshot->present === true) {
				$effective = $snapshot;
			}
		}

		return $effective;

	}//end effectiveOf()

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
			ConfigurationLayer::BUNDLE => ($bundle ?? $this->boundBundle(subject: $subject)),
			ConfigurationLayer::SUBJECT => $subject,
		];

		return $this->readChain(configKey: $configKey, references: $references);

	}//end chainFor()

	/**
	 * The bundle a subject follows, when the caller did not name one.
	 *
	 * A caller asking about a schema should not have to know which bundle it
	 * follows: a caller that had to pass it could pass the wrong one and get a
	 * confident answer about a bundle the subject never followed.
	 *
	 * @param string|null $subject The subject, when there is one.
	 *
	 * @return string|null The bundle name, or null.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function boundBundle(?string $subject): ?string {
		if ($subject === null || $this->bindings === null) {
			return null;
		}

		return $this->bindings->findBySubject(subject: $subject)?->getBundle();
	}//end boundBundle()

	/**
	 * Read one key at every layer that has its reference, lowest precedence first.
	 *
	 * @param string                     $configKey  The configuration key.
	 * @param array<string, string|null> $references The reference of each layer (null when not named).
	 *
	 * @return array<int, ConfigurationSnapshot> Every named layer's answer.
	 */
	private function readChain(string $configKey, array $references): array {
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

	}//end readChain()

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

		return $this->deploymentSummary(deployment: $deployment);

	}//end deploymentFor()

	/**
	 * What an explanation says about the deployment that set a value.
	 *
	 * @param ConfigurationDeployment $deployment The deployment.
	 *
	 * @return array<string, mixed> uuid, name, deployedAt, deployedBy, isRollback and missing (false).
	 */
	private function deploymentSummary(ConfigurationDeployment $deployment): array {
		return [
			'uuid' => $deployment->getUuid(),
			'name' => $deployment->getName(),
			'deployedAt' => $deployment->getDeployedAt()?->format(DateTime::ATOM),
			'deployedBy' => $deployment->getDeployedBy(),
			'isRollback' => $deployment->isRollback(),
			'missing' => false,
		];

	}//end deploymentSummary()

	/**
	 * Where a key is set, as the opening of its explanation: `"key" is set at the <layer> layer[ by <ref>]`.
	 *
	 * @param string                $configKey The configuration key.
	 * @param ConfigurationSnapshot $effective The winning layer's answer.
	 *
	 * @return string
	 */
	private static function setAt(string $configKey, ConfigurationSnapshot $effective): string {
		$where = match ($effective->layerRef) {
			null => sprintf('at the %s layer', $effective->layer),
			default => sprintf('at the %s layer by %s', $effective->layer, $effective->layerRef),
		};

		return sprintf('"%s" is set %s', $configKey, $where);

	}//end setAt()

	/**
	 * The one-sentence answer a support conversation starts from.
	 *
	 * A value without a deployment predates the first deployment: deploymentFor
	 * answers null exactly when the snapshot carries no provenance.
	 *
	 * @param string                    $setAt      Where the key is set, from setAt().
	 * @param array<string, mixed>|null $deployment The deployment that set it, or null when it predates the first.
	 *
	 * @return string The explanation.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private static function explanationFor(string $setAt, ?array $deployment): string {
		if ($deployment === null) {
			return sprintf('%s and predates the first deployment, so no deployment record names it', $setAt);
		}

		return sprintf(
			'%s, last moved by the deployment "%s"',
			$setAt,
			(string)($deployment['name'] ?? $deployment['uuid'] ?? 'unknown')
		);

	}//end explanationFor()
}//end class
