<?php
/**
 * OpenRegister Register Import Document
 *
 * The register document a counted re-import imports.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The register slug the caller named, every slug the document declares, and its version and fragment count.
 *
 * Its release() returns a copy; a document is never changed in place.
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */
final class RegisterImportDocument {

	/**
	 * The version of the imported document.
	 *
	 * @var string
	 */
	private string $version = '';

	/**
	 * How many register.d fragments it carries.
	 *
	 * @var integer
	 */
	private int $fragments = 0;

	/**
	 * Constructor.
	 *
	 * @param string             $slug  The register slug the caller named.
	 * @param array<int, string> $slugs Every register slug the document declares, lowercased.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function __construct(
		public readonly string $slug,
		public readonly array $slugs,
	) {
	}//end __construct()

	/**
	 * A copy with the given version and fragment count.
	 *
	 * @param string $version   The version of the imported document.
	 * @param int    $fragments How many register.d fragments it carries.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function release(string $version, int $fragments): self {
		$copy            = clone $this;
		$copy->version   = $version;
		$copy->fragments = $fragments;
		return $copy;
	}//end release()

	/**
	 * The version of the imported document.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function version(): string {
		return $this->version;
	}//end version()

	/**
	 * How many register.d fragments it carries.
	 *
	 * @return integer
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function fragments(): int {
		return $this->fragments;
	}//end fragments()
}//end class
