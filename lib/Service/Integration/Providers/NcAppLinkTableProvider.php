<?php

/**
 * NcAppLinkTableProvider: a provider over one Nextcloud app whose links live in an OpenRegister link table.
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

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use Throwable;

/**
 * Descriptor and list() of a link-table provider: the link table first, the
 * provider's own fallback when the object has no links.
 *
 * The using provider declares `REQUIRED_APP`, provides `$this->appManager`
 * (IAppManager), and implements `linkMapper()`, `rowFromLink(link: ...)` and
 * `unlinkedRows()` (MarkerSourceFallback implements the common marker scan).
 * A provider that builds all link rows at once overrides `rowsFromLinks()`.
 */
trait NcAppLinkTableProvider {

	use LinkTableAppDescriptor;

	/**
	 * The rows linked to an object: from the link table, else the provider's fallback.
	 *
	 * @param ObjectRef              $ref The owning object: register, schema and uuid.
	 * @param array<string,mixed> $filters  Optional filters (unused by link-table providers).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-object-scoped-integration-link-rest-contract
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The IntegrationProvider contract fixes the signature.
	 */
	public function list(ObjectRef $ref, array $filters = []): array {
		return $this->objectRows(objectId: $ref->id);
	}//end list()

	/**
	 * The rows linked to an object: none when the app is missing, else the link
	 * table's rows, else the provider's fallback.
	 *
	 * @param string $objectId Object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function objectRows(string $objectId): array {
		if ($this->isEnabled() === false) {
			return [];
		}

		$links = $this->linkedRows(objectId: $objectId);
		if (count($links) > 0) {
			return $this->rowsFromLinks(links: $links);
		}

		return $this->unlinkedRows(objectId: $objectId);
	}//end objectRows()

	/**
	 * The leaf rows of an object's link rows, one per link.
	 *
	 * @param array<int,object> $links The link entities.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function rowsFromLinks(array $links): array {
		return array_map(fn (object $link): array => $this->rowFromLink(link: $link), $links);
	}//end rowsFromLinks()

	/**
	 * The object's link rows; none when the link table cannot be read.
	 *
	 * @param string $objectId Object uuid.
	 *
	 * @return array<int,object> The link entities.
	 */
	protected function linkedRows(string $objectId): array {
		try {
			return $this->linkMapper()->findByObjectUuid($objectId);
		} catch (Throwable $e) {
			return [];
		}
	}//end linkedRows()

	/**
	 * The rows of an object without links.
	 *
	 * @param string $objectId Object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	abstract protected function unlinkedRows(string $objectId): array;
}//end trait
