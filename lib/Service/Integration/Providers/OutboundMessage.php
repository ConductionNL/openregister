<?php

/**
 * OutboundMessage: one provider-shaped message a consuming app hands the messaging leaf.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-message-dispatch/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

/**
 * The message to POST through a messaging source: the path relative to the
 * source base URL, the provider-shaped body the consuming app composed, and
 * optional extra request headers.
 *
 * @spec openspec/specs/integration-message-dispatch/spec.md
 */
final class OutboundMessage {

	/**
	 * Constructor.
	 *
	 * @param string               $path    Path relative to the source base URL (e.g. `{PhoneNumberID}/messages`).
	 * @param array<string,mixed>  $body    The provider-shaped request body.
	 * @param array<string,string> $headers Optional extra request headers (e.g. a per-call Content-Type).
	 */
	public function __construct(
		public readonly string $path,
		public readonly array $body,
		public readonly array $headers = [],
	) {
	}//end __construct()
}//end class
