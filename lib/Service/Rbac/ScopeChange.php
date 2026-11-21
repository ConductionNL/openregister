<?php

/**
 * One requested change of an object's scope.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The object whose scope changes and the scope requested for it, as asked;
 * ObjectSharingService::setScope() refuses a caller who is neither owner nor
 * administrator and a scope outside the vocabulary.
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */
final class ScopeChange {
	/**
	 * The change as asked.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $scope  The requested scope.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly string $scope,
	) {
	}//end __construct()
}//end class
