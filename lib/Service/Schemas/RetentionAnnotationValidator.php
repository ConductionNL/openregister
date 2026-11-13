<?php

/**
 * Refuses an `x-openregister-retention` declaration that cannot be followed
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use OCA\OpenRegister\Service\Archival\InheritedAppraisal;
use OCA\OpenRegister\Service\Relation\RelationAnnotationValidator;

/**
 * Checks `inheritAppraisalFrom` against the schema's own properties.
 *
 * A name that is not a property, or a property that holds no reference, would
 * be followed to nothing, so the declaration would hold nothing back while its
 * author believed a parent's permanent appraisal protected the records.
 *
 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
 */
class RetentionAnnotationValidator {

	/**
	 * Why a declaration is refused; none when it is sound or absent.
	 *
	 * @param mixed                $annotation The `x-openregister-retention` value.
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
	 */
	public function validate(mixed $annotation, array $properties): array {
		if ($annotation === null) {
			return [];
		}

		if (is_array($annotation) === false || ($annotation !== [] && array_is_list($annotation) === true)) {
			return ['The annotation must be an object.'];
		}

		if (array_key_exists(InheritedAppraisal::KEY, $annotation) === false) {
			return [];
		}

		$declared = $annotation[InheritedAppraisal::KEY];
		if (is_array($declared) === false || array_is_list($declared) === false) {
			return [InheritedAppraisal::KEY.' must be a list of property names.'];
		}

		$errors = [];
		foreach ($declared as $name) {
			$errors = array_merge($errors, self::refusalsOf(name: $name, properties: $properties));
		}

		return $errors;
	}//end validate()

	/**
	 * Why one declared name is refused.
	 *
	 * @param mixed                $name       The declared name.
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return list<string>
	 */
	private static function refusalsOf(mixed $name, array $properties): array {
		if (is_string($name) === false || trim($name) === '') {
			return [InheritedAppraisal::KEY.' must name properties by their non-empty name.'];
		}

		if (array_key_exists($name, $properties) === false) {
			return [sprintf("%s names '%s', which is not a property of this schema.", InheritedAppraisal::KEY, $name)];
		}

		if (RelationAnnotationValidator::isReferenceProperty(property: $properties[$name]) === false) {
			return [sprintf("%s names '%s', which does not reference other objects.", InheritedAppraisal::KEY, $name)];
		}

		return [];
	}//end refusalsOf()
}//end class
