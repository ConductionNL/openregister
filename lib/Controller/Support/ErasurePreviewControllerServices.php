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

use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewService;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStore;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRunner;

/**
 * The collaborators of ErasurePreviewController, wired by DI autowiring.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
final class ErasurePreviewControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ErasurePreviewService $previewService The preview.
	 * @param ErasurePreviewStore $store The recorded preview and its approval.
	 * @param ErasureRunner $runner The run.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function __construct(
		public readonly ErasurePreviewService $previewService,
		public readonly ErasurePreviewStore $store,
		public readonly ErasureRunner $runner,
	) {
	}//end __construct()
}//end class
