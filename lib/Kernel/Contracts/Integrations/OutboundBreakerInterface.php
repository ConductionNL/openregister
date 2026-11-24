<?php

/**
 * OutboundBreakerInterface: an outbound call sent through its dependency's breaker.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Integrations
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Integrations;

use OCP\Http\Client\IResponse;

/**
 * Sends one outbound HTTP call through the breaker of the dependency it reaches.
 *
 * The outbound client (Integrations) names the dependency and hands over the
 * call; load shedding (Platform) keeps the breakers, refuses while one is open,
 * counts the outcome and binds its answer in its own wiring.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
interface OutboundBreakerInterface {

	/**
	 * Send one call through the dependency's breaker.
	 *
	 * While the breaker is open the call is NOT made and the refusal is thrown
	 * at once. Otherwise the call is made and its outcome counted, and its
	 * response returned or its failure rethrown unchanged.
	 *
	 * @param string                 $dependency The named dependency, else the host the call goes to.
	 * @param callable(): IResponse  $send       Makes the call.
	 *
	 * @return IResponse The response.
	 *
	 * @throws \RuntimeException While the dependency's breaker is open.
	 * @throws \Throwable What the call threw.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function send(string $dependency, callable $send): IResponse;
}//end interface
