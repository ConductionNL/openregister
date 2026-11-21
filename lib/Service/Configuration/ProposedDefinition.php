<?php

/**
 * OpenRegister proposed definition
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Configuration;

/**
 * A register or schema definition a remote configuration proposes to import, as the preview reads it.
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-the-configuration-preview-names-what-an-import-would-change
 */
final class ProposedDefinition {
	/**
	 * Hold the proposal.
	 *
	 * @param string               $type `register` or `schema`.
	 * @param string               $slug The lowercased slug.
	 * @param array<string, mixed> $data The remote definition.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $slug,
		public readonly array $data,
	) {
	}//end __construct()

	/**
	 * The title a preview row shows: the definition's title, else its slug.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-the-configuration-preview-names-what-an-import-would-change
	 */
	public function title(): mixed {
		return $this->data['title'] ?? $this->slug;
	}//end title()
}//end class
