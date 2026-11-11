<?php

/**
 * OpenRegister JsonFieldsTrait
 *
 * Lists the fields of an entity that are of type json.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Lists the fields of the using entity that are of type json.
 */
trait JsonFieldsTrait {

	/**
	 * Returns all fields that are of type 'json'
	 *
	 * @return string[] List of JSON field names
	 *
	 * @psalm-return list<string>
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function getJsonFields(): array {
		return array_keys(
			array_filter(
				$this->getFieldTypes(),
				static function ($field) {
					return $field === 'json';
				}
			)
		);
	}//end getJsonFields()
}//end trait
