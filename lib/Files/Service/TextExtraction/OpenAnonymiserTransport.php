<?php

/**
 * OpenAnonymiserTransport: where an OpenAnonymiser analyze request is sent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction/spec.md
 */

namespace OCA\OpenRegister\Files\Service\TextExtraction;

/**
 * The configured OpenAnonymiser transport: ExApp or external endpoint.
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
final readonly class OpenAnonymiserTransport {
	/**
	 * Bundle the transport settings.
	 *
	 * @param string             $endpoint    The external endpoint, without a trailing slash; empty when none.
	 * @param bool               $useExternal Whether the operator chose the external endpoint.
	 * @param array<int, string> $authHeaders Credentials for the external endpoint; empty sends none.
	 */
	public function __construct(
		public string $endpoint,
		public bool $useExternal,
		public array $authHeaders=[],
	) {
	}//end __construct()
}//end class
