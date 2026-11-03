<?php

/**
 * Signs and verifies federated configuration bundles (provenance + tamper-evidence).
 *
 * A shared bundle travels between instances over GitHub; nothing about GitHub
 * proves who produced it or that it arrived unaltered. This signer gives both:
 * on publish it attaches an Ed25519 signature over the canonical bundle keyed by
 * the publishing instance's own key, and on install the receiver re-derives the
 * canonical form and checks the signature. A trusted-keys allowlist (empty =
 * "not yet enforced", the same idiom as the source allowlist) lets an org pin
 * which publishers it will install from.
 *
 * The signature covers a canonical JSON encoding (keys recursively sorted) of the
 * bundle with its own `provenance` block removed, so signing is deterministic and
 * order-independent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

use OCP\IAppConfig;
use Throwable;

/**
 * Ed25519 signing and verification for shareable bundles.
 */
class BundleSigner {

	/**
	 * The app its config lives under.
	 */
	private const APP_ID = 'openregister';

	/**
	 * App-config key holding this instance's base64 Ed25519 secret key.
	 */
	private const SECRET_KEY = 'federated_config_signing_secret';

	/**
	 * App-config key holding the comma-separated base64 public keys this org
	 * trusts. Empty means "signatures not yet enforced".
	 */
	private const TRUSTED_KEYS = 'federated_config_trusted_keys';

	/**
	 * The signature algorithm label carried in provenance.
	 */
	private const ALG = 'ed25519';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Stores the signing key and the trusted keys.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * Attach a signature to a bundle.
	 *
	 * @param array $bundle The bundle to sign.
	 *
	 * @return array The bundle with a `provenance` block.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function sign(array $bundle): array {
		unset($bundle['provenance']);

		$secret = $this->secretKey();
		$public = sodium_crypto_sign_publickey_from_secretkey($secret);
		$signature = sodium_crypto_sign_detached($this->canonical(bundle: $bundle), $secret);

		$bundle['provenance'] = [
			'alg' => self::ALG,
			'publicKey' => base64_encode($public),
			'signature' => base64_encode($signature),
		];

		return $bundle;
	}//end sign()

	/**
	 * Verify a bundle's signature.
	 *
	 * @param array $bundle The bundle to check.
	 *
	 * @return array{signed: bool, valid: bool, trusted: bool, publicKey: ?string}
	 *                                                                             `signed` — carried a provenance block; `valid` — signature checks
	 *                                                                             out; `trusted` — the key is on the allowlist (or the allowlist is
	 *                                                                             not enforced).
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function verify(array $bundle): array {
		$provenance = ($bundle['provenance'] ?? null);
		if (is_array($provenance) === false) {
			// Unsigned: "trusted" only when the org has not opted into enforcement.
			return ['signed' => false, 'valid' => false, 'trusted' => ($this->enforced() === false), 'publicKey' => null];
		}

		unset($bundle['provenance']);

		$publicKeyB64 = (string)($provenance['publicKey'] ?? '');
		$signature = base64_decode((string)($provenance['signature'] ?? ''), true);
		$publicKey = base64_decode($publicKeyB64, true);

		$valid = false;
		if ($signature !== false && $publicKey !== false) {
			try {
				$valid = sodium_crypto_sign_verify_detached($signature, $this->canonical(bundle: $bundle), $publicKey);
			} catch (Throwable $e) {
				$valid = false;
			}
		}

		return [
			'signed' => true,
			'valid' => $valid,
			'trusted' => $this->isKeyTrusted(publicKeyB64: $publicKeyB64),
			'publicKey' => $publicKeyB64,
		];

	}//end verify()

	/**
	 * This instance's public key, base64, so an operator can share it for others
	 * to trust.
	 *
	 * @return string The base64 public key.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function publicKey(): string {
		return base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->secretKey()));
	}//end publicKey()

	/**
	 * Whether signature enforcement is on (a non-empty trusted-keys allowlist).
	 *
	 * @return boolean Whether unsigned/untrusted bundles must be refused.
	 */
	public function enforced(): bool {
		return trim($this->appConfig->getValueString(self::APP_ID, self::TRUSTED_KEYS, '')) !== '';
	}//end enforced()

	/**
	 * Whether a public key is trusted. An empty allowlist trusts everything (not
	 * yet enforced); otherwise the key must appear on it.
	 *
	 * @param string $publicKeyB64 The base64 public key.
	 *
	 * @return boolean Whether it is trusted.
	 */
	private function isKeyTrusted(string $publicKeyB64): bool {
		$raw = trim($this->appConfig->getValueString(self::APP_ID, self::TRUSTED_KEYS, ''));
		if ($raw === '') {
			return true;
		}

		$trusted = array_filter(array_map('trim', explode(',', $raw)));
		return in_array($publicKeyB64, $trusted, true);
	}//end isKeyTrusted()

	/**
	 * The instance's Ed25519 secret key, generating and persisting one on first use.
	 *
	 * @return string The raw secret key bytes.
	 */
	private function secretKey(): string {
		$stored = $this->appConfig->getValueString(self::APP_ID, self::SECRET_KEY, '');
		if ($stored !== '') {
			$decoded = base64_decode($stored, true);
			if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
				return $decoded;
			}
		}

		// First publish on this instance: mint and persist a keypair. The secret
		// never leaves app config; only the derived public key is ever shared.
		$keypair = sodium_crypto_sign_keypair();
		$secret = sodium_crypto_sign_secretkey($keypair);
		$this->appConfig->setValueString(self::APP_ID, self::SECRET_KEY, base64_encode($secret), sensitive: true);

		return $secret;
	}//end secretKey()

	/**
	 * Canonical, order-independent JSON encoding of a bundle for signing.
	 *
	 * @param array $bundle The bundle (without its provenance block).
	 *
	 * @return string The canonical bytes.
	 */
	private function canonical(array $bundle): string {
		$this->ksortRecursive(value: $bundle);
		return (string)json_encode($bundle, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}//end canonical()

	/**
	 * Recursively sort array keys so encoding is deterministic.
	 *
	 * @param mixed $value The value to sort in place.
	 *
	 * @return void
	 */
	private function ksortRecursive(mixed &$value): void {
		if (is_array($value) === false) {
			return;
		}

		// Only associative arrays need key-sorting; list order is significant.
		foreach ($value as &$child) {
			$this->ksortRecursive(value: $child);
		}

		unset($child);

		if (array_is_list($value) === false) {
			ksort($value);
		}

	}//end ksortRecursive()
}//end class
