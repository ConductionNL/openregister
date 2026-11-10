<?php

/**
 * ProxySettingsInterface: what callers outside the Service component use of ProxySettings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Outbound
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Outbound;

/**
 * The methods of ProxySettings that callers outside the Service component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to ProxySettings. Guarded by F05OutboundInterfaceBindingsTest.
 */
interface ProxySettingsInterface {

	/**
	 * The request options every outbound call carries.
	 *
	 * Guzzle's `proxy` option takes either a string or a map with `http`,
	 * `https` and `no`; the map form is used whenever there are exceptions.
	 *
	 * @return array<string, mixed> The options, or an empty array when no proxy is set.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function requestOptions(): array;
}//end interface
