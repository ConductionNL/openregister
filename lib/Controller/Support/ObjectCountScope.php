<?php

/**
 * The access posture one batched count runs under.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * The scoping of a batched count: the same RBAC and multitenancy posture as the collection read.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class ObjectCountScope {

	/**
	 * Constructor.
	 *
	 * @param bool                      $rbac          Whether to apply RBAC.
	 * @param bool                      $multi         Whether to apply multitenancy.
	 * @param ObjectOperationsInterface $objectService The object service.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The access posture is the value this object carries
	 */
	public function __construct(
		public readonly bool $rbac,
		public readonly bool $multi,
		public readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()
}//end class
