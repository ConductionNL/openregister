<?php

/**
 * OpenRegister Idempotent Request
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
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

namespace OCA\OpenRegister\Service\Form;

/**
 * One submit under an Idempotency-Key: the form scope, the key, and who sent what.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 */
final class IdempotentRequest {

	/**
	 * Constructor.
	 *
	 * @param string $scope   The form scope (form id, or the caller's own).
	 * @param string $key     The Idempotency-Key.
	 * @param string $binding The binding of this request (see FormIdempotencyStore::bindingFor()).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $scope,
		public readonly string $key,
		public readonly string $binding,
	) {

	}//end __construct()
}//end class
