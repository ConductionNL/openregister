<?php

/**
 * Doriath contract stubs + test fixtures for PHPUnit in the bare php:8.3-cli CI
 * environment (credential-doriath-leaf).
 *
 * PART 1 — class_exists-GUARDED stubs of the `OCA\Doriath\*` seam classes the
 * credential-broker Doriath custody leaf consumes. The Doriath app is NOT a
 * composer dependency of OpenRegister (the leaf resolves it lazily via
 * `class_exists` + `OCP\Server::get`, design D-A), so these classes are absent
 * in the bare-composer CI install. The stub signatures are the VERIFIED
 * contract (doriath `origin/development` + the `application-secret-delete`
 * change spec for the application-scoped seam methods):
 *
 *   - EncryptService::rsaEncrypt(string $plaintext, string $publicKeyPem): string
 *   - DecryptService::rsaDecrypt(string $ciphertext, string $privateKeyPem): string
 *   - ApplicationService::register(string $name, ?string $description, string $type,
 *         ?string $csr, ?string $userId, bool $isAdmin): Application
 *   - ApplicationService::get(string $applicationId, string $userId, bool $isAdmin): Application
 *   - SecretService::createByApplication(array $data, string $applicationId): Secret
 *   - SecretService::updateByApplication(string $id, array $data, string $applicationId): Secret
 *   - SecretService::deleteByApplication(string $secretId, string $applicationId): void      [seam]
 *     ($secretId is the Doriath ROW id — the seam loads via SecretMapper::findById —
 *     idempotent silent no-op on missing/cross-vault ids)
 *   - SecretService::getByNameForApplication(string $name, string $applicationId): ?Secret   [seam]
 *     (single own-vault match → entity with ciphertext intact in getKey() + one
 *     APPLICATION_SECRET_RETRIEVED audit; zero matches / cross-vault / AMBIGUOUS (>1)
 *     → null — ambiguity logs a warning Doriath-side, never returned to the caller)
 *
 * When the real Doriath app is autoloadable (e.g. inside a bootstrapped
 * Nextcloud container) the guards skip every declaration, so the real classes
 * always win — tests therefore MUST NOT depend on `OCA\Doriath` FQCNs and use
 * the Part-2 fixtures through the production classes' protected test seams.
 *
 * PART 2 — always-declared, environment-independent fixtures under
 * `OCA\OpenRegister\Tests\Fixtures\Doriath`: recording fakes with the exact
 * seam signatures (plus reversible fake "crypto") that unit tests inject via
 * the protected `resolveDoriathService()` / `doriathServiceClasses()` /
 * `secretServiceClass()` seams. Secrets in fixtures are placeholders only.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// PART 1 — guarded OCA\Doriath contract stubs (bare-CI only; real app wins).
// ---------------------------------------------------------------------------

namespace OCA\Doriath\Db {
	if (class_exists('OCA\Doriath\Db\Secret') === false) {
		/**
		 * Minimal Doriath Secret entity stub (id + name + key ciphertext).
		 */
		class Secret {
			public function __construct(
				private string $id = '',
				private string $name = '',
				private string $key = '',
			) {
			}

			public function getId(): string {
				return $this->id;
			}

			public function getName(): string {
				return $this->name;
			}

			public function getKey(): string {
				return $this->key;
			}

			public function setKey(string $key): void {
				$this->key = $key;
			}
		}
	}//end if

	if (class_exists('OCA\Doriath\Db\Application') === false) {
		/**
		 * Minimal Doriath Application entity stub (Doriath-assigned UUID).
		 */
		class Application {
			public function __construct(
				private string $id = '',
			) {
			}

			public function getId(): string {
				return $this->id;
			}
		}
	}//end if
}//end namespace

namespace OCA\Doriath\Service {

	use OCA\Doriath\Db\Application;
	use OCA\Doriath\Db\Secret;

	if (class_exists('OCA\Doriath\Service\EncryptService') === false) {
		/**
		 * Stateless encrypt stub — scheme `rsa-oaep-sha256-chunked-v1` contract.
		 */
		class EncryptService {
			public function rsaEncrypt(string $plaintext, string $publicKeyPem): string {
				return 'stub-cipher:' . base64_encode($plaintext);
			}
		}
	}//end if

	if (class_exists('OCA\Doriath\Service\DecryptService') === false) {
		/**
		 * Stateless decrypt stub — inverse of the encrypt stub.
		 */
		class DecryptService {
			public function rsaDecrypt(string $ciphertext, string $privateKeyPem): string {
				return base64_decode(substr($ciphertext, strlen('stub-cipher:')), true) ?: '';
			}
		}
	}//end if

	if (class_exists('OCA\Doriath\Service\ApplicationService') === false) {
		/**
		 * Application registration stub (admin registration auto-approves).
		 */
		class ApplicationService {
			public function register(
				string $name,
				?string $description,
				string $type,
				?string $csr,
				?string $userId,
				bool $isAdmin,
			): Application {
				return new Application('00000000-0000-0000-0000-000000000000');
			}

			public function get(string $applicationId, string $userId, bool $isAdmin): Application {
				return new Application($applicationId);
			}
		}
	}//end if

	if (class_exists('OCA\Doriath\Service\SecretService') === false) {
		/**
		 * Secret service stub incl. the application-scoped seam methods that
		 * land via Doriath's `application-secret-delete` change.
		 */
		class SecretService {
			public function createByApplication(array $data, string $applicationId): Secret {
				return new Secret('stub', (string)($data['name'] ?? ''), (string)($data['key'] ?? ''));
			}

			public function updateByApplication(string $id, array $data, string $applicationId): Secret {
				return new Secret($id, (string)($data['name'] ?? ''), (string)($data['key'] ?? ''));
			}

			public function deleteByApplication(string $secretId, string $applicationId): void {
			}

			public function getByNameForApplication(string $name, string $applicationId): ?Secret {
				return null;
			}
		}
	}//end if
}//end namespace

// ---------------------------------------------------------------------------
// PART 2 — environment-independent test fixtures (always declared).
// ---------------------------------------------------------------------------

namespace OCA\OpenRegister\Tests\Fixtures\Doriath {

	/**
	 * Minimal secret row fixture (mirrors the Doriath Secret entity surface used by OR).
	 */
	class FakeSecretRow {
		public function __construct(
			public string $id,
			public string $name,
			public string $key,
		) {
		}

		public function getId(): string {
			return $this->id;
		}

		public function getKey(): string {
			return $this->key;
		}
	}

	/**
	 * Recording secret-service fixture WITH the application-scoped seam methods.
	 */
	class FakeSecretService {
		/** @var array<int, FakeSecretRow> */
		public array $rows = [];

		/** @var array<int, array<string, mixed>> */
		public array $createCalls = [];

		/** @var array<int, array<string, mixed>> */
		public array $updateCalls = [];

		/** @var array<int, array<string, string>> */
		public array $deleteCalls = [];

		public int $lookupCalls = 0;

		public function createByApplication(array $data, string $applicationId): FakeSecretRow {
			$this->createCalls[] = ['data' => $data, 'applicationId' => $applicationId];
			$row = new FakeSecretRow('row-' . count($this->rows), (string)($data['name'] ?? ''), (string)($data['key'] ?? ''));
			$this->rows[] = $row;
			return $row;
		}

		public function updateByApplication(string $id, array $data, string $applicationId): FakeSecretRow {
			$this->updateCalls[] = ['id' => $id, 'data' => $data, 'applicationId' => $applicationId];
			foreach ($this->rows as $row) {
				if ($row->id === $id) {
					$row->key = (string)($data['key'] ?? $row->key);
					return $row;
				}
			}

			return new FakeSecretRow($id, '', (string)($data['key'] ?? ''));
		}

		public function deleteByApplication(string $secretId, string $applicationId): void {
			$this->deleteCalls[] = ['secretId' => $secretId, 'applicationId' => $applicationId];
			$this->rows = array_values(
				array_filter($this->rows, static fn (FakeSecretRow $row): bool => $row->id !== $secretId)
			);
		}

		/**
		 * Mirrors the real Doriath semantics: single own-vault match → entity;
		 * zero matches OR ambiguity (>1 — Doriath logs and never guesses) → null.
		 */
		public function getByNameForApplication(string $name, string $applicationId): ?FakeSecretRow {
			$this->lookupCalls++;
			$matches = array_values(
				array_filter($this->rows, static fn (FakeSecretRow $row): bool => $row->name === $name)
			);

			if (count($matches) !== 1) {
				return null;
			}

			return $matches[0];
		}
	}

	/**
	 * Secret-service fixture WITHOUT the seam methods (pre-`application-secret-delete` Doriath).
	 */
	class FakeLegacySecretService {
		public function createByApplication(array $data, string $applicationId): FakeSecretRow {
			return new FakeSecretRow('legacy', (string)($data['name'] ?? ''), (string)($data['key'] ?? ''));
		}

		public function updateByApplication(string $id, array $data, string $applicationId): FakeSecretRow {
			return new FakeSecretRow($id, '', (string)($data['key'] ?? ''));
		}
	}

	/**
	 * Reversible fake encrypt service (base64 marker — NOT real crypto, tests only).
	 */
	class FakeEncryptService {
		/** @var array<int, string> */
		public array $publicKeysSeen = [];

		public function rsaEncrypt(string $plaintext, string $publicKeyPem): string {
			$this->publicKeysSeen[] = $publicKeyPem;
			return 'fake-cipher:' . base64_encode($plaintext);
		}
	}

	/**
	 * Inverse of {@see FakeEncryptService}; records the private keys it was handed.
	 */
	class FakeDecryptService {
		/** @var array<int, string> */
		public array $privateKeysSeen = [];

		public function rsaDecrypt(string $ciphertext, string $privateKeyPem): string {
			$this->privateKeysSeen[] = $privateKeyPem;
			if (str_starts_with($ciphertext, 'fake-cipher:') === false) {
				throw new \RuntimeException('Fixture cannot decrypt foreign ciphertext');
			}

			return (string)base64_decode(substr($ciphertext, strlen('fake-cipher:')), true);
		}
	}

	/**
	 * Doriath application row fixture (Doriath assigns the UUID).
	 */
	class FakeApplicationRow {
		public function __construct(
			public string $id,
		) {
		}

		public function getId(): string {
			return $this->id;
		}
	}

	/**
	 * Recording application-service fixture (register + live-row probe).
	 */
	class FakeApplicationService {
		/** @var array<int, array<int, mixed>> */
		public array $registerCalls = [];

		/** @var array<int, string> */
		public array $liveApplicationIds = [];

		public string $assignedId = 'a1b2c3d4-0000-0000-0000-000000000000';

		public function register(
			string $name,
			?string $description,
			string $type,
			?string $csr,
			?string $userId,
			bool $isAdmin,
		): FakeApplicationRow {
			$this->registerCalls[] = [$name, $description, $type, $csr, $userId, $isAdmin];
			return new FakeApplicationRow($this->assignedId);
		}

		public function get(string $applicationId, string $userId, bool $isAdmin): FakeApplicationRow {
			if (in_array($applicationId, $this->liveApplicationIds, true) === false) {
				throw new \InvalidArgumentException('Application not found');
			}

			return new FakeApplicationRow($applicationId);
		}
	}
}//end namespace
