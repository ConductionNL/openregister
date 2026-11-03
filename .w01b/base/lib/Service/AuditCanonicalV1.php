<?php

/**
 * The audit chain's v1 canonical form, frozen.
 *
 * ⚠️ THIS FILE IS FROZEN. Nothing may be added to {@see FIELDS}, ever. It is not
 * a description of the AuditTrail entity — it is a description of the entity AS
 * IT WAS when the rows sealed under `openregister-genesis-v1` were written.
 * Updating it to match a newer entity would silently destroy the only means of
 * checking those rows.
 *
 * WHY IT EXISTS. Adding the flow-attribution fields to the canonical JSON
 * changed the chain's identity, which ADR-003 Rule 4 defines as a migration
 * event: verify, then re-seal under a new seed. The verify half is the part
 * that is easy to get wrong and worthless when wrong.
 *
 * If the pre-migration verification canonicalises with the CURRENT code, it
 * includes the new keys, so every row sealed under v1 mismatches — whether that
 * row was tampered with or was perfectly intact. The verdict comes back
 * "broken" either way, the re-chain overwrites the hashes either way, and the
 * difference between a healthy chain and a compromised one is destroyed with no
 * record that it ever existed. The check would run, produce output, and mean
 * nothing.
 *
 * So the check reads the old form from here. An intact v1 chain verifies as
 * intact; a tampered one names the row it broke at; and that verdict is
 * recorded before anything is rewritten.
 *
 * The field list is an ALLOWLIST rather than a denylist of the new keys. A
 * denylist would have to be updated every time a field is added to the entity —
 * which is to say it would be wrong the first time somebody forgot, and wrong
 * silently.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\AuditTrail;

/**
 * Canonicalises an audit row the way the v1 chain did.
 *
 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
 */
final class AuditCanonicalV1 {
	/**
	 * The genesis seed the v1 chain was built from.
	 *
	 * @var string
	 */
	public const GENESIS_SEED = 'openregister-genesis-v1';

	/**
	 * Every key the v1 canonical JSON contained, `hash` and `previousHash`
	 * excluded as they always were.
	 *
	 * 🔴 FROZEN. Adding an entry here changes what a v1 row is claimed to have
	 * been sealed over, which makes intact rows read as tampered.
	 *
	 * @var string[]
	 */
	private const FIELDS = [
		'id',
		'uuid',
		'schema',
		'register',
		'object',
		'objectUuid',
		'registerUuid',
		'schemaUuid',
		'action',
		'changed',
		'user',
		'userName',
		'session',
		'request',
		'ipAddress',
		'version',
		'created',
		'organisationId',
		'organisationIdType',
		'processingActivityId',
		'processingActivityUrl',
		'processingId',
		'confidentiality',
		'retentionPeriod',
		'size',
		'expires',
		'toolId',
		'paramsDigest',
		'resultSummary',
	];

	/**
	 * The v1 genesis hash.
	 *
	 * @return string SHA-256 of the v1 seed.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
	 */
	public static function genesisHash(): string {
		return hash('sha256', self::GENESIS_SEED);
	}//end genesisHash()

	/**
	 * The canonical JSON a v1 row was sealed over.
	 *
	 * Same rules the v1 canonicaliser used: the allowlisted fields only, sorted
	 * keys, compact form.
	 *
	 * @param AuditTrail $entry The row to canonicalise.
	 *
	 * @return string The v1 canonical JSON.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
	 */
	public static function canonicalJson(AuditTrail $entry): string {
		$serialized = $entry->jsonSerialize();

		$data = [];
		foreach (self::FIELDS as $field) {
			// Present-but-null and absent are different canonical forms, and
			// every v1 field was always PRESENT — jsonSerialize() emitted the
			// whole array unconditionally. So a missing key is filled with null
			// rather than skipped.
			$data[$field] = ($serialized[$field] ?? null);
		}

		ksort($data);

		return json_encode($data, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}//end canonicalJson()

	/**
	 * The hash a v1 row should carry, given its predecessor.
	 *
	 * @param AuditTrail $entry        The row.
	 * @param string     $previousHash The hash of the row before it.
	 *
	 * @return string The expected v1 hash.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
	 */
	public static function computeHash(AuditTrail $entry, string $previousHash): string {
		return hash('sha256', ($previousHash . self::canonicalJson(entry: $entry)));
	}//end computeHash()
}//end class
