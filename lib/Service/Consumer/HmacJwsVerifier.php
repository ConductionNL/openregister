<?php

/**
 * HS* signature checks for a JWT, against a shared secret.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consumer;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\Algorithm\HS384;
use Jose\Component\Signature\Algorithm\HS512;
use Jose\Component\Signature\Algorithm\MacAlgorithm;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Throwable;

/**
 * Verifies an HS256/384/512 compact JWS with web-token.
 *
 * Only the pinned algorithm is loaded into the verifier, so the token's
 * header cannot select another one. web-token also refuses a secret shorter
 * than the hash output; JwtValidator checks that first so it can name the
 * reason, because JWSVerifier turns the library's exception into false.
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */
class HmacJwsVerifier {

	/**
	 * Whether the token verifies with the shared secret under the pinned algorithm.
	 *
	 * @param string $token     The compact JWS.
	 * @param string $algorithm The pinned algorithm (HS256, HS384 or HS512).
	 * @param string $key       The shared secret, as raw bytes.
	 *
	 * @return bool True when the signature is valid; false for anything else, including a secret that is too short.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function verify(string $token, string $algorithm, string $key): bool {
		$verifierAlgorithm = $this->hmacAlgorithm(algorithm: $algorithm);
		if ($verifierAlgorithm === null) {
			return false;
		}

		try {
			$jwk = $this->sharedSecret(key: $key, algorithm: $algorithm);
			$jws = (new CompactSerializer())->unserialize(input: $token);
			return (new JWSVerifier(new AlgorithmManager([$verifierAlgorithm])))->verifyWithKey(jws: $jws, jwk: $jwk, signature: 0);
		} catch (Throwable $e) {
			return false;
		}
	}//end verify()

	/**
	 * The verifier algorithm for an HS* name, or null.
	 *
	 * @param string $algorithm The pinned algorithm.
	 *
	 * @return MacAlgorithm|null
	 */
	private function hmacAlgorithm(string $algorithm): ?MacAlgorithm {
		return match ($algorithm) {
			'HS256' => new HS256(),
			'HS384' => new HS384(),
			'HS512' => new HS512(),
			default => null,
		};
	}//end hmacAlgorithm()

	/**
	 * The shared secret as an "oct" JWK pinned to the algorithm.
	 *
	 * The same JWK as JWKFactory::createFromSecret() builds: `k` is the
	 * base64url of the raw secret bytes.
	 *
	 * @param string $key       The shared secret.
	 * @param string $algorithm The pinned algorithm.
	 *
	 * @return JWK
	 */
	private function sharedSecret(string $key, string $algorithm): JWK {
		return new JWK(
			[
				'kty' => 'oct',
				'k' => rtrim(strtr(base64_encode($key), '+/', '-_'), '='),
				'alg' => $algorithm,
				'use' => 'sig',
			]
		);
	}//end sharedSecret()
}//end class
