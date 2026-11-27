<?php

/**
 * OutsideCustody — a credential whose secret lives in an outside vault (credential-outside-vault-reference D-1, D-2).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\WriteCause;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Validates and reads a credential with `custody: "outside"`.
 *
 * The broker calls {@see secretFor()} only AFTER its owner, membership and
 * allowed-app guards admitted the caller (D-4), so a caller the guards refuse
 * never makes the broker contact a vault. The vault connection a reference
 * names must be an `outside-vault` credential of the same organisation (or,
 * for a personal credential, of the same owner); that is checked on every
 * read, because `vaultRef` is a property the owner can edit. The secret read
 * is returned to the broker and nowhere else: it is not stored, logged or
 * written back. A failure sets the credential's `lastError` to a fixed
 * sentence and raises CredentialUpstreamException naming the connection.
 * A successful read stamps the credential's `lastReadAt`, at most once a
 * minute, so the credential page can say when the vault was last read
 * without a write on every brokered call.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The object store's value types, the reader and the two exceptions are the whole path.
 * @SuppressWarnings(PHPMD.StaticAccess) VaultReference::fromArray() is a pure constructor; WriteCause::asLookup() marks a read as such.
 *
 * @spec openspec/changes/credential-outside-vault-reference/specs/credential-broker/spec.md
 */
final class OutsideCustody {

	/**
	 * The `custody` value of a credential whose secret lives in an outside vault.
	 *
	 * @var string
	 */
	public const OUTSIDE = 'outside';

	/**
	 * The catalogue kind of a vault connection credential.
	 *
	 * @var string
	 */
	public const KIND = 'outside-vault';

	/**
	 * The `lastError` a failed read leaves: fixed, so no vault answer reaches the object.
	 *
	 * @var string
	 */
	public const READ_FAILED = 'The outside vault could not give the secret for this credential.';

	/**
	 * Seconds a `lastReadAt` stamp stays fresh: a read within it writes nothing.
	 *
	 * @var int
	 */
	public const READ_STAMP_INTERVAL = 60;

	/**
	 * Constructor.
	 *
	 * @param CredentialSources $sources The credential objects, the custody leaf and the provider catalogue.
	 * @param OutsideVaultReader $reader Reads the referenced value from the vault.
	 * @param LoggerInterface $logger Secret-free diagnostics.
	 * @param ITimeFactory $clock The time a successful read is stamped with.
	 */
	public function __construct(
		private readonly CredentialSources $sources,
		private readonly OutsideVaultReader $reader,
		private readonly LoggerInterface $logger,
		private readonly ITimeFactory $clock,
	) {
	}//end __construct()

	/**
	 * Whether a credential's secret lives in an outside vault.
	 *
	 * @param array<string, mixed> $data The credential's data.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/credential-outside-vault-reference/specs/credential-broker/spec.md#requirement-a-credential-can-reference-a-secret-held-in-an-outside-vault
	 */
	public static function isOutside(array $data): bool {
		return ($data['custody'] ?? null) === self::OUTSIDE;
	}//end isOutside()

	/**
	 * Refuse a mint of an outside credential that carries a secret or no usable reference.
	 *
	 * @param array<string, mixed> $data The credential about to be minted.
	 * @param string|null $secret The secret the mint was given, trimmed.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a secret is given, or the reference or its connection is not usable.
	 *
	 * @spec openspec/changes/credential-outside-vault-reference/specs/credential-broker/spec.md#requirement-a-credential-can-reference-a-secret-held-in-an-outside-vault
	 */
	public function assertMintable(array $data, #[\SensitiveParameter] ?string $secret): void {
		if ($secret !== null && $secret !== '') {
			throw new InvalidArgumentException('A credential held in an outside vault takes no secret');
		}

		$reference = VaultReference::fromArray(vaultRef: (array)($data['vaultRef'] ?? []));
		if ($this->usableConnection(data: $data, reference: $reference) === null) {
			throw new InvalidArgumentException('The vault connection is not an outside-vault credential this credential may use');
		}
	}//end assertMintable()

	/**
	 * The secret an admitted outside credential refers to, read from its vault now.
	 *
	 * @param array<string, mixed> $data The admitted credential's data.
	 * @param string $credentialId The credential uuid.
	 *
	 * @return string The secret.
	 *
	 * @throws CredentialAccessDeniedException When the reference or its connection is not usable.
	 * @throws CredentialUpstreamException When the vault cannot give the secret.
	 *
	 * @spec openspec/changes/credential-outside-vault-reference/specs/credential-broker/spec.md#requirement-a-credential-can-reference-a-secret-held-in-an-outside-vault
	 * @spec openspec/changes/credential-outside-vault-reference/specs/credential-broker/spec.md#requirement-authorization-is-decided-before-an-outside-secret-is-read
	 */
	public function secretFor(array $data, string $credentialId): string {
		try {
			$reference = VaultReference::fromArray(vaultRef: (array)($data['vaultRef'] ?? []));
		} catch (InvalidArgumentException $e) {
			$this->logger->warning('[CredentialBroker] outside credential has no usable vault reference: ' . $credentialId);
			throw new CredentialAccessDeniedException(message: 'Request not permitted');
		}

		$connection = $this->usableConnection(data: $data, reference: $reference);
		if ($connection === null) {
			$this->logger->warning('[CredentialBroker] outside credential names a vault connection it may not use: ' . $credentialId);
			throw new CredentialAccessDeniedException(message: 'Request not permitted');
		}

		try {
			$secret = $this->reader->read(connection: $this->vaultConnection(connection: $connection, reference: $reference), reference: $reference);
		} catch (CredentialUpstreamException $e) {
			$this->recordFailure(credentialId: $credentialId);
			throw new CredentialUpstreamException('The outside vault connection ' . $reference->connection . ' could not give the secret.');
		}

		$this->recordRead(credentialId: $credentialId);
		return $secret;
	}//end secretFor()

	/**
	 * The connection credential a reference names, when this credential may use it.
	 *
	 * It must be stored, of the `outside-vault` kind, and held by the same
	 * organisation as an organisation credential, or the same owner as a
	 * personal one.
	 *
	 * @param array<string, mixed> $data The outside credential's data.
	 * @param VaultReference $reference The reference.
	 *
	 * @return array<string, mixed>|null The connection's data, or null when it may not be used.
	 */
	private function usableConnection(array $data, VaultReference $reference): ?array {
		$connection = $this->storedCredential(credentialId: $reference->connection);
		if ($connection === null) {
			return null;
		}

		$connectionData = $connection->jsonSerialize();
		$provider = $this->sources->catalogue->get((string)($connectionData['provider'] ?? ''));
		if ($provider === null || ($provider['kind'] ?? null) !== self::KIND) {
			return null;
		}

		if (self::scopeOf(data: $data) !== self::scopeOf(data: $connectionData)) {
			return null;
		}

		$holder = 'owner';
		if (self::scopeOf(data: $data) === 'organisation') {
			$holder = 'organisation';
		}

		$expected = trim((string)($data[$holder] ?? ''));
		if ($expected === '' || $expected !== trim((string)($connectionData[$holder] ?? ''))) {
			return null;
		}

		return $connectionData;
	}//end usableConnection()

	/**
	 * How to reach the vault: the connection's address, login and mount, with its login secret from custody.
	 *
	 * @param array<string, mixed> $connection The connection's data.
	 * @param VaultReference $reference The reference, for the connection uuid.
	 *
	 * @return OutsideVaultConnection
	 *
	 * @throws CredentialUpstreamException When the connection has no login secret or is malformed.
	 */
	private function vaultConnection(array $connection, VaultReference $reference): OutsideVaultConnection {
		$secret = $this->sources->credentialStore->get($reference->connection, self::scopeOf(data: $connection));
		if ($secret === null || $secret === '') {
			throw new CredentialUpstreamException('The outside vault connection has no login secret.');
		}

		try {
			return new OutsideVaultConnection(
				baseUrl: (string)($connection['instanceBaseUrl'] ?? ''),
				authMethod: (string)($connection['vaultAuthMethod'] ?? OutsideVaultConnection::AUTH_TOKEN),
				secret: $secret,
				roleId: self::stringOrNull(value: ($connection['vaultRoleId'] ?? null)),
				mount: (string)($connection['vaultMount'] ?? 'secret')
			);
		} catch (InvalidArgumentException $e) {
			throw new CredentialUpstreamException('The outside vault connection is not configured correctly.');
		}
	}//end vaultConnection()

	/**
	 * A stored credential, past RBAC and multitenancy, or null.
	 *
	 * @param string $credentialId The credential uuid.
	 *
	 * @return ObjectEntity|null
	 */
	private function storedCredential(string $credentialId): ?ObjectEntity {
		try {
			return WriteCause::asLookup(fn () => $this->sources->objectService->find(
				id: $credentialId,
				options: new ObjectFindOptions(register: CredentialBrokerService::REGISTER, schema: CredentialBrokerService::SCHEMA),
				_rbac: false,
				_multitenancy: false
			));
		} catch (Throwable $e) {
			return null;
		}
	}//end storedCredential()

	/**
	 * Leave the fixed sentence on the credential's `lastError`; a failed write is logged, not raised.
	 *
	 * @param string $credentialId The credential uuid.
	 *
	 * @return void
	 */
	private function recordFailure(string $credentialId): void {
		try {
			$stored = $this->storedCredential(credentialId: $credentialId);
			if ($stored === null) {
				return;
			}

			$data = $stored->jsonSerialize();
			unset($data['@self']);
			$this->sources->objectService->saveObject(
				object: array_merge($data, ['lastError' => self::READ_FAILED]),
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: CredentialBrokerService::REGISTER, schema: CredentialBrokerService::SCHEMA),
					uuid: $stored->getUuid()
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('[CredentialBroker] could not record the outside vault failure on ' . $credentialId);
		}//end try
	}//end recordFailure()

	/**
	 * Stamp `lastReadAt` after a successful read, unless a stamp of the last minute stands.
	 *
	 * A stale `lastError` this class left is cleared with it, because the vault
	 * has just given the secret. Any other `lastError` (an OAuth refresh
	 * failure, say) is not this class's to clear. A failed write is logged and
	 * never fails the call: the secret was read, and the caller is owed it.
	 *
	 * @param string $credentialId The credential uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/credential-outside-vault-reference/specs/credential-broker/spec.md#requirement-a-credential-can-reference-a-secret-held-in-an-outside-vault
	 */
	private function recordRead(string $credentialId): void {
		try {
			$stored = $this->storedCredential(credentialId: $credentialId);
			if ($stored === null) {
				return;
			}

			$data = $stored->jsonSerialize();
			unset($data['@self']);
			$now = $this->clock->now();
			if ($this->stampIsFresh(stamp: $data['lastReadAt'] ?? null, now: $now) === true
				&& ($data['lastError'] ?? null) !== self::READ_FAILED
			) {
				return;
			}

			$data['lastReadAt'] = $now->format(DATE_ATOM);
			if (($data['lastError'] ?? null) === self::READ_FAILED) {
				unset($data['lastError']);
			}

			$this->sources->objectService->saveObject(
				object: $data,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: CredentialBrokerService::REGISTER, schema: CredentialBrokerService::SCHEMA),
					uuid: $stored->getUuid()
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('[CredentialBroker] could not record the outside vault read on ' . $credentialId);
		}//end try
	}//end recordRead()

	/**
	 * Whether a stored `lastReadAt` is younger than {@see READ_STAMP_INTERVAL}.
	 *
	 * An absent, unparsable or future stamp is not fresh, so it is rewritten.
	 *
	 * @param mixed $stamp The stored stamp.
	 * @param \DateTimeImmutable $now The time of this read.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/credential-outside-vault-reference/specs/credential-broker/spec.md#requirement-a-credential-can-reference-a-secret-held-in-an-outside-vault
	 */
	private function stampIsFresh(mixed $stamp, \DateTimeImmutable $now): bool {
		if (is_string($stamp) === false || trim($stamp) === '') {
			return false;
		}

		try {
			$age = $now->getTimestamp() - (new \DateTimeImmutable($stamp))->getTimestamp();
		} catch (Throwable $e) {
			return false;
		}

		return $age >= 0 && $age < self::READ_STAMP_INTERVAL;
	}//end stampIsFresh()

	/**
	 * A credential's scope: organisation, or personal for anything else.
	 *
	 * @param array<string, mixed> $data The credential's data.
	 *
	 * @return string
	 */
	private static function scopeOf(array $data): string {
		if (($data['scope'] ?? null) === 'organisation') {
			return 'organisation';
		}

		return 'personal';
	}//end scopeOf()

	/**
	 * A trimmed non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function stringOrNull(mixed $value): ?string {
		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end stringOrNull()
}//end class
