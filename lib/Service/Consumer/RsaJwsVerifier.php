<?php

/**
 * RS* and PS* signature checks for a JWT, against an RSA public key.
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
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\PS384;
use Jose\Component\Signature\Algorithm\PS512;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\Algorithm\SignatureAlgorithm;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Throwable;

/**
 * Verifies an RS256/384/512 or PS256/384/512 compact JWS.
 *
 * Only the pinned algorithm is loaded into the verifier, so the token's
 * header cannot select another one.
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */
class RsaJwsVerifier {

	/**
	 * Whether the token verifies with the RSA public key under the pinned algorithm.
	 *
	 * @param string $token The compact JWS.
	 * @param JwsKey $key   The pinned algorithm (RS* or PS*) and a PEM public key, or base64 of one.
	 *
	 * @return bool True when the signature is valid; false for anything else, including a key that is not RSA.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function verify(string $token, JwsKey $key): bool {
		$verifierAlgorithm = $this->asymmetricAlgorithm(algorithm: $key->algorithm);
		$jwk = $this->rsaPublicKey(key: $key->material, algorithm: $key->algorithm);
		if ($verifierAlgorithm === null || $jwk === null) {
			return false;
		}

		try {
			$jws = (new CompactSerializer())->unserialize(input: $token);
			return (new JWSVerifier(new AlgorithmManager([$verifierAlgorithm])))->verifyWithKey(jws: $jws, jwk: $jwk, signature: 0);
		} catch (Throwable $e) {
			return false;
		}
	}//end verify()

	/**
	 * The verifier algorithm for an RS* or PS* name, or null.
	 *
	 * @param string $algorithm The pinned algorithm.
	 *
	 * @return SignatureAlgorithm|null
	 */
	private function asymmetricAlgorithm(string $algorithm): ?SignatureAlgorithm {
		return match ($algorithm) {
			'RS256' => new RS256(),
			'RS384' => new RS384(),
			'RS512' => new RS512(),
			'PS256' => new PS256(),
			'PS384' => new PS384(),
			'PS512' => new PS512(),
			default => null,
		};
	}//end asymmetricAlgorithm()

	/**
	 * An RSA public key (PEM, or base64 of a PEM) as a JWK pinned to the algorithm.
	 *
	 * @param string $key       The key.
	 * @param string $algorithm The pinned algorithm.
	 *
	 * @return JWK|null The key, or null when it is not an RSA public key.
	 */
	private function rsaPublicKey(string $key, string $algorithm): ?JWK {
		$rsa = $this->rsaComponents(key: $key);
		if ($rsa === null) {
			return null;
		}

		return new JWK(
			[
				'kty' => 'RSA',
				'n' => $this->base64Url(raw: $rsa['n']),
				'e' => $this->base64Url(raw: $rsa['e']),
				'alg' => $algorithm,
				'use' => 'sig',
			]
		);
	}//end rsaPublicKey()

	/**
	 * The modulus and exponent of an RSA public key, or null when the key is not one.
	 *
	 * @param string $key The PEM, or its base64-encoded form.
	 *
	 * @return array{n: string, e: string}|null The raw RSA components.
	 */
	private function rsaComponents(string $key): ?array {
		$resource = openssl_pkey_get_public($this->pemOf(key: $key));
		if ($resource === false) {
			return null;
		}

		$rsa = (openssl_pkey_get_details($resource)['rsa'] ?? null);
		if (is_array($rsa) === false || isset($rsa['n'], $rsa['e']) === false) {
			return null;
		}

		return $rsa;
	}//end rsaComponents()

	/**
	 * A key as PEM: as given when it carries a PEM header, else base64-decoded.
	 *
	 * @param string $key The PEM, or its base64-encoded form.
	 *
	 * @return string The PEM.
	 */
	private function pemOf(string $key): string {
		if (str_contains($key, '-----BEGIN') === false) {
			return (string)base64_decode($key, true);
		}

		return $key;
	}//end pemOf()

	/**
	 * Base64url without padding, as a JWK carries its numbers.
	 *
	 * @param string $raw The raw bytes.
	 *
	 * @return string The encoded value.
	 */
	private function base64Url(string $raw): string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}//end base64Url()
}//end class
