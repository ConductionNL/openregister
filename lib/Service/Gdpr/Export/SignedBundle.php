<?php

/**
 * OpenRegister Gdpr SignedBundle
 *
 * Immutable value object a {@see PadesSigner} returns: the (PDF) bundle bytes,
 * the SHA-256 content hash of those bytes, and the signature state. When the
 * signer is the {@see UnsignedPadesSigner} default, `signed` is false and
 * `signatureState` is a clearly-marked "pending PAdES-LTV library" marker so no
 * caller can mistake an unsigned bundle for a signed one.
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
 * Result of signing (or stub-signing) an export bundle.
 */
final class SignedBundle {
	/**
	 * Constructor.
	 *
	 * @param string $bytes The bundle bytes (the PDF disclosure document).
	 * @param string $contentHash SHA-256 content hash of the bytes (`sha256:...`).
	 * @param bool $signed Whether a real PAdES-LTV signature is attached.
	 * @param string $signatureState Human/machine-readable signature state marker.
	 * @param string $mimeType MIME type of the bytes.
	 *
	 * @spec openspec/changes/dsar-case-engine/specs/dsar-export-bundle/spec.md
	 */
	public function __construct(
		private readonly string $bytes,
		private readonly string $contentHash,
		private readonly bool $signed,
		private readonly string $signatureState,
		private readonly string $mimeType = 'application/pdf',
	) {
	}//end __construct()

	/**
	 * The bundle bytes.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/dsar-case-engine/specs/dsar-export-bundle/spec.md
	 */
	public function getBytes(): string {
		return $this->bytes;
	}//end getBytes()

	/**
	 * The SHA-256 content hash of the bytes.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/dsar-case-engine/specs/dsar-export-bundle/spec.md
	 */
	public function getContentHash(): string {
		return $this->contentHash;
	}//end getContentHash()

	/**
	 * Whether a real PAdES-LTV signature is attached.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/dsar-case-engine/specs/dsar-export-bundle/spec.md
	 */
	public function isSigned(): bool {
		return $this->signed;
	}//end isSigned()

	/**
	 * The signature-state marker.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/dsar-case-engine/specs/dsar-export-bundle/spec.md
	 */
	public function getSignatureState(): string {
		return $this->signatureState;
	}//end getSignatureState()

	/**
	 * The MIME type of the bytes.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/dsar-case-engine/specs/dsar-export-bundle/spec.md
	 */
	public function getMimeType(): string {
		return $this->mimeType;
	}//end getMimeType()
}//end class
