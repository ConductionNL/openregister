<?php

/**
 * OpenRegister DependencyBreaker
 *
 * The dependency breakers behind the Kernel breaker port.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Resilience
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Resilience;

use OCA\OpenRegister\Kernel\Contracts\DependencyBreakerInterface;

/**
 * Answers the Kernel breaker port with this request's shared breaker.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
final class DependencyBreaker implements DependencyBreakerInterface {

	/**
	 * Constructor.
	 *
	 * @param BreakerRegistry $breakers The breaker registry.
	 */
	public function __construct(
		private readonly BreakerRegistry $breakers,
	) {
	}//end __construct()

	/**
	 * @inheritDoc
	 */
	public function allow(string $key): void {
		$this->breakers->breaker()->allow(key: $key);
	}//end allow()

	/**
	 * @inheritDoc
	 */
	public function call(string $key, callable $call): mixed {
		return $this->breakers->breaker()->call(key: $key, call: $call);
	}//end call()

	/**
	 * @inheritDoc
	 */
	public function recordSuccess(string $key): void {
		$this->breakers->breaker()->recordSuccess(key: $key);
	}//end recordSuccess()

	/**
	 * @inheritDoc
	 */
	public function recordFailure(string $key, ?int $retryAfter = null): void {
		$this->breakers->breaker()->recordFailure(key: $key, retryAfter: $retryAfter);
	}//end recordFailure()

	/**
	 * @inheritDoc
	 */
	public function llmKey(): string {
		return BreakerRegistry::llmKey();
	}//end llmKey()

	/**
	 * @inheritDoc
	 */
	public function webhookKey(string $host): string {
		return BreakerRegistry::webhookKey(host: $host);
	}//end webhookKey()

	/**
	 * @inheritDoc
	 */
	public function sourceKey(string|int $sourceId): string {
		return BreakerRegistry::sourceKey(sourceId: $sourceId);
	}//end sourceKey()
}//end class
