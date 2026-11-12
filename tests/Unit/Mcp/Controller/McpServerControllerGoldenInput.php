<?php

/**
 * A php:// stream wrapper that serves a fixed request body, for the McpServerController golden test.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\Controller;

/**
 * Serves McpServerControllerGoldenInput::$body as php://input.
 */
final class McpServerControllerGoldenInput {

	/**
	 * The body every php:// stream reads.
	 *
	 * @var string
	 */
	public static string $body = '';

	/**
	 * Stream context, set by PHP.
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * Read position.
	 *
	 * @var int
	 */
	private int $position = 0;

	/**
	 * Open the stream.
	 *
	 * @return bool
	 */
	public function stream_open(): bool {
		$this->position = 0;
		return true;
	}

	/**
	 * Read from the body.
	 *
	 * @param int $count Bytes wanted.
	 *
	 * @return string
	 */
	public function stream_read(int $count): string {
		$data = substr(self::$body, $this->position, $count);
		$this->position += strlen($data);
		return $data;
	}

	/**
	 * Whether the body is read.
	 *
	 * @return bool
	 */
	public function stream_eof(): bool {
		return $this->position >= strlen(self::$body);
	}

	/**
	 * Stat the stream.
	 *
	 * @return array<int|string, int>
	 */
	public function stream_stat(): array {
		return [];
	}
}
