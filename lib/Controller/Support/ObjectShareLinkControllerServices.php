<?php

/**
 * OpenRegister object share link controller services
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

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkReaderInterface;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Share\IManager;

/**
 * The services of the object share link API.
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/share-links-and-email-invites/spec.md
 */
final class ObjectShareLinkControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param IManager         $shareManager  Core share manager — validates the token.
	 * @param ObjectOperationsInterface    $objectService Loads the addressed object.
	 * @param AccessLinkReaderInterface $reader        Projects the object onto what an anonymous caller may read.
	 * @param IThrottler       $throttler     Brute-force throttler for rejected tokens.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/share-links-and-email-invites/spec.md
	 */
	public function __construct(
		public readonly IManager $shareManager,
		public readonly ObjectOperationsInterface $objectService,
		public readonly AccessLinkReaderInterface $reader,
		public readonly IThrottler $throttler,
	) {
	}//end __construct()
}//end class
