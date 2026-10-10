<?php

/**
 * Judges a form's field-to-property mapping against its destination schema.
 *
 * Decision 179 (ADR-117): a form submits into the object a person works on,
 * so a form must be valid against that object's schema when it is BUILT, not
 * when a resident submits it. This is the one validator for that. Flow task
 * forms ({@see \OCA\OpenRegister\Service\Task\TaskFormReader}) delegate their
 * field check here, so object forms and task forms are judged by one service.
 *
 * A finding is `{ field?, property, code, message }`. The codes are the
 * contract buildiq, portaliq, dossiq and pipelinq read; none of them keeps a
 * copy of these rules.
 *
 * The mapping shape:
 *
 *   {
 *     "fields": [ { "field": "onderwerp", "property": "title", "type": "text",
 *                   "format"?, "options"?, "maxLength"?, "minLength"?,
 *                   "maximum"?, "minimum"?, "pattern"? } ],
 *     "fixed":  { "caseType": "<uuid>" }
 *   }
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Db\Schema;
use OCP\IL10N;

/**
 * The one validator for a form's mapping against its destination.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
 */
class FormDestinationValidator {

	public const REQUIRED_UNMAPPED = 'required-unmapped';
	public const PROPERTY_UNKNOWN = 'property-unknown';
	public const PROPERTY_READ_ONLY = 'property-read-only';
	public const TYPE_MISMATCH = 'type-mismatch';
	public const FORMAT_MISMATCH = 'format-mismatch';
	public const ENUM_UNCONSTRAINED = 'enum-unconstrained';
	public const ENUM_VALUE_UNKNOWN = 'enum-value-unknown';
	public const CONSTRAINT_LOOSER = 'constraint-looser';
	public const FIXED_VALUE_INVALID = 'fixed-value-invalid';
	public const DESTINATION_NOT_PUBLIC = 'destination-not-public';
	public const DESTINATION_IS_STAGING = 'destination-is-staging';

	/**
	 * The property dialect block the two form markers live in.
	 *
	 * @var string
	 */
	public const DIALECT = 'x-openregister';

	/**
	 * Marker: a listener or the server fills this property, so no field needs to.
	 *
	 * @var string
	 */
	public const MARKER_SERVER_SET = 'serverSet';

	/**
	 * Marker: the submit response returns this property to the submitter.
	 *
	 * @var string
	 */
	public const MARKER_CONFIRMATION = 'confirmation';

	/**
	 * Marker: this property is the human-readable reference a submit returns.
	 *
	 * @var string
	 */
	public const MARKER_REFERENCE = 'reference';

	/**
	 * Constructor.
	 *
	 * @param IL10N          $l10n  Translations, for findings an author reads.
	 * @param FormFieldRules $rules The field-to-property compatibility rules.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly FormFieldRules $rules,
	) {

	}//end __construct()

	/**
	 * Every problem with a mapping into a destination schema.
	 *
	 * @param array<string, mixed> $mapping The mapping: `fields` and `fixed`.
	 * @param Schema               $schema  The destination schema.
	 * @param array<string, mixed> $options `audience`: `public` or `authenticated` when the form is not internal.
	 *
	 * @return array<int, array{field?: string, property: string, code: string, message: string}> The findings; empty when valid.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
	 */
	public function validate(array $mapping, Schema $schema, array $options = []): array {
		$fields = $this->fieldsOf(mapping: $mapping);
		$fixed = $this->fixedOf(mapping: $mapping);

		$findings = $this->destinationFindings(schema: $schema, audience: (string)($options['audience'] ?? ''));
		foreach ($fields as $field) {
			array_push($findings, ...$this->fieldFindings(field: $field, schema: $schema));
		}

		foreach ($fixed as $property => $value) {
			array_push($findings, ...$this->fixedFindings(property: (string)$property, value: $value, schema: $schema));
		}

		$sourced = array_merge(
			array_map(static fn (array $field): string => (string)($field['property'] ?? ''), $fields),
			array_map('strval', array_keys($fixed))
		);

		return array_merge($findings, $this->requiredFindings(schema: $schema, sourced: $sourced));
	}//end validate()

	/**
	 * Why a task-form field of this schema cannot be rendered, or null when it can.
	 *
	 * The three reasons are the three the shared renderer drops a property for
	 * before it consults the field whitelist; a declared field hitting any of
	 * them renders nothing at all. Moved here from TaskFormReader, which now
	 * delegates, so the step-save refusal and the form-save findings come from
	 * one service.
	 *
	 * @param Schema $schema The live subject schema.
	 * @param string $field  The property name.
	 *
	 * @return string|null The reason, translated, or null when renderable.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
	 */
	public function unrenderableReason(Schema $schema, string $field): ?string {
		$properties = $schema->getProperties();
		if (array_key_exists($field, $properties) === false) {
			return $this->l10n->t('the schema has no such property.');
		}

		$property = (array)$properties[$field];
		if (($property['readOnly'] ?? false) === true) {
			return $this->l10n->t('the schema marks it read-only, so a submitted value would be refused.');
		}

		if (($property['visible'] ?? true) === false) {
			return $this->l10n->t('the schema marks it not visible, so no form can show it.');
		}

		return null;
	}//end unrenderableReason()

	/**
	 * Whether a property carries a form marker in its `x-openregister` block.
	 *
	 * Only a literal `true` counts: a marker is a declaration, and "yes" or 1
	 * is a typo the author should see fail, not a value to coerce.
	 *
	 * @param array<string, mixed> $property The schema property.
	 * @param string               $name     The marker name.
	 *
	 * @return bool True when marked.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
	 */
	public static function marker(array $property, string $name): bool {
		$block = ($property[self::DIALECT] ?? null);
		if (is_array($block) === false) {
			return false;
		}

		return ($block[$name] ?? null) === true;
	}//end marker()

	/**
	 * The properties of a schema that carry a marker, in declared order.
	 *
	 * @param Schema $schema The schema.
	 * @param string $name   The marker name.
	 *
	 * @return array<int, string> The property names.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public static function markedProperties(Schema $schema, string $name): array {
		$marked = [];
		foreach ($schema->getProperties() as $propertyName => $property) {
			if (is_array($property) === true && self::marker(property: $property, name: $name) === true) {
				$marked[] = (string)$propertyName;
			}
		}

		return $marked;
	}//end markedProperties()

	/**
	 * Findings about the destination itself: staging, or closed to the form's audience.
	 *
	 * @param Schema $schema   The destination schema.
	 * @param string $audience `public`, `authenticated`, or empty for an internal form.
	 *
	 * @return array<int, array{property: string, code: string, message: string}>
	 */
	private function destinationFindings(Schema $schema, string $audience): array {
		$findings = [];
		$slug = (string)$schema->getSlug();
		if (($schema->getConfiguration()['staging'] ?? false) === true) {
			$findings[] = $this->finding(
				property: '',
				code: self::DESTINATION_IS_STAGING,
				message: $this->l10n->t('Schema "%1$s" is marked as a staging object. A form submits into the object a person works on, not into a layer before it.', [$slug])
			);
		}

		if ($audience !== '' && $this->grantsCreateTo(schema: $schema, audience: $audience) === false) {
			$findings[] = $this->finding(
				property: '',
				code: self::DESTINATION_NOT_PUBLIC,
				message: $this->l10n->t('Schema "%1$s" does not let a %2$s visitor create objects, so this form could never be submitted.', [$slug, $audience])
			);
		}

		return $findings;
	}//end destinationFindings()

	/**
	 * Whether the schema lets the audience create objects.
	 *
	 * An empty authorization block is open to everyone; a non-empty one is
	 * default-deny per action, the same rule the save path applies.
	 *
	 * @param Schema $schema   The destination schema.
	 * @param string $audience `public` or `authenticated`.
	 *
	 * @return bool True when granted.
	 */
	private function grantsCreateTo(Schema $schema, string $audience): bool {
		$authorization = ($schema->getAuthorization() ?? []);
		if ($authorization === []) {
			return true;
		}

		$accepted = ['public'];
		if ($audience === 'authenticated') {
			$accepted[] = 'authenticated';
		}

		foreach ((array)($authorization['create'] ?? []) as $entry) {
			$group = $entry;
			if (is_array($entry) === true) {
				$group = ($entry['group'] ?? null);
			}

			if (is_string($group) === true && in_array($group, $accepted, true) === true) {
				return true;
			}
		}

		return false;
	}//end grantsCreateTo()

	/**
	 * Findings for one mapped field.
	 *
	 * @param array<string, mixed> $field  The mapping entry.
	 * @param Schema               $schema The destination schema.
	 *
	 * @return array<int, array{field?: string, property: string, code: string, message: string}>
	 */
	private function fieldFindings(array $field, Schema $schema): array {
		$name = (string)($field['field'] ?? '');
		$propertyName = (string)($field['property'] ?? '');
		$properties = $schema->getProperties();
		if ($propertyName === '' || array_key_exists($propertyName, $properties) === false) {
			if ($propertyName !== '' && $this->allowsExtras(schema: $schema) === true) {
				return [];
			}

			return [
				$this->finding(
					property: $propertyName,
					code: self::PROPERTY_UNKNOWN,
					message: $this->l10n->t('Field "%1$s" writes into "%2$s", which schema "%3$s" does not have.', [$name, $propertyName, (string)$schema->getSlug()]),
					field: $name
				),
			];
		}

		$property = (array)$properties[$propertyName];
		if (($property['readOnly'] ?? false) === true) {
			return [
				$this->finding(
					property: $propertyName,
					code: self::PROPERTY_READ_ONLY,
					message: $this->l10n->t('Field "%1$s" writes into "%2$s", which is read-only, so the save would refuse it.', [$name, $propertyName]),
					field: $name
				),
			];
		}

		$shape = $this->shapeFindings(field: $field, property: $property, name: $name, propertyName: $propertyName);
		if ($shape !== [] && $shape[0]['code'] === self::TYPE_MISMATCH) {
			// A text field into an integer: its bounds and options say nothing more.
			return $shape;
		}

		return array_merge(
			$shape,
			$this->enumFindings(field: $field, property: $property, name: $name, propertyName: $propertyName),
			$this->constraintFindings(field: $field, property: $property, name: $name, propertyName: $propertyName)
		);
	}//end fieldFindings()

	/**
	 * Type and format findings for one field.
	 *
	 * @param array<string, mixed> $field        The mapping entry.
	 * @param array<string, mixed> $property     The schema property.
	 * @param string               $name         The field name.
	 * @param string               $propertyName The property name.
	 *
	 * @return array<int, array{field?: string, property: string, code: string, message: string}>
	 */
	private function shapeFindings(array $field, array $property, string $name, string $propertyName): array {
		if ($this->rules->typeFits(field: $field, property: $property) === false) {
			return [
				$this->finding(
					property: $propertyName,
					code: self::TYPE_MISMATCH,
					message: $this->l10n->t(
						'Field "%1$s" is a %2$s field and cannot produce the %3$s that "%4$s" holds.',
						[$name, (string)($field['type'] ?? ''), $this->typeLabel(property: $property), $propertyName]
					),
					field: $name
				),
			];
		}

		if ($this->rules->formatFits(field: $field, property: $property) === false) {
			return [
				$this->finding(
					property: $propertyName,
					code: self::FORMAT_MISMATCH,
					message: $this->l10n->t(
						'Field "%1$s" does not produce the %2$s format that "%3$s" demands.',
						[$name, (string)($property['format'] ?? ''), $propertyName]
					),
					field: $name
				),
			];
		}

		return [];
	}//end shapeFindings()

	/**
	 * Enum findings for one field: free text into an enum, or options outside it.
	 *
	 * @param array<string, mixed> $field        The mapping entry.
	 * @param array<string, mixed> $property     The schema property.
	 * @param string               $name         The field name.
	 * @param string               $propertyName The property name.
	 *
	 * @return array<int, array{field?: string, property: string, code: string, message: string}>
	 */
	private function enumFindings(array $field, array $property, string $name, string $propertyName): array {
		$enum = $this->rules->enumOf(property: $property);
		if ($enum === null) {
			return [];
		}

		$options = $this->rules->optionsOf(field: $field);
		if ($options === null) {
			return [
				$this->finding(
					property: $propertyName,
					code: self::ENUM_UNCONSTRAINED,
					message: $this->l10n->t('Field "%1$s" lets anything be typed, but "%2$s" accepts only: %3$s.', [$name, $propertyName, $this->listOf(values: $enum)]),
					field: $name
				),
			];
		}

		$findings = [];
		foreach ($options as $option) {
			if (in_array($option, $enum, true) === true) {
				continue;
			}

			$findings[] = $this->finding(
				property: $propertyName,
				code: self::ENUM_VALUE_UNKNOWN,
				message: $this->l10n->t('Field "%1$s" offers "%2$s", which "%3$s" does not accept.', [$name, $this->scalarLabel(value: $option), $propertyName]),
				field: $name
			);
		}

		return $findings;
	}//end enumFindings()

	/**
	 * Constraint findings for one field: one per bound the field leaves looser.
	 *
	 * @param array<string, mixed> $field        The mapping entry.
	 * @param array<string, mixed> $property     The schema property.
	 * @param string               $name         The field name.
	 * @param string               $propertyName The property name.
	 *
	 * @return array<int, array{field?: string, property: string, code: string, message: string}>
	 */
	private function constraintFindings(array $field, array $property, string $name, string $propertyName): array {
		$findings = [];
		foreach ($this->rules->looserConstraints(field: $field, property: $property) as $constraint) {
			$findings[] = $this->finding(
				property: $propertyName,
				code: self::CONSTRAINT_LOOSER,
				message: $this->l10n->t(
					'Field "%1$s" allows more than "%2$s" does: set %3$s to %4$s or tighter.',
					[$name, $propertyName, $constraint, $this->scalarLabel(value: $property[$constraint])]
				),
				field: $name
			);
		}

		return $findings;
	}//end constraintFindings()

	/**
	 * Findings for one fixed value.
	 *
	 * @param string $property The property the value is fixed into.
	 * @param mixed  $value    The fixed value.
	 * @param Schema $schema   The destination schema.
	 *
	 * @return array<int, array{property: string, code: string, message: string}>
	 */
	private function fixedFindings(string $property, mixed $value, Schema $schema): array {
		$properties = $schema->getProperties();
		if (array_key_exists($property, $properties) === false) {
			if ($this->allowsExtras(schema: $schema) === true) {
				return [];
			}

			return [
				$this->finding(
					property: $property,
					code: self::PROPERTY_UNKNOWN,
					message: $this->l10n->t('A fixed value writes into "%1$s", which schema "%2$s" does not have.', [$property, (string)$schema->getSlug()])
				),
			];
		}

		if ($this->rules->fixedValueFits(value: $value, property: (array)$properties[$property]) === true) {
			return [];
		}

		return [
			$this->finding(
				property: $property,
				code: self::FIXED_VALUE_INVALID,
				message: $this->l10n->t('The fixed value for "%1$s" is one the property does not accept.', [$property])
			),
		];
	}//end fixedFindings()

	/**
	 * Findings for required properties no field, fixed value or server default fills.
	 *
	 * @param Schema             $schema  The destination schema.
	 * @param array<int, string> $sourced The properties the mapping fills.
	 *
	 * @return array<int, array{property: string, code: string, message: string}>
	 */
	private function requiredFindings(Schema $schema, array $sourced): array {
		$properties = $schema->getProperties();
		$required = $schema->getRequired();
		foreach ($properties as $name => $property) {
			if (is_array($property) === true && ($property['required'] ?? false) === true) {
				$required[] = (string)$name;
			}
		}

		$findings = [];
		foreach (array_values(array_unique(array_map('strval', $required))) as $name) {
			$property = (array)($properties[$name] ?? []);
			if (in_array($name, $sourced, true) === true || $this->isServerFilled(property: $property) === true) {
				continue;
			}

			$findings[] = $this->finding(
				property: $name,
				code: self::REQUIRED_UNMAPPED,
				message: $this->l10n->t('"%1$s" is required, and no field, fixed value or server default fills it.', [$name])
			);
		}

		return $findings;
	}//end requiredFindings()

	/**
	 * Whether the server fills a property: a default, a computation, or the serverSet marker.
	 *
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return bool True when no field needs to.
	 */
	private function isServerFilled(array $property): bool {
		if (array_key_exists('default', $property) === true || isset($property['computed']) === true) {
			return true;
		}

		return self::marker(property: $property, name: self::MARKER_SERVER_SET);
	}//end isServerFilled()

	/**
	 * Whether the schema explicitly accepts properties it does not declare.
	 *
	 * @param Schema $schema The destination schema.
	 *
	 * @return bool True only for an explicit opt-in.
	 */
	private function allowsExtras(Schema $schema): bool {
		return (($schema->getConfiguration() ?? [])['additionalProperties'] ?? false) === true;
	}//end allowsExtras()

	/**
	 * The `fields` list of a mapping, entries that are not arrays dropped.
	 *
	 * @param array<string, mixed> $mapping The mapping.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fieldsOf(array $mapping): array {
		return array_values(array_filter((array)($mapping['fields'] ?? []), 'is_array'));
	}//end fieldsOf()

	/**
	 * The `fixed` map of a mapping.
	 *
	 * @param array<string, mixed> $mapping The mapping.
	 *
	 * @return array<string, mixed>
	 */
	private function fixedOf(array $mapping): array {
		$fixed = ($mapping['fixed'] ?? []);
		if (is_array($fixed) === false) {
			return [];
		}

		return $fixed;
	}//end fixedOf()

	/**
	 * One finding, the field key present only when a field is named.
	 *
	 * @param string      $property The property.
	 * @param string      $code     The finding code.
	 * @param string      $message  The translated message.
	 * @param string|null $field    The field, when the finding is about one.
	 *
	 * @return array{field?: string, property: string, code: string, message: string}
	 */
	private function finding(string $property, string $code, string $message, ?string $field = null): array {
		$finding = [];
		if ($field !== null) {
			$finding['field'] = $field;
		}

		$finding['property'] = $property;
		$finding['code'] = $code;
		$finding['message'] = $message;

		return $finding;
	}//end finding()

	/**
	 * A property's type, for a message.
	 *
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return string The type, or types joined.
	 */
	private function typeLabel(array $property): string {
		$type = ($property['type'] ?? '');
		if (is_array($type) === true) {
			return implode('/', array_map('strval', $type));
		}

		return (string)$type;
	}//end typeLabel()

	/**
	 * A list of values, for a message.
	 *
	 * @param array<int, mixed> $values The values.
	 *
	 * @return string The values joined.
	 */
	private function listOf(array $values): string {
		return implode(', ', array_map(fn ($value): string => $this->scalarLabel(value: $value), $values));
	}//end listOf()

	/**
	 * A value, for a message.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The printable form.
	 */
	private function scalarLabel(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return (string)json_encode($value);
	}//end scalarLabel()
}//end class
