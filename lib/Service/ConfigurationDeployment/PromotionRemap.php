<?php

/**
 * OpenRegister PromotionRemap
 *
 * The target organisation of a promotion and the table that maps each source uuid to the uuid it gets there.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;
/**
 * Source uuid to target uuid, applied to every string in a definition.
 *
 * @spec openspec/specs/environment-otap/spec.md#requirement-configuration-promotion-must-transfer-settings-between-otap-environments
 */
final class PromotionRemap {

	/**
	 * Constructor.
	 *
	 * @param string                $targetUuid The organisation promoted to.
	 * @param array<string, string> $uuids      Target uuid by source uuid.
	 */
	public function __construct(
		public readonly string $targetUuid,
		public readonly array $uuids,
	) {

	}//end __construct()

	/**
	 * The value with every string that is a source uuid replaced by its target uuid, at any depth.
	 *
	 * Only whole strings are replaced: a uuid inside a longer string (a URL, a
	 * description) is text, and rewriting text is how a promotion corrupts
	 * configuration nobody asked it to touch.
	 *
	 * @param mixed $value A definition or a value inside one.
	 *
	 * @return mixed The remapped value.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
	 */
	public function apply(mixed $value): mixed {
		if (is_string($value) === true) {
			return ($this->uuids[$value] ?? $value);
		}

		if (is_array($value) === false) {
			return $value;
		}

		$remapped = [];
		foreach ($value as $key => $item) {
			$remapped[$key] = $this->apply(value: $item);
		}

		return $remapped;

	}//end apply()
}//end class
