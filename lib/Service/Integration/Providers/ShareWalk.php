<?php

/**
 * ShareWalk — the share manager and the user whose shares the Shares leaf walks.
 *
 * The two travel together through every step of the folder walk in
 * {@see SharesProvider}; this holds them as one value and asks the manager
 * for one share type on one node.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-shares/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use Throwable;

/**
 * A share manager and the current user uid.
 *
 * @spec openspec/specs/integration-shares/spec.md
 */
final readonly class ShareWalk {

	/**
	 * Constructor.
	 *
	 * @param object $shareManager The share manager (`OCP\Share\IManager`).
	 * @param string $userId       The current user uid.
	 */
	public function __construct(
		public object $shareManager,
		public string $userId,
	) {
	}//end __construct()

	/**
	 * The shares of one type on one node; a per-type failure yields none.
	 *
	 * A missing provider for one type keeps the other types walking rather
	 * than blanking the whole list.
	 *
	 * @param int   $type The share type.
	 * @param mixed $node The child node.
	 *
	 * @return mixed The shares, as the manager returns them.
	 *
	 * @spec openspec/specs/integration-shares/spec.md
	 */
	public function sharesOfType(int $type, mixed $node): mixed {
		try {
			return $this->shareManager->getSharesBy($this->userId, $type, $node);
		} catch (Throwable $e) {
			return [];
		}
	}//end sharesOfType()
}//end class
