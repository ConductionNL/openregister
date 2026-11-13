<?php

/**
 * UrlFileFetcher: the address guard, one request, the ceiling while reading, and the reasons.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\File\UrlFetchRefused;
use OCA\OpenRegister\Service\File\UrlFileFetcher;
use OCA\OpenRegister\Service\File\UrlStreamOpener;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The opener is a stub serving an in-memory body; the address guard is the real one.
 *
 * @covers \OCA\OpenRegister\Service\File\UrlFileFetcher
 * @covers \OCA\OpenRegister\Service\File\UrlFetchRefused
 * @uses   \OCA\OpenRegister\Service\SecurityService
 * @uses   \OCA\OpenRegister\Service\Security\SafeFetchUrl
 * @uses   \OCA\OpenRegister\Service\Security\OutboundAddressGuard
 */
class UrlFileFetcherTest extends TestCase {

	/**
	 * A public address, so the guard needs no name lookup.
	 */
	private const PUBLIC_URL = 'https://93.184.215.14/besluit.pdf';

	private UrlStreamOpener&MockObject $opener;

	protected function setUp(): void {
		$this->opener = $this->createMock(UrlStreamOpener::class);
	}

	/**
	 * Serve one answer.
	 *
	 * @param int|null $status The status.
	 * @param string   $body   The body.
	 *
	 * @return resource
	 */
	private function serve(?int $status, string $body) {
		$stream = fopen('php://memory', 'w+b');
		fwrite($stream, $body);
		rewind($stream);
		$this->opener->expects($this->once())->method('open')->with(self::PUBLIC_URL)->willReturn(['stream' => $stream, 'status' => $status]);
		return $stream;
	}

	private function refusal(string $url, int $maxBytes=1000): UrlFetchRefused {
		try {
			(new UrlFileFetcher($this->opener))->fetch($url, $maxBytes);
		} catch (UrlFetchRefused $refusal) {
			return $refusal;
		}

		$this->fail('the fetch should have been refused');
	}

	public function testTheBodyIsReturned(): void {
		$stream = $this->serve(200, '%PDF-1.4 besluit');

		$this->assertSame('%PDF-1.4 besluit', (new UrlFileFetcher($this->opener))->fetch(self::PUBLIC_URL, 1000));
		$this->assertFalse(is_resource($stream), 'the stream is closed');
	}

	public function testAPrivateAddressIsRefused(): void {
		$this->opener->expects($this->never())->method('open');

		foreach (['http://169.254.169.254/latest/meta-data', 'http://127.0.0.1/x', 'http://10.0.0.5/x', 'file:///etc/passwd'] as $url) {
			$this->assertSame(UrlFetchRefused::REFUSED_ADDRESS, $this->refusal($url)->reason, $url);
		}
	}

	public function testARedirectIsNotFollowed(): void {
		$this->serve(302, '');

		$refusal = $this->refusal(self::PUBLIC_URL);

		$this->assertSame(UrlFetchRefused::STATUS, $refusal->reason);
		$this->assertSame('The URL answered with status 302.', $refusal->getMessage());
	}

	public function testANon2xxIsReported(): void {
		$this->serve(404, 'not here');

		$this->assertSame(UrlFetchRefused::STATUS, $this->refusal(self::PUBLIC_URL)->reason);
	}

	public function testAnUnknownStatusIsReported(): void {
		$this->serve(null, 'body');

		$this->assertSame('The URL answered with status unknown.', $this->refusal(self::PUBLIC_URL)->getMessage());
	}

	public function testTheCeilingStopsTheReadNotTheWrite(): void {
		$this->serve(200, str_repeat('x', 20000));

		$refusal = $this->refusal(self::PUBLIC_URL, 10000);

		$this->assertSame(UrlFetchRefused::TOO_LARGE, $refusal->reason);
		$this->assertSame('The file is larger than 10000 bytes.', $refusal->getMessage());
	}

	public function testABodyAtTheCeilingIsAccepted(): void {
		$this->serve(200, str_repeat('x', 10000));

		$this->assertSame(10000, strlen((new UrlFileFetcher($this->opener))->fetch(self::PUBLIC_URL, 10000)));
	}

	public function testAnEmptyBodyIsReported(): void {
		$this->serve(200, '');

		$this->assertSame(UrlFetchRefused::EMPTY, $this->refusal(self::PUBLIC_URL)->reason);
	}

	public function testAnUnreachableUrlIsATimeout(): void {
		$this->opener->method('open')->willReturn(['stream' => null, 'status' => null]);

		$this->assertSame(UrlFetchRefused::TIMEOUT, $this->refusal(self::PUBLIC_URL)->reason);
	}

	public function testTheOpenerNeverFollowsRedirectsAndReadsEveryStatus(): void {
		$this->assertFalse(UrlStreamOpener::OPTIONS['http']['follow_location']);
		$this->assertSame(0, UrlStreamOpener::OPTIONS['http']['max_redirects']);
		$this->assertSame(30, UrlStreamOpener::OPTIONS['http']['timeout']);
		$this->assertTrue(UrlStreamOpener::OPTIONS['http']['ignore_errors']);
	}
}
