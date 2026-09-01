<?php

/**
 * OpenRegister Gdpr PadesSigner
 *
 * Swappable signing seam for the export-bundle service. The bundle service
 * depends on this interface (constructor-injected), so the eventual PAdES-LTV
 * implementation drops in behind it as a single dependency without touching the
 * assembly/token/dossier logic (ADR-011 isolation of the new dependency).
 *
 * The default binding is {@see UnsignedPadesSigner}, which attaches the SHA-256
 * content hash and a clearly-marked unsigned state — it does NOT implement
 * PAdES. Swap the binding for the real PAdES-LTV signer once the library is
 * chosen.
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
 * Signs (or stub-signs) rendered export-bundle bytes.
 */
interface PadesSigner {
	/**
	 * Sign the rendered bundle bytes and return the signed result + hash.
	 *
	 * @param string $bytes The rendered bundle bytes (the PDF disclosure document).
	 *
	 * @return SignedBundle The bytes, their SHA-256 content hash, and the signature state.
	 *
	 * @spec openspec/changes/dsar-case-engine/specs/dsar-export-bundle/spec.md
	 */
	public function sign(string $bytes): SignedBundle;
}//end interface
