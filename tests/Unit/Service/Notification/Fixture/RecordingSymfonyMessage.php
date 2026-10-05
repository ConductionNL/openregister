<?php

/**
 * A recording message that exposes its mail object, as Nextcloud's own does.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification\Fixture
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification\Fixture;

/**
 * `getSymfonyEmail()->getHeaders()` writes into `$headers`, with the
 * has/remove/addTextHeader surface of Symfony's header bag.
 */
class RecordingSymfonyMessage extends RecordingMessage {

	/**
	 * The mail object, with its header bag.
	 *
	 * @return object The mail object.
	 */
	public function getSymfonyEmail(): object {
		$message = $this;
		$bag = new class($message) {
			public function __construct(private RecordingMessage $message) {
			}

			public function has(string $name): bool {
				return isset($this->message->headers[$name]);
			}

			public function remove(string $name): void {
				unset($this->message->headers[$name]);
			}

			public function addTextHeader(string $name, string $value): void {
				$this->message->headers[$name] = $value;
			}
		};

		return new class($bag) {
			public function __construct(private object $bag) {
			}

			public function getHeaders(): object {
				return $this->bag;
			}
		};
	}
}
