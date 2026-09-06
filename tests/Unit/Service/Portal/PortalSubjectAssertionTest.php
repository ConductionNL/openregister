<?php

/**
 * The X-Portal-Subject verifier: every defect denies, and the secret is
 * read in the published order.
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
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see PortalSubjectAssertion}.
 *
 * @covers \OCA\OpenRegister\Service\Portal\PortalSubjectAssertion
 * @covers \OCA\OpenRegister\Service\Portal\PortalSubject
 * @covers \OCA\OpenRegister\Exception\PortalSubjectException
 */
class PortalSubjectAssertionTest extends TestCase {

	/**
	 * The shared secret the tests sign with.
	 *
	 * @var string
	 */
	private const SECRET = 'a-secret-of-at-least-sixteen-characters';

	/**
	 * A verifier over a config with the given app values.
	 *
	 * @param string $own OpenRegister's own secret.
	 * @param string $portaliq Portaliq's secret.
	 *
	 * @return PortalSubjectAssertion The verifier.
	 */
	private function verifier(string $own = '', string $portaliq = self::SECRET): PortalSubjectAssertion {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($own, $portaliq): string {
				if ($app === 'openregister' && $key === 'portal_assertion_secret') {
					return $own;
				}

				if ($app === 'portaliq' && $key === 'jwt_signing_secret') {
					return $portaliq;
				}

				return $default;
			}
		);

		return new PortalSubjectAssertion(config: $config);
	}//end verifier()

	/**
	 * Mint an assertion the way portaliq does.
	 *
	 * @param array<string, mixed> $claims Claim overrides.
	 * @param string $secret The signing secret.
	 * @param array<string, mixed> $header Header overrides.
	 *
	 * @return string The compact JWT.
	 */
	private function token(array $claims = [], string $secret = self::SECRET, array $header = []): string {
		$header = array_merge(['alg' => 'HS256', 'typ' => 'JWT'], $header);
		$claims = array_merge(
			['sub' => 'sub-1', 'audience' => 'client', 'organisation' => 'org-1', 'trust' => 'substantial', 'jti' => 'sess-1', 'use' => 'assertion', 'iat' => time(), 'exp' => (time() + 60), 'iss' => 'portaliq'],
			$claims
		);
		$encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
		$h = $encode((string)json_encode($header));
		$c = $encode((string)json_encode($claims));

		return $h . '.' . $c . '.' . $encode(hash_hmac('sha256', $h . '.' . $c, $secret, true));
	}//end token()

	/**
	 * A request carrying the header.
	 *
	 * @param string $token The header value.
	 *
	 * @return IRequest The request.
	 */
	private function request(string $token): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('X-Portal-Subject')->willReturn($token);

		return $request;
	}//end request()

	/**
	 * A valid assertion resolves to the subject it names.
	 *
	 * @return void
	 */
	public function testAValidAssertionResolvesTheSubject(): void {
		$subject = $this->verifier()->resolve(request: $this->request($this->token()));
		$this->assertSame('sub-1', $subject->subjectRef);
		$this->assertSame('client', $subject->audience);
		$this->assertSame('org-1', $subject->organisation);
		$this->assertSame('substantial', $subject->trust);
		$this->assertSame('sess-1', $subject->jti);
		$this->assertSame('party:sub-1', $subject->partyReference());
	}//end testAValidAssertionResolvesTheSubject()

	/**
	 * OpenRegister's own secret wins over portaliq's when both are set.
	 *
	 * @return void
	 */
	public function testOpenRegistersOwnSecretIsConsultedFirst(): void {
		$verifier = $this->verifier(own: 'own-secret-sixteen-chars!', portaliq: self::SECRET);
		$this->assertSame('sub-1', $verifier->fromToken(token: $this->token(secret: 'own-secret-sixteen-chars!'))->subjectRef);

		$this->expectException(PortalSubjectException::class);
		$verifier->fromToken(token: $this->token(secret: self::SECRET));
	}//end testOpenRegistersOwnSecretIsConsultedFirst()

	/**
	 * Every defect denies with its code: missing header, no secret, bad
	 * signature, wrong algorithm, wrong issuer, a session token, expiry, no
	 * subject, malformed.
	 *
	 * @return void
	 */
	public function testEveryDefectDenies(): void {
		$verifier = $this->verifier();
		$cases = [
			[PortalSubjectException::CODE_MISSING, static fn (): string => ''],
			[PortalSubjectException::CODE_INVALID, fn (): string => $this->token(secret: 'the-wrong-secret-entirely')],
			[PortalSubjectException::CODE_INVALID, fn (): string => $this->token(header: ['alg' => 'none'])],
			[PortalSubjectException::CODE_INVALID, fn (): string => $this->token(claims: ['iss' => 'somebody'])],
			[PortalSubjectException::CODE_INVALID, fn (): string => $this->token(claims: ['use' => 'session'])],
			[PortalSubjectException::CODE_EXPIRED, fn (): string => $this->token(claims: ['exp' => (time() - 1)])],
			[PortalSubjectException::CODE_EXPIRED, fn (): string => $this->token(claims: ['exp' => null])],
			[PortalSubjectException::CODE_INVALID, fn (): string => $this->token(claims: ['sub' => ' '])],
			[PortalSubjectException::CODE_INVALID, static fn (): string => 'not.a-jwt'],
			[PortalSubjectException::CODE_INVALID, static fn (): string => 'a.b.c'],
		];

		foreach ($cases as [$code, $make]) {
			try {
				$verifier->resolve(request: $this->request($make()));
				$this->fail('Expected refusal ' . $code);
			} catch (PortalSubjectException $refused) {
				$this->assertSame($code, $refused->refusal(), $refused->getMessage());
			}
		}
	}//end testEveryDefectDenies()

	/**
	 * With no secret anywhere, a perfectly formed assertion is still refused.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredVerifierRefusesEverything(): void {
		try {
			$this->verifier(own: '', portaliq: '')->fromToken(token: $this->token());
			$this->fail('An unconfigured verifier admitted an assertion.');
		} catch (PortalSubjectException $refused) {
			$this->assertSame(PortalSubjectException::CODE_UNCONFIGURED, $refused->refusal());
		}
	}//end testAnUnconfiguredVerifierRefusesEverything()
}//end class
