<?php

/**
 * SupportBundleServiceInterface: what callers outside the Service component ask of SupportBundleService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

/**
 * The redacted support bundle, as the operations maintenance controller uses
 * it. Only the methods that caller calls.
 */
interface SupportBundleServiceInterface {

	/**
	 * Build the redacted support bundle.
	 *
	 * @return array<string, mixed> The version, the build, the redacted settings and the recent failures.
	 */
	public function build(): array;

	/**
	 * The facts shown before a bundle is built.
	 *
	 * @return array<string, mixed> The facts.
	 */
	public function facts(): array;
}//end interface
