<?php

/**
 * Reading what a connector call returned, whatever shape it came back in: a
 * decoded array, a call log with a (base64) body, an object log, an HTTP
 * response, a JSON-serialisable value or raw text; and the status, duration,
 * correlation id and headers read alongside it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Service\Integration\ConnectorResponseReader;
use PHPUnit\Framework\TestCase;

/**
 * ConnectorResponseReader.
 */
class ConnectorResponseReaderBehaviourTest extends TestCase {

	private ConnectorResponseReader $reader;

	protected function setUp(): void {
		$this->reader = new ConnectorResponseReader();
	}//end setUp()

	/**
	 * A call log exposing getResponse().
	 */
	private static function callLog(mixed $payload): object {
		return new class ($payload) {
			public function __construct(private mixed $payload) {
			}

			public function getResponse(): mixed {
				return $this->payload;
			}
		};
	}//end callLog()

	/**
	 * An object log exposing getObject().
	 *
	 * @param array<string, mixed> $object
	 */
	private static function objectLog(array $object): object {
		return new class ($object) {
			public function __construct(private array $object) {
			}

			public function getObject(): array {
				return $this->object;
			}
		};
	}//end objectLog()

	public function testAnArrayIsAlreadyDecoded(): void {
		$this->assertSame(['a' => 1], $this->reader->decode(['a' => 1]));
	}//end testAnArrayIsAlreadyDecoded()

	public function testACallLogBodyIsDecodedFromJsonOrBase64(): void {
		$this->assertSame(['ok' => true], $this->reader->decode(self::callLog(['body' => '{"ok":true}'])));
		$this->assertSame(
			['ok' => 'b64'],
			$this->reader->decode(self::callLog(['body' => base64_encode('{"ok":"b64"}'), 'encoding' => 'base64']))
		);
		$this->assertSame(['body' => 'plain text'], $this->reader->decode(self::callLog(['body' => 'plain text'])));
		$this->assertSame(['nested' => 1], $this->reader->decode(self::callLog(['body' => ['nested' => 1], 'encoding' => 'base64'])));
	}//end testACallLogBodyIsDecodedFromJsonOrBase64()

	public function testAPayloadWithoutABodyIsTheAnswerItself(): void {
		$this->assertSame(['statusCode' => 204], $this->reader->decode(self::callLog(['statusCode' => 204])));
	}//end testAPayloadWithoutABodyIsTheAnswerItself()

	public function testAnObjectLogCarriesItsPayloadUnderResponse(): void {
		$this->assertSame(['x' => 1], $this->reader->decode(self::objectLog(['response' => ['body' => '{"x":1}']])));
	}//end testAnObjectLogCarriesItsPayloadUnderResponse()

	public function testOtherShapesAreDecodedOrWrapped(): void {
		$serialisable = new class implements \JsonSerializable {
			public function jsonSerialize(): mixed {
				return ['s' => 1];
			}
		};
		$scalarSerialisable = new class implements \JsonSerializable {
			public function jsonSerialize(): mixed {
				return 'just text';
			}
		};

		$this->assertSame(['s' => 1], $this->reader->decode($serialisable));
		$this->assertSame(['body' => 'just text'], $this->reader->decode($scalarSerialisable));
		$this->assertSame(['j' => 2], $this->reader->decode('{"j":2}'));
		$this->assertSame(['body' => 'oops'], $this->reader->decode('oops'));
		$this->assertSame(['body' => 42], $this->reader->decode(42));
		$textLog = self::callLog('x');
		$this->assertSame(['body' => $textLog], $this->reader->decode($textLog));
		$this->assertSame(['body' => null], $this->reader->decode(self::callLog(['body' => null])));
	}//end testOtherShapesAreDecodedOrWrapped()

	public function testStatusComesFromTheHttpResponseOrTheLog(): void {
		$http = new class {
			public function getStatusCode(): string {
				return '201';
			}
		};

		$this->assertSame(201, $this->reader->status($http));
		$this->assertSame(404, $this->reader->status(self::callLog(['statusCode' => '404'])));
		$this->assertSame(502, $this->reader->status(self::objectLog(['statusCode' => 502])));
		$this->assertSame(0, $this->reader->status(self::callLog(['statusCode' => 'n/a'])));
		$this->assertSame(0, $this->reader->status(new \stdClass()));
		$this->assertSame(0, $this->reader->status(['statusCode' => 200]));
	}//end testStatusComesFromTheHttpResponseOrTheLog()

	public function testMetaReadsDurationCorrelationAndHeadersFromTheLog(): void {
		$meta = $this->reader->meta(
			self::callLog(
				[
					'statusCode' => 200,
					'responseTime' => '12.6',
					'headers' => ['x-correlation-id' => ['abc', 'def'], 'Content-Type' => 'application/json'],
				]
			)
		);

		$this->assertSame(
			[
				'status' => 200,
				'durationMs' => 13,
				'correlationId' => 'abc',
				'headers' => ['x-correlation-id' => 'abc', 'Content-Type' => 'application/json'],
			],
			$meta
		);
	}//end testMetaReadsDurationCorrelationAndHeadersFromTheLog()

	public function testMetaOfAPlainArrayAndTheEmptyCases(): void {
		$this->assertSame(
			['status' => 503, 'durationMs' => 0, 'correlationId' => null, 'headers' => ['X-Correlation-ID' => '']],
			$this->reader->meta(['statusCode' => 503, 'responseTime' => 'slow', 'headers' => ['X-Correlation-ID' => '']])
		);
		$this->assertSame(
			['status' => 0, 'durationMs' => 0, 'correlationId' => null, 'headers' => []],
			$this->reader->meta('text')
		);
		$this->assertSame(
			['status' => 0, 'durationMs' => 0, 'correlationId' => null, 'headers' => []],
			$this->reader->meta(['headers' => 'not-a-list'])
		);
		$this->assertNull($this->reader->meta(['headers' => ['X-Correlation-ID' => []]])['correlationId']);
	}//end testMetaOfAPlainArrayAndTheEmptyCases()

	public function testAnHttpStatusIsNotOverriddenByThePayload(): void {
		$http = new class {
			public function getStatusCode(): int {
				return 200;
			}

			public function getResponse(): array {
				return ['statusCode' => 500];
			}
		};

		$this->assertSame(200, $this->reader->meta($http)['status']);
	}//end testAnHttpStatusIsNotOverriddenByThePayload()

	public function testFlattenHeadersKeepsTheFirstValueOfEachList(): void {
		$this->assertSame(
			['a' => '1', 'b' => '', '7' => 'x'],
			$this->reader->flattenHeaders(['a' => ['1', '2'], 'b' => [], 7 => 'x'])
		);
	}//end testFlattenHeadersKeepsTheFirstValueOfEachList()
}//end class
