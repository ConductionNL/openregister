<?php

/**
 * OpenRegister objects controller services
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

/**
 * Everything the objects controller injects besides the request and the controller session.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectStore              $store      Object service and mappers.
	 * @param ObjectTransfer           $transfer   Export, import, webhooks and upsert.
	 * @param ObjectGovernanceServices $governance Recovery window, uniqueness, purpose and history.
	 * @param ObjectsExtensions        $extensions Geo and linked data services and the container.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly ObjectStore $store,
		public readonly ObjectTransfer $transfer,
		public readonly ObjectGovernanceServices $governance,
		public readonly ObjectsExtensions $extensions,
	) {
	}//end __construct()
}//end class
