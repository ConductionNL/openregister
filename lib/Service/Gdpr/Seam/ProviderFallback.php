<?php

/**
 * OpenRegister Provider Fallback
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Seam
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

namespace OCA\OpenRegister\Service\Gdpr\Seam;

/**
 * The fail-closed default a seam resolves to, and the warning it logs when it does.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Seam
 */
final class ProviderFallback {

	/**
	 * Constructor.
	 *
	 * @param object $default   The fail-closed default provider.
	 * @param string $defaultId The default's provider id (for the warning).
	 * @param string $warning   sprintf() format (two `%s`: the selector, the default id).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $default,
		public readonly string $defaultId,
		public readonly string $warning,
	) {

	}//end __construct()
}//end class
