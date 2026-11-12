<?php

/**
 * OpenRegister View Formatting
 *
 * The derived values a saved view serialises: its effective presentation,
 * its managing configuration and how that configuration is found.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Db;

use OCA\OpenRegister\Db\Configuration;

/**
 * Pure helpers behind View's serialised presentation and configuration link.
 *
 * @spec openspec/specs/saved-search-views/spec.md#requirement-views-persist-a-validated-presentation-config-req-view-pres-01
 */
final class ViewFormatting {

	/**
	 * The effective presentation: a missing config or viewType means a table.
	 *
	 * @param mixed $presentation The stored presentation.
	 *
	 * @return array The effective presentation config.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md#requirement-views-persist-a-validated-presentation-config-req-view-pres-01
	 */
	public static function presentation(mixed $presentation): array {
		if (is_array($presentation) === false) {
			return ['viewType' => 'table'];
		}

		if (empty($presentation['viewType']) === true) {
			$presentation['viewType'] = 'table';
		}

		return $presentation;
	}//end presentation()

	/**
	 * The id, uuid and title of the managing configuration, or null.
	 *
	 * @param Configuration|null $configuration The managing configuration.
	 *
	 * @return (int|null|string)[]|null
	 *
	 * @psalm-return array{id: int, uuid: null|string, title: null|string}|null
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public static function managedBy(?Configuration $configuration): ?array {
		if ($configuration !== null) {
			return [
				'id' => $configuration->getId(),
				'uuid' => $configuration->getUuid(),
				'title' => $configuration->getTitle(),
			];
		}

		return null;
	}//end managedBy()

	/**
	 * The first configuration whose views list the id, compared strictly.
	 *
	 * @param mixed               $viewId         The view's id.
	 * @param array<Configuration> $configurations The configurations to check.
	 *
	 * @return Configuration|null The managing configuration, or null.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public static function managing(mixed $viewId, array $configurations): ?Configuration {
		if (empty($configurations) === true || $viewId === null) {
			return null;
		}

		return self::firstListing(viewId: $viewId, configurations: $configurations);
	}//end managing()

	/**
	 * The first configuration whose views list the id, compared strictly.
	 *
	 * @param mixed                $viewId         The view's id, not null.
	 * @param array<Configuration> $configurations The configurations to check.
	 *
	 * @return Configuration|null The first listing configuration, or null.
	 */
	private static function firstListing(mixed $viewId, array $configurations): ?Configuration {
		foreach ($configurations as $configuration) {
			$views = $configuration->getViews();
			if (in_array($viewId, $views, true) === true) {
				return $configuration;
			}
		}

		return null;
	}//end firstListing()
}//end class
