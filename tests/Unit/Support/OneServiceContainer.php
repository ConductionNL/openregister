<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A container that holds one service under one id, for a class that looks a
 * collaborator up on first use instead of taking it in its constructor.
 */
final class OneServiceContainer implements ContainerInterface {

	/**
	 * Constructor.
	 *
	 * @param string $id      The id the service answers to.
	 * @param object $service The service.
	 */
	public function __construct(
		private readonly string $id,
		private readonly object $service,
	) {
	}//end __construct()

	/**
	 * The service, when asked for by its id.
	 *
	 * @param string $id The id.
	 *
	 * @return object The service.
	 */
	public function get(string $id): object {
		if ($id !== $this->id) {
			throw new RuntimeException('No service ' . $id);
		}

		return $this->service;
	}//end get()

	/**
	 * Whether the id is the one service's.
	 *
	 * @param string $id The id.
	 *
	 * @return bool Whether it is held.
	 */
	public function has(string $id): bool {
		return $id === $this->id;
	}//end has()
}//end class
