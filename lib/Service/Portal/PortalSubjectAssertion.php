<?php

/**
 * Verifies the `X-Portal-Subject` assertion portaliq forwards with a
 * server-to-server action, and turns it into a {@see PortalSubject}.
 *
 * The assertion is portaliq's contract-v2 A6 shape: a compact HS256 JWT,
 * issuer `portaliq`, `use: assertion`, sixty seconds of life, carrying the
 * server-derived `sub` (subjectRef), `audience`, `organisation`, `trust` and
 * the originating session's `jti`. The client's own bearer never reaches
 * this side of the seam, so a session token presented here is refused by the
 * `use` claim alone.
 *
 * THE SECRET. The same HMAC secret signs on both sides. OpenRegister reads
 * its own `portal_assertion_secret` first, so an operator can pin it, and
 * falls back to portaliq's `jwt_signing_secret` because the two apps share
 * an instance by contract (the forward is instance-local). With neither set,
 * every assertion is refused: an unconfigured verifier that admitted would
 * be the fail-open this class exists to prevent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Portal;

use OCA\OpenRegister\Exception\PortalSubjectException;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Resolves the acting portal subject from a request, fail-closed.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
 */
class PortalSubjectAssertion {

	/**
	 * The header the assertion travels in.
	 *
	 * @var string
	 */
	public const HEADER = 'X-Portal-Subject';

	/**
	 * OpenRegister's own secret key, consulted first.
	 *
	 * @var string
	 */
	public const CONFIG_SECRET = 'portal_assertion_secret';

	/**
	 * The issuer portaliq stamps.
	 *
	 * @var string
	 */
	private const ISSUER = 'portaliq';

	/**
	 * The `use` claim that marks an assertion, as opposed to a session.
	 *
	 * @var string
	 */
	private const USE_ASSERTION = 'assertion';

	/**
	 * Constructor.
	 *
	 * @param IConfig $config Where the shared secret is read from.
	 */
	public function __construct(
		private readonly IConfig $config,
	) {

	}//end __construct()

	/**
	 * The subject a request acts as.
	 *
	 * @param IRequest $request The incoming request.
	 *
	 * @return PortalSubject The verified subject.
	 *
	 * @throws PortalSubjectException When the header is missing, the verifier is
	 *                                unconfigured, or the token is invalid or expired.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
	 */
	public function resolve(IRequest $request): PortalSubject {
		$token = trim($request->getHeader(self::HEADER));
		if ($token === '') {
			throw new PortalSubjectException(
				refusal: PortalSubjectException::CODE_MISSING,
				message: 'No X-Portal-Subject assertion on the request.'
			);
		}

		return $this->fromToken(token: $token);
	}//end resolve()

	/**
	 * Verify a compact assertion and read its subject.
	 *
	 * @param string $token The compact JWT.
	 *
	 * @return PortalSubject The verified subject.
	 *
	 * @throws PortalSubjectException On any defect; the code says which class.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
	 */
	public function fromToken(string $token): PortalSubject {
		$secret = $this->secret();
		if ($secret === '') {
			throw new PortalSubjectException(
				refusal: PortalSubjectException::CODE_UNCONFIGURED,
				message: 'No portal assertion secret is configured; every assertion is refused.'
			);
		}

		$parts = explode('.', $token);
		if (count($parts) !== 3) {
			throw new PortalSubjectException(refusal: PortalSubjectException::CODE_INVALID, message: 'Malformed assertion.');
		}

		[$headerPart, $claimsPart, $signaturePart] = $parts;
		$header = json_decode($this->b64UrlDecode(encoded: $headerPart), true);
		if (is_array($header) === false || ($header['alg'] ?? '') !== 'HS256') {
			throw new PortalSubjectException(refusal: PortalSubjectException::CODE_INVALID, message: 'Unsupported assertion algorithm.');
		}

		$expected = $this->b64UrlEncode(bytes: hash_hmac('sha256', $headerPart . '.' . $claimsPart, $secret, true));
		if (hash_equals($expected, $signaturePart) === false) {
			throw new PortalSubjectException(refusal: PortalSubjectException::CODE_INVALID, message: 'Assertion signature does not verify.');
		}

		$claims = json_decode($this->b64UrlDecode(encoded: $claimsPart), true);
		if (is_array($claims) === false) {
			throw new PortalSubjectException(refusal: PortalSubjectException::CODE_INVALID, message: 'Malformed assertion claims.');
		}

		if (($claims['iss'] ?? '') !== self::ISSUER) {
			throw new PortalSubjectException(refusal: PortalSubjectException::CODE_INVALID, message: 'Unexpected assertion issuer.');
		}

		if (($claims['use'] ?? '') !== self::USE_ASSERTION) {
			// A session token is not an assertion; presenting one here is the
			// token-confusion case the `use` claim exists to refuse.
			throw new PortalSubjectException(refusal: PortalSubjectException::CODE_INVALID, message: 'Token is not a subject assertion.');
		}

		if (isset($claims['exp']) === false || (int)$claims['exp'] < time()) {
			throw new PortalSubjectException(refusal: PortalSubjectException::CODE_EXPIRED, message: 'Assertion expired or carries no expiry.');
		}

		$subjectRef = trim((string)($claims['sub'] ?? ''));
		if ($subjectRef === '') {
			throw new PortalSubjectException(refusal: PortalSubjectException::CODE_INVALID, message: 'Assertion names no subject.');
		}

		return new PortalSubject(
			subjectRef: $subjectRef,
			audience: trim((string)($claims['audience'] ?? '')),
			organisation: trim((string)($claims['organisation'] ?? '')),
			trust: trim((string)($claims['trust'] ?? '')),
			jti: trim((string)($claims['jti'] ?? ''))
		);
	}//end fromToken()

	/**
	 * The shared secret: OpenRegister's own key, else portaliq's.
	 *
	 * @return string The secret, or '' when neither is set.
	 */
	private function secret(): string {
		$own = trim((string)$this->config->getAppValue('openregister', self::CONFIG_SECRET, ''));
		if ($own !== '') {
			return $own;
		}

		return trim((string)$this->config->getAppValue('portaliq', 'jwt_signing_secret', ''));
	}//end secret()

	/**
	 * URL-safe base64, unpadded.
	 *
	 * @param string $bytes The bytes.
	 *
	 * @return string The encoding.
	 */
	private function b64UrlEncode(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}//end b64UrlEncode()

	/**
	 * The inverse of {@see b64UrlEncode()}.
	 *
	 * @param string $encoded The encoding.
	 *
	 * @return string The bytes; '' on a malformed input.
	 */
	private function b64UrlDecode(string $encoded): string {
		$padded = strtr($encoded, '-_', '+/');
		$padded .= str_repeat('=', (4 - (strlen($padded) % 4)) % 4);
		$decoded = base64_decode($padded, true);
		if ($decoded === false) {
			return '';
		}

		return $decoded;
	}//end b64UrlDecode()
}//end class
