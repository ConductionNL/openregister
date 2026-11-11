<?php

/**
 * Every refusal of the portal subject assertion, each paired with the
 * accepted assertion it differs from by one input.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Portal;

use OCA\OpenRegister\Exception\PortalSubjectException;
use OCA\OpenRegister\Service\Portal\PortalSubjectAssertion;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Refusal tests for {@see PortalSubjectAssertion}.
 */
class PortalSubjectAssertionRefusalTest extends TestCase {

	/**
	 * The shared secret.
	 *
	 * @var string
	 */
	private const SECRET = 'refusal-test-secret-0123456789';

	/**
	 * A verifier over the given secrets.
	 *
	 * @param string $own OpenRegister's own secret.
	 * @param string $portaliq The portaliq fallback secret.
	 *
	 * @return PortalSubjectAssertion The verifier.
	 */
	private function verifier(string $own = self::SECRET, string $portaliq = ''): PortalSubjectAssertion {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '', bool $lazy = false) use ($own, $portaliq): string {
				return match ($app . '/' . $key) {
					'openregister/portal_assertion_secret' => $own,
					'portaliq/jwt_signing_secret' => $portaliq,
					default => $default,
				};
			}
		);

		return new PortalSubjectAssertion(config: $config);
	}//end verifier()

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes The bytes.
	 *
	 * @return string The encoding.
	 */
	private static function b64(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}//end b64()

	/**
	 * A token over raw header and claim segments, signed with the secret.
	 *
	 * @param string $header The raw header JSON.
	 * @param string $claims The raw claims JSON.
	 * @param string $secret The signing secret.
	 *
	 * @return string The token.
	 */
	private static function sign(string $header, string $claims, string $secret = self::SECRET): string {
		$head = self::b64($header) . '.' . self::b64($claims);

		return $head . '.' . self::b64(hash_hmac('sha256', $head, $secret, true));
	}//end sign()

	/**
	 * A valid assertion, with claim overrides (null removes a claim).
	 *
	 * @param array<string, mixed> $overrides Claim overrides.
	 *
	 * @return string The token.
	 */
	private static function token(array $overrides = []): string {
		$claims = array_merge(
			['iss' => 'portaliq', 'use' => 'assertion', 'exp' => time() + 600, 'sub' => 'bsn-1', 'audience' => ' burger ', 'organisation' => 'org-1', 'trust' => 'high', 'jti' => 'j-1'],
			$overrides
		);

		return self::sign(header: '{"alg":"HS256","typ":"JWT"}', claims: (string)json_encode(array_filter($claims, static fn ($v) => $v !== null)));
	}//end token()

	/**
	 * Assert the token is refused with the code and message.
	 *
	 * @param PortalSubjectAssertion $verifier The verifier.
	 * @param string $token The token.
	 * @param string $code The expected refusal code.
	 * @param string $message The expected message.
	 *
	 * @return void
	 */
	private function assertRefused(PortalSubjectAssertion $verifier, string $token, string $code, string $message): void {
		try {
			$verifier->fromToken(token: $token);
			$this->fail('Accepted: ' . $message);
		} catch (PortalSubjectException $refused) {
			$this->assertSame([$code, $message], [$refused->refusal(), $refused->getMessage()]);
		}
	}//end assertRefused()

	/**
	 * A valid assertion yields its subject with trimmed claims.
	 *
	 * @return void
	 */
	public function testAValidAssertionYieldsItsSubject(): void {
		$subject = $this->verifier()->fromToken(token: self::token(['sub' => ' bsn-9 ']));

		$this->assertSame('bsn-9', $subject->subjectRef);
		$this->assertSame(['burger', 'org-1', 'high', 'j-1'], [$subject->audience, $subject->organisation, $subject->trust, $subject->jti]);
	}//end testAValidAssertionYieldsItsSubject()

	/**
	 * Absent optional claims read as empty strings.
	 *
	 * @return void
	 */
	public function testAbsentOptionalClaimsReadEmpty(): void {
		$subject = $this->verifier()->fromToken(token: self::token(['audience' => null, 'organisation' => null, 'trust' => null, 'jti' => null]));

		$this->assertSame(['', '', '', ''], [$subject->audience, $subject->organisation, $subject->trust, $subject->jti]);
	}//end testAbsentOptionalClaimsReadEmpty()

	/**
	 * The request header is trimmed and verified; an empty one is refused as missing.
	 *
	 * @return void
	 */
	public function testResolveReadsTheHeaderAndRefusesAnEmptyOne(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('X-Portal-Subject')->willReturnOnConsecutiveCalls('  ' . self::token() . ' ', '   ');

		$this->assertSame('bsn-1', $this->verifier()->resolve(request: $request)->subjectRef);
		try {
			$this->verifier()->resolve(request: $request);
			$this->fail('An empty header was accepted.');
		} catch (PortalSubjectException $refused) {
			$this->assertSame(PortalSubjectException::CODE_MISSING, $refused->refusal());
		}
	}//end testResolveReadsTheHeaderAndRefusesAnEmptyOne()

	/**
	 * With no own secret the portaliq secret verifies; with neither, all is refused.
	 *
	 * @return void
	 */
	public function testSecretFallsBackToPortaliqAndRefusesWhenNoneIsSet(): void {
		$fallback = $this->verifier(own: '  ', portaliq: self::SECRET);
		$this->assertSame('bsn-1', $fallback->fromToken(token: self::token())->subjectRef);

		$this->assertRefused(
			verifier: $this->verifier(own: '', portaliq: ' '),
			token: self::token(),
			code: PortalSubjectException::CODE_UNCONFIGURED,
			message: 'No portal assertion secret is configured; every assertion is refused.'
		);
	}//end testSecretFallsBackToPortaliqAndRefusesWhenNoneIsSet()

	/**
	 * The own secret wins over the portaliq one.
	 *
	 * @return void
	 */
	public function testTheOwnSecretWinsOverTheFallback(): void {
		$this->assertRefused(
			verifier: $this->verifier(own: 'another-secret-entirely', portaliq: self::SECRET),
			token: self::token(),
			code: PortalSubjectException::CODE_INVALID,
			message: 'Assertion signature does not verify.'
		);
	}//end testTheOwnSecretWinsOverTheFallback()

	/**
	 * Structural defects are refused as invalid, each with its own message.
	 *
	 * @return void
	 */
	public function testStructuralDefectsAreRefused(): void {
		$verifier = $this->verifier();
		$invalid = PortalSubjectException::CODE_INVALID;
		[$head, $claims] = explode('.', self::token());

		$this->assertRefused($verifier, 'only.two', $invalid, 'Malformed assertion.');
		$this->assertRefused($verifier, self::sign('{"alg":"none"}', '{}'), $invalid, 'Unsupported assertion algorithm.');
		$this->assertRefused($verifier, self::sign('"HS256"', '{}'), $invalid, 'Unsupported assertion algorithm.');
		$this->assertRefused($verifier, '!!!.' . $claims . '.sig', $invalid, 'Unsupported assertion algorithm.');
		$this->assertRefused($verifier, $head . '.' . $claims . '.forged', $invalid, 'Assertion signature does not verify.');
		$this->assertRefused($verifier, self::sign('{"alg":"HS256"}', 'not json'), $invalid, 'Malformed assertion claims.');
	}//end testStructuralDefectsAreRefused()

	/**
	 * Each wrong claim is refused with its own code and message.
	 *
	 * @return void
	 */
	public function testEachWrongClaimIsRefused(): void {
		$verifier = $this->verifier();
		$invalid = PortalSubjectException::CODE_INVALID;
		$expired = PortalSubjectException::CODE_EXPIRED;

		$this->assertRefused($verifier, self::token(['iss' => 'elsewhere']), $invalid, 'Unexpected assertion issuer.');
		$this->assertRefused($verifier, self::token(['use' => 'session']), $invalid, 'Token is not a subject assertion.');
		$this->assertRefused($verifier, self::token(['exp' => null]), $expired, 'Assertion expired or carries no expiry.');
		$this->assertRefused($verifier, self::token(['exp' => time() - 1]), $expired, 'Assertion expired or carries no expiry.');
		$this->assertRefused($verifier, self::token(['sub' => '  ']), $invalid, 'Assertion names no subject.');
	}//end testEachWrongClaimIsRefused()
}//end class
