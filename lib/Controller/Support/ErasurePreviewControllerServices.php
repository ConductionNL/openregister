<?php

/**
 * OpenRegister ErasurePreviewController services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewServiceInterface;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStoreInterface;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRunnerInterface;

/**
 * The collaborators of ErasurePreviewController, wired by DI autowiring.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
final class ErasurePreviewControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ErasurePreviewServiceInterface $previewService The preview.
	 * @param ErasurePreviewStoreInterface $store The recorded preview and its approval.
	 * @param ErasureRunnerInterface $runner The run.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function __construct(
		public readonly ErasurePreviewServiceInterface $previewService,
		public readonly ErasurePreviewStoreInterface $store,
		public readonly ErasureRunnerInterface $runner,
	) {
	}//end __construct()
}//end class
