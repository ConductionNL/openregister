<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ConfigurationDeploymentController;

/**
 * Builds ConfigurationDeploymentController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ConfigurationDeploymentControllerFactory {
	/**
	 * The constructor arguments of ConfigurationDeploymentController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ConfigurationDraftService $drafts Constructor argument.
	 * @param DeploymentPreviewService $previews Constructor argument.
	 * @param DeploymentService $deployments Constructor argument.
	 * @param ConfigurationExplainer $explainer Constructor argument.
	 * @param ConfigurationKeyRegistry $registry Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService $drafts,
		\OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentPreviewService $previews,
		\OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentService $deployments,
		\OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationExplainer $explainer,
		\OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationKeyRegistry $registry,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\ConfigDeploymentControllerServices(
				drafts: $drafts,
				previews: $previews,
				deployments: $deployments,
				explainer: $explainer,
			),
			$registry,
		];
	}

	/**
	 * Build ConfigurationDeploymentController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ConfigurationDeploymentController
	 */
	public static function create(mixed ...$args): ConfigurationDeploymentController {
		return new ConfigurationDeploymentController(...self::args(...$args));
	}
}
