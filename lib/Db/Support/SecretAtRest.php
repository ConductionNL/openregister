<?php

/**
 * Secrets stored encrypted with Nextcloud's ICrypto.
 *
 * A sealed value carries a prefix, so a stored value that was written before
 * encryption existed is told apart from one that was not, and sealing twice
 * is a no-op. A value that cannot be opened stays sealed: whoever uses it then
 * holds ciphertext, which signs and matches nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Support;

use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seals and opens stored secrets.
 *
 * @spec openspec/specs/auth-system/spec.md
 */
final class SecretAtRest {
	/**
	 * The prefix of a sealed value.
	 *
	 * @var string
	 */
	public const PREFIX = 'orenc:v1:';

	/**
	 * The value a webhook secret reads as when it cannot be opened.
	 *
	 * Sealed by its prefix, so a delivery that meets it is refused instead of sent unsigned.
	 *
	 * @var string
	 */
	public const UNREADABLE = self::PREFIX . 'unreadable';

	/**
	 * The authorization configuration keys of a consumer whose values are secret.
	 *
	 * `publicKey` holds the shared secret for an HS* algorithm; for RS* it is a
	 * public key, and sealing it costs nothing.
	 *
	 * @var array<int, string>
	 */
	public const CONSUMER_SECRET_KEYS = ['apiKey', 'publicKey', 'secret', 'client_secret', 'password', 'private_key', 'token'];

	/**
	 * Constructor.
	 *
	 * @param ICrypto              $crypto Nextcloud's crypto service.
	 * @param LoggerInterface|null $logger Told when a stored secret cannot be opened.
	 */
	public function __construct(
		private readonly ICrypto $crypto,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Whether a stored value is sealed.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public static function isSealed(mixed $value): bool {
		return is_string($value) === true && str_starts_with($value, self::PREFIX) === true;
	}//end isSealed()

	/**
	 * The value sealed; null, empty and already sealed values come back as they are.
	 *
	 * @param string|null $value The plain value.
	 *
	 * @return string|null The sealed value.
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function seal(?string $value): ?string {
		if ($value === null || $value === '' || self::isSealed(value: $value) === true) {
			return $value;
		}

		return self::PREFIX . $this->crypto->encrypt($value);
	}//end seal()

	/**
	 * The value opened; a plain value comes back as it is.
	 *
	 * A sealed value that cannot be opened reads as null (logged), never as its
	 * ciphertext: the ciphertext is visible to whoever may read the stored
	 * configuration, so it must not work as the secret.
	 *
	 * @param string|null $value The stored value.
	 *
	 * @return string|null The plain value, or null when it cannot be opened.
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function open(?string $value): ?string {
		if (self::isSealed(value: $value) === false) {
			return $value;
		}

		try {
			return $this->crypto->decrypt(substr((string)$value, strlen(self::PREFIX)));
		} catch (Throwable $e) {
			$this->logger?->error(
				message: '[SecretAtRest] A stored secret cannot be decrypted; it is treated as absent',
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => get_class($e)]
			);
			return null;
		}
	}//end open()

	/**
	 * A consumer's authorization configuration with its secret values sealed.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function sealConfiguration(array $configuration): array {
		foreach (self::CONSUMER_SECRET_KEYS as $key) {
			if (is_string($configuration[$key] ?? null) === true) {
				$configuration[$key] = $this->seal(value: $configuration[$key]);
			}
		}

		return $configuration;
	}//end sealConfiguration()

	/**
	 * A consumer's authorization configuration with its secret values opened.
	 *
	 * @param array<string, mixed> $configuration The stored configuration.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function openConfiguration(array $configuration): array {
		foreach (self::CONSUMER_SECRET_KEYS as $key) {
			if (is_string($configuration[$key] ?? null) === true) {
				$configuration[$key] = $this->open(value: $configuration[$key]);
			}
		}

		return $configuration;
	}//end openConfiguration()
}//end class
