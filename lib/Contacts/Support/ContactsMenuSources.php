<?php

/**
 * OpenRegister contacts menu sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contacts
 * @package  OCA\OpenRegister\Contacts\Support
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

namespace OCA\OpenRegister\Contacts\Support;

use OCA\OpenRegister\Kernel\Contracts\ContactMatcherInterface;
use OCA\OpenRegister\Service\DeepLinkResolverInterface;

/**
 * The contact matcher and deep-link resolver the contacts menu finds an entry's objects with.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final readonly class ContactsMenuSources {
	/**
	 * Bundle the services.
	 *
	 * @param ContactMatcherInterface   $matchingService  Finds the objects that mention a contact.
	 * @param DeepLinkResolverInterface $deepLinkRegistry Resolves an object's deep link.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public ContactMatcherInterface $matchingService,
		public DeepLinkResolverInterface $deepLinkRegistry,
	) {
	}//end __construct()
}//end class
