<?php

/**
 * OpenRegister entity relations platform
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

use OCP\Files\IRootFolder;

/**
 * The platform services of the entity relations API.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final class EntityRelationsPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param ControllerSession $session    Session user accessor and structured log sink.
	 * @param IRootFolder       $rootFolder Nextcloud root folder (for file lookups).
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public readonly ControllerSession $session,
		public readonly IRootFolder $rootFolder,
	) {
	}//end __construct()
}//end class
