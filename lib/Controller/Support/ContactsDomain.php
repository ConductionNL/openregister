<?php

/**
 * OpenRegister contacts domain
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

use OCA\OpenRegister\Kernel\Contracts\ContactMatcherInterface;
use OCA\OpenRegister\Service\ContactServiceInterface;
use OCA\OpenRegister\Service\DeepLinkRegistryServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * The domain services of the contacts API.
 *
 * @spec openspec/specs/contacts-actions/spec.md
 */
final class ContactsDomain {
	/**
	 * Bundle the services.
	 *
	 * @param ContactServiceInterface $contactService   Contact service
	 * @param ObjectOperationsInterface           $objectService    Object service
	 * @param ContactMatcherInterface $matchingService  Contact matching service
	 * @param DeepLinkRegistryServiceInterface $deepLinkRegistry Deep link registry
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function __construct(
		public readonly ContactServiceInterface $contactService,
		public readonly ObjectOperationsInterface $objectService,
		public readonly ContactMatcherInterface $matchingService,
		public readonly DeepLinkRegistryServiceInterface $deepLinkRegistry,
	) {
	}//end __construct()
}//end class
