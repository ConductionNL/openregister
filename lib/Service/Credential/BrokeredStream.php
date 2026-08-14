<?php

/**
 * An upstream response the broker has opened but not yet read.
 *
 * `CredentialBrokerService::request()` answers with the whole body as a string,
 * which is right for the calls the broker was built for — a label write, a tree
 * commit, a pull request — where the response is small and the caller wants it
 * in one piece.
 *
 * It is wrong for a streaming API. A model completion arrives as server-sent
 * events over minutes; buffering it means the caller waits for the last token to
 * see the first, and a multi-minute PHP request sits in the path holding the
 * whole generation in memory. Worse, the failure only appears on the LONG
 * responses — a short test passes and says nothing about the case that matters.
 *
 * So this object is handed back INSTEAD of a body: the status and headers are
 * known (they arrived with the response head), and the body is still open.
 * `pump()` reads it in chunks and hands each one to a sink as it arrives.
 *
 * 🔑 THE GUARDS HAVE ALREADY RUN by the time this exists. It is constructed only
 * by `CredentialBrokerService::streamRequest()`, after the same access,
 * allowed-app, provider, allow-rule and host-lock checks `request()` performs —
 * they are one shared method, not two copies. Holding one of these is holding an
 * ALREADY-AUTHORISED response, not a licence to make one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
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

namespace OCA\OpenRegister\Service\Credential;

/**
 * A brokered upstream response whose body is still open.
 *
 * @spec openspec/specs/credential-broker-service/spec.md
 */
final class BrokeredStream {

	/**
	 * How much is read from the upstream body per pass.
	 *
	 * Small on purpose. An SSE frame is a few hundred bytes, and the whole point
	 * of this class is that a frame reaches the caller when it arrives rather
	 * than when the response ends — a large buffer would reintroduce exactly the
	 * latency it exists to remove, while looking like it streamed.
	 *
	 * @var integer
	 */
	private const CHUNK_BYTES = 8192;

	/**
	 * The still-open upstream body, or null once `pump()` has taken it.
	 *
	 * 🔑 NULLABLE ON PURPOSE, and not merely to satisfy a type-checker.
	 * `pump()` hands the handle to a local and leaves null behind BEFORE it reads
	 * a byte, so the object never holds a resource it has closed. That is what
	 * makes a second `pump()` a no-op instead of a read against a dead handle.
	 *
	 * Declared here rather than promoted in the constructor because a promoted
	 * property takes the parameter's type, and the parameter is a resource — it
	 * is never null on the way IN. The two are genuinely different types, which
	 * is what PHPStan pointed out.
	 *
	 * @var resource|null
	 */
	private $body;

	/**
	 * Constructor.
	 *
	 * @param int                   $status  The upstream status code.
	 * @param array<string, mixed>  $headers The upstream response headers.
	 * @param resource              $body    The still-open upstream body.
	 */
	public function __construct(
		private readonly int $status,
		private readonly array $headers,
		$body,
	) {
		$this->body = $body;

	}//end __construct()

	/**
	 * The upstream status code.
	 *
	 * @return int The status code.
	 *
	 * @spec openspec/specs/credential-broker-service/spec.md
	 */
	public function getStatus(): int {
		return $this->status;

	}//end getStatus()

	/**
	 * The upstream response headers.
	 *
	 * @return array<string, mixed> The headers, as the upstream sent them.
	 *
	 * @spec openspec/specs/credential-broker-service/spec.md
	 */
	public function getHeaders(): array {
		return $this->headers;

	}//end getHeaders()

	/**
	 * Read the body to its end, handing each chunk to the sink as it arrives.
	 *
	 * The sink is called with raw bytes and nothing else — no framing, no
	 * parsing, no re-encoding. Whatever the upstream is speaking travels through
	 * unaltered, which is what lets one implementation carry SSE, chunked JSON,
	 * or anything else a provider invents later.
	 *
	 * ⚠️ The stream is CLOSED when this returns, including when the sink throws.
	 * A brokered call that leaks its socket would hold an upstream connection for
	 * the life of the PHP worker.
	 *
	 * @param callable $sink Receives each chunk of bytes as it is read.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/credential-broker-service/spec.md
	 */
	public function pump(callable $sink): void {
		// Taken into a LOCAL and the property surrendered before a byte is read.
		// Two reasons, and the second is the one that matters: a second `pump()`
		// on the same object then reads nothing rather than reading from a closed
		// handle, and the property never holds a `closed-resource` — which is
		// both a real bug class and what psalm objects to.
		$body = $this->body;
		$this->body = null;

		if (is_resource($body) === false) {
			return;
		}

		try {
			while (feof(stream: $body) === false) {
				$chunk = fread(stream: $body, length: self::CHUNK_BYTES);

				// `false` is a read ERROR and `''` is merely nothing-yet on a
				// non-blocking socket; neither is the end of the body, and only
				// `feof()` says that. Treating `''` as the end would truncate a
				// slow generation the moment it paused to think.
				if ($chunk === false) {
					break;
				}

				if ($chunk === '') {
					continue;
				}

				$sink($chunk);
			}
		} finally {
			if (is_resource($body) === true) {
				fclose(stream: $body);
			}
		}

	}//end pump()
}//end class
