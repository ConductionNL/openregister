<?php

/**
 * The peer instance a federated schema reads from, and the register and schema its records bind to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-source-providers/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

/**
 * A federated schema's peer: its objects base URL (null when unconfigured) and the local binding.
 *
 * @spec openspec/changes/object-source-providers/tasks.md
 */
final class FederatedPeer {
	/**
	 * Constructor.
	 *
	 * @param SourcedObjectBinding $binding The local register and schema the peer's records bind to.
	 * @param string|null          $baseUrl The peer's objects base URL, or null when the config names none.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly SourcedObjectBinding $binding,
		public readonly ?string $baseUrl,
	) {
	}//end __construct()
}//end class
