<?php

/**
 * OpenRegister federation controller services
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

use OCA\OpenRegister\Db\FederatedShareMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\FederationShareServiceInterface;
use OCP\Security\Bruteforce\IThrottler;

/**
 * The services of the federation API.
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */
final class FederationControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param FederatedShareMapperInterface   $shareMapper   Federated-share persistence.
	 * @param ObjectOperationsInterface          $objectService Object read service.
	 * @param FederationShareServiceInterface $shareService  Share management (list/create/revoke).
	 * @param IThrottler             $throttler     Brute-force throttler for rejected tokens.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	public function __construct(
		public readonly FederatedShareMapperInterface $shareMapper,
		public readonly ObjectOperationsInterface $objectService,
		public readonly FederationShareServiceInterface $shareService,
		public readonly IThrottler $throttler,
	) {
	}//end __construct()
}//end class
