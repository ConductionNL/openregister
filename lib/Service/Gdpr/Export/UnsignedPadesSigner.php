<?php

/**
 * OpenRegister Gdpr UnsignedPadesSigner
 *
 * Default {@see PadesSigner} binding. Returns the bundle bytes with a SHA-256
 * content hash attached and a clearly-marked `signed:false` /
 * `pending PAdES-LTV library` state. It deliberately does NOT implement a
 * PAdES-LTV signature: the real signer (a vendored PAdES-LTV library) drops in
 * later behind the {@see PadesSigner} interface without any change to the
 * export-bundle service.
 *
 * The SHA-256 hash is a genuine integrity guarantee carried on every bundle;
 * altering the bytes changes the hash. It is NOT a substitute for the signature
 * — the `signed:false` marker makes the unsigned state unmistakable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Export
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Export;

/**
 * SHA-256-only stub signer — carries the hash, does not sign.
 */
final class UnsignedPadesSigner implements PadesSigner {

	/**
	 * Signature-state marker for the unsigned stub.
	 *
	 * @var string
	 */
	public const STATE_PENDING_LIBRARY = 'pending PAdES-LTV library';

	/**
	 * Attach a SHA-256 content hash to the bytes and mark them unsigned.
	 *
	 * @param string $bytes The rendered bundle bytes.
	 *
	 * @return SignedBundle Bytes + `sha256:` hash + `signed:false` pending-library state.
	 *
	 * @spec openspec/changes/dsar-case-engine/specs/dsar-export-bundle/spec.md
	 */
	public function sign(string $bytes): SignedBundle {
		return new SignedBundle(
			bytes: $bytes,
			contentHash: 'sha256:' . hash(algo: 'sha256', data: $bytes),
			signed: false,
			signatureState: self::STATE_PENDING_LIBRARY
		);
	}//end sign()
}//end class
