<?php

/**
 * The services a case token is issued through: its store, its randomness and its public address.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\CaseTokenMapperInterface;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * The issuance bundle of {@see CaseTokenService}: the mapper the token rows
 * live in, the secure random source a token value is drawn from, and the URL
 * generator that turns a token into its public link.
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */
final class CaseTokenIssuance {
	/**
	 * Bundle the services.
	 *
	 * @param CaseTokenMapperInterface $mapper       Token persistence.
	 * @param ISecureRandom            $secureRandom NC secure RNG.
	 * @param IURLGenerator            $urlGenerator Public URL builder.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function __construct(
		public readonly CaseTokenMapperInterface $mapper,
		public readonly ISecureRandom $secureRandom,
		public readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()
}//end class
