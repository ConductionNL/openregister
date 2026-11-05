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

use Throwable;

/**
 * Descriptor and list() of a link-table provider: the link table first, the
 * provider's own fallback when the object has no links.
 *
 * The using provider declares `REQUIRED_APP`, provides `$this->appManager`
 * (IAppManager) and `$this->db` (IDBConnection), uses MarkerLookupTrait, declares
 * `MARKER_PREFIX` and `MARKER_SOURCE`, and implements `linkMapper()` and
 * `rowFromLink(link: ...)`.
 */
trait NcAppLinkTableProvider {

	/**
	 * Nextcloud app that must be installed for this integration to function.
	 *
	 * @return string|null
	 *
	 * @spec exclude Provider descriptor shared by the link-table providers; each provider spec owns the values.
	 */
	public function getRequiredApp(): ?string {
		return self::REQUIRED_APP;
	}//end getRequiredApp()

	/**
	 * Storage strategy (AD-22): links live in an OpenRegister link table.
	 *
	 * @return string
	 *
	 * @spec exclude Provider descriptor shared by the link-table providers; each provider spec owns the values.
	 */
	public function getStorageStrategy(): string {
		return 'link-table';
	}//end getStorageStrategy()

	/**
	 * Whether the backing app is installed.
	 *
	 * @return bool
	 *
	 * @spec exclude Provider descriptor shared by the link-table providers; each provider spec owns the values.
	 */
	public function isEnabled(): bool {
		return $this->appManager->isInstalled(self::REQUIRED_APP);
	}//end isEnabled()

	/**
	 * The rows linked to an object: from the link table, else the provider's fallback.
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
		if ($this->isEnabled() === false) {
			return [];
		}

		$links = $this->linkedRows(objectId: $objectId);
		if (count($links) > 0) {
			return array_map(fn (object $link): array => $this->rowFromLink(link: $link), $links);
		}

		return $this->unlinkedRows(objectId: $objectId);
	}//end list()

	/**
	 * The object's link rows; none when the link table cannot be read.
	 *
	 * @param string $objectId Object uuid.
	 *
	 * @return array<int,object> The link entities.
	 */
	private function linkedRows(string $objectId): array {
		try {
			return $this->linkMapper()->findByObjectUuid($objectId);
		} catch (Throwable $e) {
			return [];
		}
	}//end linkedRows()

	/**
	 * Backwards-compatible fallback for an object without links: the rows that
	 * carry the legacy `[or:{uuid}]` marker in the app's own table.
	 *
	 * @param string $objectId Object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function unlinkedRows(string $objectId): array {
		$source = self::MARKER_SOURCE;
		$rows = $this->findByMarker(
			db: $this->db,
			table: $source['table'],
			markerColumn: $source['column'],
			marker: self::MARKER_PREFIX . $objectId . ']',
			extraColumns: $source['extra'],
			idColumn: $source['id'],
		);

		return array_map(fn (array $row): array => $this->rowFromMarker(row: $row), $rows);
	}//end unlinkedRows()

	/**
	 * One marker-scan row in the registry leaf-row shape.
	 *
	 * @param array<string,mixed> $row The scanned row.
	 *
	 * @return array<string,mixed>
	 */
	private function rowFromMarker(array $row): array {
		$source = self::MARKER_SOURCE;
		$id = (string)($row[$source['id']] ?? '');

		return ['id' => $id, 'title' => (string)($row[$source['column']] ?? ''), 'url' => $source['url'] . $id, 'data' => $row];
	}//end rowFromMarker()
}//end trait
