<?php

/**
 * OpenRegister PromotionLanding
 *
 * The Configuration side of a promotion: the package export of a register and a draft set the package lands in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Kernel\Contracts\PromotionLandingInterface;
use OCA\OpenRegister\Kernel\Contracts\PromotionRefusedException;
use OCA\OpenRegister\Service\ConfigurationTransferInterface;

/**
 * Export through the configuration export; land through the draft sets.
 *
 * A promotion lands as drafts and nothing more: the target's preview,
 * approval and deployment are those of any other set, and the deployment's
 * undo record is the rollback snapshot. A draft refusal is passed on as the
 * Kernel refusal, with its reason, message and status unchanged.
 *
 * @spec openspec/specs/environment-otap/spec.md#requirement-configuration-promotion-must-transfer-settings-between-otap-environments
 */
class PromotionLanding implements PromotionLandingInterface {

	/**
	 * Constructor.
	 *
	 * @param ConfigurationTransferInterface $export The configuration export.
	 * @param ConfigurationDraftsInterface   $drafts The draft sets.
	 */
	public function __construct(
		private readonly ConfigurationTransferInterface $export,
		private readonly ConfigurationDraftsInterface $drafts,
	) {

	}//end __construct()

	/**
	 * One register's package, as the configuration export writes it.
	 *
	 * @param Register $register The register.
	 *
	 * @return array<string, mixed> The export document.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
	 */
	public function exportRegister(Register $register): array {
		return $this->export->exportConfig(input: $register);

	}//end exportRegister()

	/**
	 * Open a draft set and draft every value into it.
	 *
	 * @param string                                                                       $name        The set's name.
	 * @param string                                                                       $description What the set is for.
	 * @param list<array{layer: string, layerRef: string, configKey: string, value: mixed}> $drafts      The values, each at its address.
	 *
	 * @return array<string, mixed> The opened set, serialised.
	 *
	 * @throws PromotionRefusedException When the set cannot be opened or a value cannot be drafted.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
	 */
	public function land(string $name, string $description, array $drafts): array {
		try {
			$set     = $this->drafts->openSet(name: $name, description: $description);
			$setUuid = (string) $set->getUuid();
			foreach ($drafts as $draft) {
				$address = new ConfigurationAddress(layer: $draft['layer'], layerRef: $draft['layerRef'], configKey: $draft['configKey']);
				$this->drafts->draftValue(target: new ConfigurationDraftTarget(setUuid: $setUuid, address: $address), value: $draft['value']);
			}
		} catch (DeploymentRefusedException $refusal) {
			throw new PromotionRefusedException(
				reason: $refusal->getReason(),
				message: $refusal->getMessage(),
				refusals: $refusal->getRefusals(),
				statusCode: $refusal->getStatusCode()
			);
		}

		return $set->jsonSerialize();

	}//end land()
}//end class
