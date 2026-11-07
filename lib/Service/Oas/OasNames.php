<?php

/**
 * Spells register and schema titles the way an OpenAPI document needs them.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * Names in the generated OpenAPI document.
 *
 * @spec openspec/specs/oas-validation/spec.md#scenario-standard-http-methods-documented-api-01
 */
class OasNames {

	/**
	 * A lowercase slug of a title.
	 *
	 * @param string $string The title
	 *
	 * @return string The slug
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-14
	 */
	public function slug(string $string): string {
		return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string), '-'));
	}//end slug()

	/**
	 * A title in PascalCase.
	 *
	 * @param string $string The title
	 *
	 * @return string The PascalCase string
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-14
	 */
	public function pascalCase(string $string): string {
		return str_replace(' ', '', ucwords(str_replace('-', ' ', $this->slug(string: $string))));
	}//end pascalCase()

	/**
	 * A component name for a schema title, matching `^[a-zA-Z0-9._-]+$`.
	 *
	 * @param string|null $title The schema title
	 *
	 * @return string The component name
	 *
	 * @spec openspec/specs/oas-validation/spec.md#scenario-standard-http-methods-documented-api-01
	 */
	public function schemaName(?string $title): string {
		if (empty($title) === true) {
			return 'UnknownSchema';
		}

		$sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $title);
		$sanitized = trim(preg_replace('/_+/', '_', $sanitized), '_');
		if (empty($sanitized) === true) {
			return 'UnknownSchema';
		}

		if (preg_match('/^[0-9]/', $sanitized) === 1) {
			return 'Schema_' . $sanitized;
		}

		return $sanitized;
	}//end schemaName()
}//end class
