<?php

/**
 * The key of the audit hash chain, and the row the keyed chain starts at.
 *
 * The chain was sha256(previousHash . canonicalJson), which anyone with write
 * access to the table can recompute after editing a row. From the keyed-from
 * row on, each link is an HMAC under a key that lives outside the table: a
 * tenant key from TenantKeyService, stored encrypted with Nextcloud's ICrypto
 * and generated on first use. Rows before that point keep their unkeyed links,
 * so verification still checks them and reports how many there were.
 *
 * The keyed-from row id is stored in app config together with an HMAC of it
 * under the same key, so moving the boundary to make keyed rows verify as
 * unkeyed is detected rather than trusted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\IAppConfig;

/**
 * Keys the audit hash chain with a tenant key.
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */
class AuditChainKey {
	/**
	 * The tenant id the chain key is held under in TenantKeyService.
	 *
	 * @var string
	 */
	public const TENANT = 'openregister-audit-chain';

	/**
	 * App config key holding `<row id>:<hmac of the row id>`.
	 *
	 * @var string
	 */
	public const KEYED_FROM_KEY = 'audit_chain_keyed_from';

	/**
	 * The key material, read once per instance.
	 *
	 * @var string|null
	 */
	private ?string $key = null;

	/**
	 * The parsed boundary, read once per instance.
	 *
	 * @var array{from: int|null, intact: bool}|null
	 */
	private ?array $boundary = null;

	/**
	 * Constructor.
	 *
	 * @param TenantKeyService $keys      Holds the chain key, encrypted with ICrypto.
	 * @param IAppConfig       $appConfig Holds the keyed-from row.
	 */
	public function __construct(
		private readonly TenantKeyService $keys,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The HMAC-SHA256 of the data under the chain key.
	 *
	 * @param string $data The data.
	 *
	 * @return string The hex digest.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function hmac(string $data): string {
		return hash_hmac('sha256', $data, $this->key());
	}//end hmac()

	/**
	 * The first row id that is chained with the key, or null when the chain is not keyed yet.
	 *
	 * A boundary whose HMAC does not match still answers its row id, so rows
	 * from there on are never sealed or checked unkeyed; boundaryIntact() says
	 * it was altered.
	 *
	 * @return int|null The row id.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function keyedFrom(): ?int {
		return $this->boundary()['from'];
	}//end keyedFrom()

	/**
	 * Whether the stored boundary carries a valid HMAC (true when none is stored).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function boundaryIntact(): bool {
		return $this->boundary()['intact'];
	}//end boundaryIntact()

	/**
	 * Whether a row is chained with the key.
	 *
	 * @param int|null $id The row id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function isKeyed(?int $id): bool {
		$from = $this->keyedFrom();

		return $from !== null && $id !== null && $id >= $from;
	}//end isKeyed()

	/**
	 * Start the keyed chain at a row id, generating the key when there is none yet.
	 *
	 * @param int $id The first row id to chain with the key.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function startAt(int $id): void {
		$this->appConfig->setValueString('openregister', self::KEYED_FROM_KEY, $id . ':' . $this->boundaryMac(id: $id));
		$this->boundary = ['from' => $id, 'intact' => true];
	}//end startAt()

	/**
	 * The stored boundary, parsed and checked.
	 *
	 * @return array{from: int|null, intact: bool}
	 */
	private function boundary(): array {
		if ($this->boundary !== null) {
			return $this->boundary;
		}

		$stored = $this->appConfig->getValueString('openregister', self::KEYED_FROM_KEY, '');
		if ($stored === '') {
			$this->boundary = ['from' => null, 'intact' => true];
			return $this->boundary;
		}

		$parts = explode(':', $stored, 2);
		$from  = 1;
		if (ctype_digit($parts[0]) === true) {
			$from = max(1, (int)$parts[0]);
		}

		$intact = (count($parts) === 2 && hash_equals($this->boundaryMac(id: $from), $parts[1]) === true && (string)$from === $parts[0]);

		$this->boundary = ['from' => $from, 'intact' => $intact];
		return $this->boundary;
	}//end boundary()

	/**
	 * The HMAC binding a boundary row id to the key.
	 *
	 * @param int $id The row id.
	 *
	 * @return string The hex digest.
	 */
	private function boundaryMac(int $id): string {
		return $this->hmac(data: 'openregister-audit-chain-keyed-from:' . $id);
	}//end boundaryMac()

	/**
	 * The chain key, read from TenantKeyService (which generates it on first use).
	 *
	 * @return string The key material.
	 */
	private function key(): string {
		if ($this->key === null) {
			$this->key = $this->keys->getCurrentTenantKey(tenantId: self::TENANT);
		}

		return $this->key;
	}//end key()
}//end class
