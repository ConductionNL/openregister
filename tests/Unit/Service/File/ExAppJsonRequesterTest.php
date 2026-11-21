<?php

/**
 * ExAppJsonRequester: every failure is an exception with the reason.
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
use OCA\OpenRegister\Service\File\ExAppJsonRequester;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\File\ExAppJsonRequester
 */
final class ExAppJsonRequesterTest extends TestCase {

	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		return $response;
	}//end response()

	public function testWithoutAppApiTheRequestFailsAndSaysSo(): void {
		if (class_exists(ExAppJsonRequester::PUBLIC_FUNCTIONS) === true) {
			$this->markTestSkipped('AppAPI is installed here.');
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('AppAPI is not available, so anonymiq cannot be reached.');

		(new ExAppJsonRequester())->request('anonymiq', '/api/v1/image/regions', []);
	}//end testWithoutAppApiTheRequestFailsAndSaysSo()

	public function testAJsonObjectIsDecoded(): void {
		$this->assertSame(['regions' => []], (new ExAppJsonRequester())->decode($this->response(200, '{"regions":[]}'), 'anonymiq'));
	}//end testAJsonObjectIsDecoded()

	public function testANonSuccessStatusIsRefused(): void {
		$this->expectExceptionMessage('anonymiq answered HTTP 422.');

		(new ExAppJsonRequester())->decode($this->response(422, '{}'), 'anonymiq');
	}//end testANonSuccessStatusIsRefused()

	public function testABodyThatIsNotAnObjectIsRefused(): void {
		$this->expectExceptionMessage('anonymiq answered something that is not a JSON object.');

		(new ExAppJsonRequester())->decode($this->response(200, 'ok'), 'anonymiq');
	}//end testABodyThatIsNotAnObjectIsRefused()
}//end class
