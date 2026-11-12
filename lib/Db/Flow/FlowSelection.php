<?php

/**
 * FlowSelection: which stored flows a listing or a count covers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Flow;

/**
 * Which flows a listing or count covers. A null or empty field does not restrict.
 *
 * @spec openspec/specs/flow-storage/spec.md
 */
final class FlowSelection {

	/**
	 * Constructor.
	 *
	 * @param string|null $app             Restrict to one owning app id.
	 * @param string|null $applicationSlug Restrict to one OpenBuild virtual-app slug.
	 * @param string|null $organisation    Restrict to one organisation uuid.
	 * @param bool|null   $enabled         Restrict to enabled or disabled flows.
	 */
	public function __construct(
		public readonly ?string $app = null,
		public readonly ?string $applicationSlug = null,
		public readonly ?string $organisation = null,
		public readonly ?bool $enabled = null,
	) {
	}//end __construct()

	/**
	 * The non-empty column restrictions, in the order the queries apply them.
	 *
	 * @return array<string, string> Column to value.
	 *
	 * @spec openspec/specs/flow-storage/spec.md
	 */
	public function columnValues(): array {
		$values = ['app' => $this->app, 'application_slug' => $this->applicationSlug, 'organisation' => $this->organisation];

		return array_filter($values, static fn (?string $value): bool => $value !== null && $value !== '');
	}//end columnValues()
}//end class
