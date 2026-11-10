<?php

/**
 * NcAppLinkRowsProvider: a provider over one Nextcloud app that lists only its link-table rows.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

/**
 * Descriptor and list() of a link-table provider without a fallback: an object
 * without links has no rows, and a link table that cannot be read throws.
 *
 * The using provider declares `REQUIRED_APP`, provides `$this->appManager`
 * (IAppManager), and implements `linkMapper()` and `rowFromLink(link: ...)`.
 */
trait NcAppLinkRowsProvider {

	use LinkTableAppDescriptor;

	/**
	 * The rows linked to an object, one per link-table row.
	 *
	 * @param string              $register Register slug or numeric id.
	 * @param string              $schema   Schema slug or numeric id.
	 * @param string              $objectId Object uuid.
	 * @param array<string,mixed> $filters  Optional filters (unused by link-table providers).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-object-scoped-integration-link-rest-contract
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The IntegrationProvider contract fixes the signature.
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		return $this->rowsOf(objectId: $objectId);
	}//end list()

	/**
	 * The rows of the links an object has; none while the backing app is off.
	 *
	 * @param string $objectId Owning object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rowsOf(string $objectId): array {
		if ($this->isEnabled() === false) {
			return [];
		}

		return array_values(array_map(fn (object $link): array => $this->rowFromLink(link: $link), $this->linksOf(objectId: $objectId)));
	}//end rowsOf()

	/**
	 * The object's link rows.
	 *
	 * @param string $objectId Object uuid.
	 *
	 * @return array<int,object> The link entities.
	 */
	private function linksOf(string $objectId): array {
		return $this->linkMapper()->findByObjectUuid($objectId);
	}//end linksOf()
}//end trait
