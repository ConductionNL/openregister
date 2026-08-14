<?php

/**
 * Relay a brokered upstream response to the client as it arrives.
 *
 * Nextcloud's dispatcher treats an `ICallbackResponse` differently from every
 * other response, and that difference is the whole reason this class exists
 * (`lib/private/AppFramework/App.php`):
 *
 *     if ($response instanceof ICallbackResponse) {
 *         $response->callback($io);              // called directly, headers already sent
 *     } elseif (!is_null($output)) {
 *         $io->setHeader('Content-Length: ' . strlen($output));
 *         $io->setOutput($output);               // the buffered arm
 *     }
 *
 * The callback arm writes straight through, and — just as important — sets no
 * `Content-Length`, which is what lets a response of unknown length be delivered
 * at all.
 *
 * MEASURED before this was written, because "it streams" is exactly the kind of
 * claim that is easy to assert and easy to be wrong about. This image runs
 * `output_buffering=0`, `implicit_flush=On`, `zlib.output_compression=Off`, and a
 * 60 MB download through the same Apache answered with
 * `TTFB 1.07s / TOTAL 9.54s` — first byte at 11% of the transfer. A buffering
 * stack would have put TTFB level with TOTAL.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Http
 * @package  OCA\OpenRegister\Http
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/credential-broker-service/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Http;

use OCA\OpenRegister\Service\Credential\BrokeredStream;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/**
 * Streams a `BrokeredStream` to the client chunk by chunk.
 *
 * @spec openspec/specs/credential-broker-service/spec.md
 */
class BrokeredStreamResponse extends Response implements ICallbackResponse {

	/**
	 * Upstream headers that must NOT be relayed.
	 *
	 * Each of these describes the UPSTREAM's transfer, not ours, and repeating it
	 * makes this response lie about its own body:
	 *
	 *  - `content-length` counts the upstream's bytes; ours are chunked and of
	 *    unknown length. A wrong length truncates the response at the client.
	 *  - `transfer-encoding` and `connection` are hop-by-hop by definition
	 *    (RFC 7230 §6.1) and belong to the connection that already ended.
	 *  - `content-encoding` would claim a compression this relay does not apply,
	 *    since the client library already decoded the upstream body for us.
	 *
	 * @var string[]
	 */
	private const HOP_BY_HOP = [
		'content-length',
		'transfer-encoding',
		'connection',
		'content-encoding',
		'keep-alive',
	];

	/**
	 * Constructor.
	 *
	 * @param BrokeredStream $stream The authorised upstream response, body unread.
	 */
	public function __construct(private readonly BrokeredStream $stream) {
		parent::__construct();

		$this->setStatus(status: $stream->getStatus());

		foreach ($stream->getHeaders() as $name => $values) {
			if (in_array(needle: strtolower((string)$name), haystack: self::HOP_BY_HOP, strict: true) === true) {
				continue;
			}

			$this->addHeader(name: (string)$name, value: implode(separator: ', ', array: (array)$values));
		}

		// A reverse proxy in front of Nextcloud will happily buffer a response it
		// considers small enough to be worth buffering, which turns a stream back
		// into a wait. nginx honours this header; deployments without one ignore
		// it harmlessly.
		//
		// ⚠️ mod_deflate IS enabled on this image and compressing a stream would
		// re-buffer it — but MEASURED, its configured types are
		// `text/html text/plain text/xml text/css text/javascript`, the
		// javascript/xml/rss/wasm application types, and nothing else.
		// `text/event-stream` is not among them, so SSE is not compressed here
		// and no `no-gzip` opt-out is needed. If a deployment adds it to
		// `AddOutputFilterByType`, that opt-out becomes necessary and its absence
		// will show as latency rather than as an error.
		$this->addHeader(name: 'X-Accel-Buffering', value: 'no');

	}//end __construct()

	/**
	 * Write the upstream body out as it arrives.
	 *
	 * `flush()` after every chunk is the load-bearing line. PHP is configured
	 * here not to buffer, but a caller running under a different SAPI or an
	 * operator who turns `output_buffering` on would otherwise silently get the
	 * buffered behaviour back — and the symptom would be latency, not an error.
	 *
	 * @param IOutput $output Nextcloud's output wrapper.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/credential-broker-service/spec.md
	 */
	public function callback(IOutput $output): void {
		$this->stream->pump(
			static function (string $chunk) use ($output): void {
				$output->setOutput($chunk);
				flush();
			}
		);

	}//end callback()
}//end class
