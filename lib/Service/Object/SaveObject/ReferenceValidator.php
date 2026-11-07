<?php

/**
 * OpenRegister ReferenceValidator
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ReferenceValidationException;
use Psr\Log\LoggerInterface;

/**
 * Validates the references of an object's data for every property that asks for it.
 *
 * A cross-app reference written as an HTTP(S) URL is never fetched (a component may
 * not read another for security or tenancy reasons); only its syntax is checked. In
 * `warn` mode a refusal is logged after its failure event fired and the save proceeds,
 * so schema authors can adopt validation gradually on registers with known dirty data.
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */
final class ReferenceValidator {

	/**
	 * The prefix of a local schema path.
	 */
	private const SCHEMA_PATH = '#/components/schemas/';

	/**
	 * @param ReferenceExistence   $existence The existence check.
	 * @param ReferenceFilterGuard $filter    The picker filter check.
	 * @param ReferenceEvents      $events    Announces a malformed URL.
	 * @param LoggerInterface      $logger    The logger.
	 */
	public function __construct(
		private readonly ReferenceExistence $existence,
		private readonly ReferenceFilterGuard $filter,
		private readonly ReferenceEvents $events,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Validate every property of a schema against the run's data.
	 *
	 * @param Schema               $schema The schema containing property definitions.
	 * @param ReferencePropertyRun $run    The validation pass.
	 *
	 * @return void
	 *
	 * @throws ReferenceValidationException In strict mode, when a reference is refused.
	 *
	 * @spec openspec/specs/reference-existence-validation/spec.md
	 */
	public function validate(Schema $schema, ReferencePropertyRun $run): void {
		foreach (($schema->getProperties() ?? []) as $propertyName => $property) {
			$run->propertyName = $propertyName;
			$run->property = $property;
			$this->validateProperty(run: $run);
		}
	}//end validate()

	/**
	 * Validate the references one property holds, when its schema enables validation.
	 *
	 * Null or empty values, and values unchanged on an update, are skipped.
	 * Legacy `validateReference: false` (or absent) costs nothing.
	 *
	 * @param ReferencePropertyRun $run The validation pass, on one property.
	 *
	 * @return void
	 */
	private function validateProperty(ReferencePropertyRun $run): void {
		$property = $run->property;
		$run->strictness = (new ReferenceStrictness())->forProperty(property: $property);
		$ref = $property['$ref'] ?? $property['items']['$ref'] ?? null;
		$value = $run->data[$run->propertyName] ?? null;
		if ($run->strictness === null || $ref === null || self::isSkipped(run: $run, value: $value) === true) {
			return;
		}

		$run->ref = $ref;
		$run->targetRegister = $property['register'] ?? $run->register;
		$this->validateValues(run: $run, values: self::values(property: $property, value: $value));
	}//end validateProperty()

	/**
	 * Validate each non-empty value of the run's property.
	 *
	 * @param ReferencePropertyRun $run    The validation pass, on one property.
	 * @param array                $values The reference values.
	 *
	 * @return void
	 */
	private function validateValues(ReferencePropertyRun $run, array $values): void {
		foreach ($values as $uuid) {
			if (empty($uuid) === false) {
				$run->uuid = (string)$uuid;
				$this->validateOne(run: $run);
			}
		}
	}//end validateValues()

	/**
	 * Whether a value holds nothing to check: empty, or unchanged on an update.
	 *
	 * @param ReferencePropertyRun $run   The validation pass, on one property.
	 * @param mixed                $value The incoming value.
	 *
	 * @return bool
	 */
	private static function isSkipped(ReferencePropertyRun $run, mixed $value): bool {
		if ($value === null || $value === '') {
			return true;
		}

		return $run->oldData !== null && array_key_exists($run->propertyName, $run->oldData) === true
			&& $run->oldData[$run->propertyName] === $value;
	}//end isSkipped()

	/**
	 * The reference values of a property: the list of an array property, else the single value.
	 *
	 * @param array $property The property definition.
	 * @param mixed $value    The value.
	 *
	 * @return array The values to validate.
	 */
	private static function values(array $property, mixed $value): array {
		if (isset($property['type']) === true && $property['type'] === 'array' && is_array($value) === true) {
			return $value;
		}

		return [$value];
	}//end values()

	/**
	 * Validate the run's reference, logging a refusal in warn mode.
	 *
	 * @param ReferencePropertyRun $run The validation pass, on one reference.
	 *
	 * @return void
	 *
	 * @throws ReferenceValidationException In strict mode, when the reference is refused.
	 */
	private function validateOne(ReferencePropertyRun $run): void {
		try {
			$this->check(run: $run);
		} catch (ReferenceValidationException $exception) {
			if ($run->strictness !== 'warn') {
				throw $exception;
			}

			$this->logWarned(run: $run, exception: $exception);
		}
	}//end validateOne()

	/**
	 * Log a refusal that warn mode lets through.
	 *
	 * @param ReferencePropertyRun         $run       The validation pass, on one reference.
	 * @param ReferenceValidationException $exception The refusal.
	 *
	 * @return void
	 */
	private function logWarned(ReferencePropertyRun $run, ReferenceValidationException $exception): void {
		$this->logger->warning(
			message: '[SaveObject] Reference validation failed (warn-only)',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'property' => $run->propertyName,
				'uuid' => $run->uuid,
				'targetSchema' => $exception->getTargetSchemaSlug(),
				'targetRegister' => $exception->getTargetRegister(),
			]
		);
	}//end logWarned()

	/**
	 * URL syntax for a cross-app URL, else existence and the picker filter.
	 *
	 * @param ReferencePropertyRun $run The validation pass, on one reference.
	 *
	 * @return void
	 */
	private function check(ReferencePropertyRun $run): void {
		// Scheme prefix only: once a value says http:// or https:// the refusal
		// speaks in URL terms, not in UUID-not-found terms.
		if (str_starts_with($run->uuid, 'http://') === true || str_starts_with($run->uuid, 'https://') === true) {
			$this->assertWellFormedUrl(run: $run);
			return;
		}

		$this->existence->assertExists(check: self::checkOf(run: $run));
		$this->filter->assertMatches(run: $run);
	}//end check()

	/**
	 * Refuse an external URL that is not syntactically well formed.
	 *
	 * Component A may not have read access to component B, so fetching the URL
	 * would pass falsely or block legitimate writes: the design decision
	 * (`reference-existence-validation` tasks.md 2026-05-02) is syntax only.
	 * A miss announces itself like a UUID miss, so warn-mode listeners see it.
	 *
	 * @param ReferencePropertyRun $run The validation pass, on one reference.
	 *
	 * @return void
	 *
	 * @throws ReferenceValidationException When the URL syntax is invalid.
	 */
	private function assertWellFormedUrl(ReferencePropertyRun $run): void {
		if (self::isWellFormedUrl(value: $run->uuid) === true) {
			return;
		}

		$check = self::checkOf(run: $run);
		$check->targetSchemaSlug = self::bareSlug(schemaRef: $run->ref);
		$this->events->refused(check: $check);

		throw self::malformedUrl(check: $check);
	}//end assertWellFormedUrl()

	/**
	 * The refusal of a malformed URL.
	 *
	 * @param ReferenceCheck $check The reference.
	 *
	 * @return ReferenceValidationException
	 */
	private static function malformedUrl(ReferenceCheck $check): ReferenceValidationException {
		return new ReferenceValidationException(
			propertyName: $check->propertyName,
			referencedUuid: $check->uuid,
			targetSchemaSlug: $check->targetSchemaSlug,
			targetRegister: $check->register,
			message: sprintf(
				'External URL reference "%s" is malformed: scheme MUST be http or https and host MUST parse.',
				$check->uuid
			)
		);
	}//end malformedUrl()

	/**
	 * Whether a URL has an http or https scheme and a host.
	 *
	 * @param string $value The URL.
	 *
	 * @return bool
	 */
	private static function isWellFormedUrl(string $value): bool {
		$parts = parse_url($value);
		if (is_array($parts) === false) {
			return false;
		}

		return in_array(($parts['scheme'] ?? ''), ['http', 'https'], true) === true && ($parts['host'] ?? '') !== '';
	}//end isWellFormedUrl()

	/**
	 * A local schema path reduced to its slug, the convention of the UUID path.
	 *
	 * @param string $schemaRef The schema reference.
	 *
	 * @return string
	 */
	private static function bareSlug(string $schemaRef): string {
		if (str_starts_with($schemaRef, self::SCHEMA_PATH) === true) {
			return substr($schemaRef, strlen(self::SCHEMA_PATH));
		}

		return $schemaRef;
	}//end bareSlug()

	/**
	 * The check of the run's reference.
	 *
	 * @param ReferencePropertyRun $run The validation pass, on one reference.
	 *
	 * @return ReferenceCheck
	 */
	private static function checkOf(ReferencePropertyRun $run): ReferenceCheck {
		$check = new ReferenceCheck();
		$check->propertyName = (string)$run->propertyName;
		$check->uuid = $run->uuid;
		$check->schemaRef = $run->ref;
		$check->register = $run->targetRegister;

		return $check;
	}//end checkOf()
}//end class
