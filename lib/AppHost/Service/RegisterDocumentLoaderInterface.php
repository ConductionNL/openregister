<?php

/**
 * RegisterDocumentLoaderInterface: reading an app's register document with its register.d fragments.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost\Service
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

namespace OCA\OpenRegister\AppHost\Service;

/**
 * What the app register import runner needs of RegisterDocumentLoader.
 * Bound to RegisterDocumentLoader in AppHostRegistrar, so it resolves to the same shared instance.
 */
interface RegisterDocumentLoaderInterface {

	/**
	 * The leaf app's register document: the base file plus its register.d fragments.
	 *
	 * @param string $appId The leaf app whose register document is read.
	 *
	 * @return array{0: array<string, mixed>|null, 1: string, 2?: bool} `[$data, $version, $complete]`;
	 *                                                                  `$data` is `null` when no
	 *                                                                  register JSON was found;
	 *                                                                  `$complete` is false when a
	 *                                                                  fragment was skipped.
	 */
	public function load(string $appId): array;
}//end interface
