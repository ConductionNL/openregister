<?php

/**
 * Where this app asks for a client that can reach the network.
 *
 * 🔑 IT IMPLEMENTS `IClientService`, AND THAT IS THE WHOLE TRICK. This app
 * makes outbound calls from fourteen files across twenty call sites, every one
 * of them `$clientService->newClient()`. Editing twenty call sites would leave
 * the guarantee resting on nobody ever adding a twenty-first, and design D-6 is
 * explicit that the call site somebody forgets is the one that fails in
 * production.
 *
 * So this is registered in the app container UNDER `IClientService` itself.
 * Every existing call site is unchanged and every one of them now gets a
 * proxied client, including ones written next year by somebody who has never
 * read this file. Nothing outside this app is affected: a Nextcloud app
 * container binding overrides the server's only for classes resolved through
 * it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Outbound;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;

/**
 * Builds outbound clients that honour the administered proxy.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Outbound
 */
class OutboundClientFactory implements IClientService {

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService Nextcloud's own client factory.
	 * @param ProxySettings $proxy The one administered proxy setting.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly ProxySettings $proxy,
	) {

	}//end __construct()

	/**
	 * A client whose every request goes through the administered proxy.
	 *
	 * @param callable|null $handler An optional Guzzle handler, forwarded to the
	 *                               inner client. Nextcloud 35 widened
	 *                               IClientService::newClient() with this
	 *                               parameter, so the implementation must carry
	 *                               it or PHP fatals on the signature mismatch.
	 *
	 * @inheritDoc
	 *
	 * @return IClient The client.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function newClient(?callable $handler = null): IClient {
		return new OutboundHttpClient(
			inner: $this->clientService->newClient($handler),
			proxy: $this->proxy,
		);

	}//end newClient()
}//end class
