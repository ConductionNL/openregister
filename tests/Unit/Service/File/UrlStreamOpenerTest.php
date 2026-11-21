<?php

/**
 * UrlStreamOpener: what it hands back for a URL that opens and one that does not.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\UrlStreamOpener;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\File\UrlStreamOpener
 */
class UrlStreamOpenerTest extends TestCase {

	/**
	 * A URL that cannot be opened gives no stream and no status, without a warning.
	 *
	 * @return void
	 */
	public function testAUrlThatCannotBeOpenedGivesNothing(): void {
		$opened = (new UrlStreamOpener())->open(url: sys_get_temp_dir() . '/openregister-no-such-file-' . bin2hex(random_bytes(6)));

		self::assertSame(['stream' => null, 'status' => null], $opened);
	}//end testAUrlThatCannotBeOpenedGivesNothing()

	/**
	 * A stream whose wrapper carries no response headers is handed back readable, with no status.
	 *
	 * @return void
	 */
	public function testAStreamWithoutHeadersHasNoStatus(): void {
		stream_wrapper_register('orteststream', InMemoryTestStream::class);
		try {
			InMemoryTestStream::$body = 'besluit';
			$opened = (new UrlStreamOpener())->open(url: 'orteststream://document');

			self::assertIsResource($opened['stream']);
			self::assertNull($opened['status']);
			self::assertSame('besluit', stream_get_contents($opened['stream']));
			fclose($opened['stream']);
		} finally {
			stream_wrapper_unregister('orteststream');
		}
	}//end testAStreamWithoutHeadersHasNoStatus()

	/**
	 * Redirects are not followed and a non-2xx answer is still read, so the caller judges the status.
	 *
	 * @return void
	 */
	public function testTheContextNeitherFollowsRedirectsNorHidesErrors(): void {
		self::assertFalse(UrlStreamOpener::OPTIONS['http']['follow_location']);
		self::assertSame(0, UrlStreamOpener::OPTIONS['http']['max_redirects']);
		self::assertTrue(UrlStreamOpener::OPTIONS['http']['ignore_errors']);
		self::assertSame(30, UrlStreamOpener::OPTIONS['http']['timeout']);
	}//end testTheContextNeitherFollowsRedirectsNorHidesErrors()
}//end class

/**
 * A minimal readable stream wrapper; its wrapper data is the wrapper itself, not header lines.
 */
class InMemoryTestStream {

	/**
	 * What every stream of this wrapper reads.
	 *
	 * @var string
	 */
	public static string $body = '';

	/**
	 * The stream context PHP assigns.
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * How far the stream has been read.
	 *
	 * @var integer
	 */
	private int $position = 0;

	/**
	 * Open the stream.
	 *
	 * @param string      $path       The URL.
	 * @param string      $mode       The mode.
	 * @param int         $options    The options.
	 * @param string|null $openedPath Unused.
	 *
	 * @return bool
	 */
	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$this->position = 0;
		return true;
	}//end stream_open()

	/**
	 * Read from the stream.
	 *
	 * @param int $count Bytes asked for.
	 *
	 * @return string
	 */
	public function stream_read(int $count): string {
		$chunk = substr(self::$body, $this->position, $count);
		$this->position += strlen($chunk);
		return $chunk;
	}//end stream_read()

	/**
	 * Whether the stream is read to its end.
	 *
	 * @return bool
	 */
	public function stream_eof(): bool {
		return $this->position >= strlen(self::$body);
	}//end stream_eof()

	/**
	 * Stat the stream.
	 *
	 * @return array<int|string, int>
	 */
	public function stream_stat(): array {
		return ['size' => strlen(self::$body)];
	}//end stream_stat()
}//end class
