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
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration;
use OCP\IL10N;

/**
 * The one validator for a form's mapping against its destination.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The complexity IS the finding list:
 * every branch is one named reason a field cannot produce what its property holds. Split,
 * the codes and the rules that raise them would sit in files that must agree.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
 */
class FormDestinationValidator implements FormDestinationValidatorInterface {

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
		$findings = array_merge(
			$this->destinationFindings(schema: $schema, audience: (string)($options['audience'] ?? '')),
			$this->allFieldFindings(fields: $fields, schema: $schema),
			$this->allFixedFindings(fixed: $fixed, schema: $schema)
		);

		return array_merge($findings, $this->requiredFindings(schema: $schema, sourced: $this->sourcedBy(fields: $fields, fixed: $fixed)));
	}//end validate()

	/**
	 * The findings of every mapped field, in order.
	 *
	 * @param array<int, array<string, mixed>> $fields The mapping's fields.
	 * @param Schema                           $schema The destination schema.
	 *
	 * @return array<int, array{field?: string, property: string, code: string, message: string}>
	 */
	private function allFieldFindings(array $fields, Schema $schema): array {
		$findings = [];
		foreach ($fields as $field) {
			array_push($findings, ...$this->fieldFindings(field: $field, schema: $schema));
		}

		return $findings;
	}//end allFieldFindings()

	/**
	 * The findings of every fixed value, in order.
	 *
	 * @param array<string, mixed> $fixed  The mapping's fixed values.
	 * @param Schema               $schema The destination schema.
	 *
	 * @return array<int, array{property: string, code: string, message: string}>
	 */
	private function allFixedFindings(array $fixed, Schema $schema): array {
		$findings = [];
		foreach ($fixed as $property => $value) {
			array_push($findings, ...$this->fixedFindings(fixed: new FixedValue(property: (string)$property, value: $value), schema: $schema));
		}

		return $findings;
	}//end allFixedFindings()

	/**
	 * The properties a mapping fills: each field's property, then each fixed value's.
	 *
	 * @param array<int, array<string, mixed>> $fields The mapping's fields.
	 * @param array<string, mixed>             $fixed  The mapping's fixed values.
	 *
	 * @return array<int, string> The property names.
	 */
	private function sourcedBy(array $fields, array $fixed): array {
		return array_merge(
			array_map(static fn (array $field): string => (string)($field['property'] ?? ''), $fields),
			array_map('strval', array_keys($fixed))
		);
	}//end sourcedBy()

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
		if (($schema->getConfiguration()['staging'] ?? false) === true) {
			$findings[] = $this->stagingFinding(slug: (string)$schema->getSlug());
		}

		if ($audience !== '' && $this->grantsCreateTo(schema: $schema, audience: $audience) === false) {
			$findings[] = $this->closedFinding(slug: (string)$schema->getSlug(), audience: $audience);
		}

		return $findings;
	}//end destinationFindings()

	/**
	 * The finding for a staging schema used as a destination.
	 *
	 * @param string $slug The schema's slug.
	 *
	 * @return array{property: string, code: string, message: string}
	 */
	private function stagingFinding(string $slug): array {
		return (new FormFinding(
			property: '',
			code: self::DESTINATION_IS_STAGING,
			message: $this->l10n->t(
				'Schema "%1$s" is marked as a staging object. A form submits into the object a person works on, not into a layer before it.',
				[$slug]
			)
		))->toArray();
	}//end stagingFinding()

	/**
	 * The finding for a destination that does not let the form's audience create.
	 *
	 * @param string $slug     The schema's slug.
	 * @param string $audience `public` or `authenticated`.
	 *
	 * @return array{property: string, code: string, message: string}
	 */
	private function closedFinding(string $slug, string $audience): array {
		return (new FormFinding(
			property: '',
			code: self::DESTINATION_NOT_PUBLIC,
			message: $this->l10n->t('Schema "%1$s" does not let a %2$s visitor create objects, so this form could never be submitted.', [$slug, $audience])
		))->toArray();
	}//end closedFinding()

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

		$accepted = $this->acceptedGroups(audience: $audience);
		foreach ((array)($authorization['create'] ?? []) as $entry) {
			if (in_array($this->groupOf(entry: $entry), $accepted, true) === true) {
				return true;
			}
		}

		return false;
	}//end grantsCreateTo()

	/**
	 * The create grants that serve an audience: `public`, and for an authenticated audience also `authenticated`.
	 *
	 * @param string $audience `public` or `authenticated`.
	 *
	 * @return array<int, string> The groups.
	 */
	private function acceptedGroups(string $audience): array {
		$accepted = ['public'];
		if ($audience === 'authenticated') {
			$accepted[] = 'authenticated';
		}

		return $accepted;
	}//end acceptedGroups()

	/**
	 * The group a create grant names: the entry itself, or its `group`; null when that is not a string.
	 *
	 * @param mixed $entry The grant entry.
	 *
	 * @return string|null The group.
	 */
	private function groupOf(mixed $entry): ?string {
		$group = $entry;
		if (is_array($entry) === true) {
			$group = ($entry['group'] ?? null);
		}

		if (is_string($group) === true) {
			return $group;
		}

		return null;
	}//end groupOf()

	/**
	 * Findings for one mapped field.
	 *
	 * @param array<string, mixed> $field  The mapping entry.
	 * @param Schema               $schema The destination schema.
	 *
	 * @return array<int, array{field?: string, property: string, code: string, message: string}>
	 */
	private function fieldFindings(array $field, Schema $schema): array {
		$properties = $schema->getProperties();
		$mapped = new MappedField(field: $field, property: (array)($properties[(string)($field['property'] ?? '')] ?? []));
		if ($mapped->propertyName === '' || array_key_exists($mapped->propertyName, $properties) === false) {
			return $this->unknownPropertyFindings(mapped: $mapped, schema: $schema);
		}

		return $this->knownPropertyFindings(mapped: $mapped);
	}//end fieldFindings()

	/**
	 * Findings for a field that writes into a property the schema does not declare.
	 *
	 * @param MappedField $mapped The field and its (empty) property.
	 * @param Schema      $schema The destination schema.
	 *
	 * @return array<int, array{field?: string, property: string, code: string, message: string}>
	 */
	private function unknownPropertyFindings(MappedField $mapped, Schema $schema): array {
		if ($mapped->propertyName !== '' && $this->allowsExtras(schema: $schema) === true) {
			return [];
		}

		$message = $this->l10n->t(
			'Field "%1$s" writes into "%2$s", which schema "%3$s" does not have.',
			[$mapped->name, $mapped->propertyName, (string)$schema->getSlug()]
		);

		return [$mapped->finding(code: self::PROPERTY_UNKNOWN, message: $message)];
	}//end unknownPropertyFindings()

	/**
	 * Findings for a field that writes into a declared property.
	 *
	 * @param MappedField $mapped The field and its property.
	 *
	 * @return array<int, array{field?: string, property: string, code: string, message: string}>
	 */
	private function knownPropertyFindings(MappedField $mapped): array {
		// A read-only property, or a text field into an integer: nothing else about the field matters.
		$blocking = $this->blockingFinding(mapped: $mapped);
		if ($blocking !== null) {
			return [$blocking];
		}

		return array_merge(
			$this->formatFindings(mapped: $mapped),
			$this->enumFindings(mapped: $mapped),
			$this->constraintFindings(mapped: $mapped)
		);
	}//end knownPropertyFindings()

	/**
	 * The finding that makes every other finding about a field moot: read-only, or a type mismatch.
	 *
	 * @param MappedField $mapped The field and its property.
	 *
	 * @return array{field: string, property: string, code: string, message: string}|null The finding, or null when none blocks.
	 */
	private function blockingFinding(MappedField $mapped): ?array {
		if (($mapped->property['readOnly'] ?? false) === true) {
			$message = $this->l10n->t(
				'Field "%1$s" writes into "%2$s", which is read-only, so the save would refuse it.',
				[$mapped->name, $mapped->propertyName]
			);
			return $mapped->finding(code: self::PROPERTY_READ_ONLY, message: $message);
		}

		if ($this->rules->typeFits(field: $mapped->field, property: $mapped->property) === false) {
			return $this->typeMismatchFinding(mapped: $mapped);
		}

		return null;
	}//end blockingFinding()

	/**
	 * The finding for a field that cannot produce the property's type.
	 *
	 * @param MappedField $mapped The field and its property.
	 *
	 * @return array{field: string, property: string, code: string, message: string}
	 */
	private function typeMismatchFinding(MappedField $mapped): array {
		$message = $this->l10n->t(
			'Field "%1$s" is a %2$s field and cannot produce the %3$s that "%4$s" holds.',
			[$mapped->name, $mapped->fieldType(), $mapped->propertyType(), $mapped->propertyName]
		);

		return $mapped->finding(code: self::TYPE_MISMATCH, message: $message);
	}//end typeMismatchFinding()

	/**
	 * Format findings for one field whose type fits.
	 *
	 * @param MappedField $mapped The field and its property.
	 *
	 * @return array<int, array{field?: string, property: string, code: string, message: string}>
	 */
	private function formatFindings(MappedField $mapped): array {
		if ($this->rules->formatFits(field: $mapped->field, property: $mapped->property) === true) {
			return [];
		}

		$message = $this->l10n->t(
			'Field "%1$s" does not produce the %2$s format that "%3$s" demands.',
			[$mapped->name, $mapped->format(), $mapped->propertyName]
		);

		return [$mapped->finding(code: self::FORMAT_MISMATCH, message: $message)];
	}//end formatFindings()

	/**
	 * Enum findings for one field: free text into an enum, or options outside it.
	 *
	 * @param MappedField $mapped The field and its property.
	 *
	 * @return array<int, array{field?: string, property: string, code: string, message: string}>
	 */
	private function enumFindings(MappedField $mapped): array {
		$enum = $this->rules->enumOf(property: $mapped->property);
		if ($enum === null) {
			return [];
		}

		$options = $this->rules->optionsOf(field: $mapped->field);
		if ($options === null) {
			return [$this->unconstrainedFinding(mapped: $mapped, enum: $enum)];
		}

		$outside = array_filter($options, static fn (mixed $option): bool => in_array($option, $enum, true) === false);
		return array_map(fn (mixed $option): array => $this->unknownOptionFinding(mapped: $mapped, option: $option), array_values($outside));
	}//end enumFindings()

	/**
	 * The finding for a free field that writes into an enum.
	 *
	 * @param MappedField       $mapped The field and its property.
	 * @param array<int, mixed> $enum   The values the property accepts.
	 *
	 * @return array{field: string, property: string, code: string, message: string}
	 */
	private function unconstrainedFinding(MappedField $mapped, array $enum): array {
		$message = $this->l10n->t(
			'Field "%1$s" lets anything be typed, but "%2$s" accepts only: %3$s.',
			[$mapped->name, $mapped->propertyName, $this->listOf(values: $enum)]
		);

		return $mapped->finding(code: self::ENUM_UNCONSTRAINED, message: $message);
	}//end unconstrainedFinding()

	/**
	 * The finding for one offered option the property's enum does not accept.
	 *
	 * @param MappedField $mapped The field and its property.
	 * @param mixed       $option The offered value.
	 *
	 * @return array{field: string, property: string, code: string, message: string}
	 */
	private function unknownOptionFinding(MappedField $mapped, mixed $option): array {
		$message = $this->l10n->t(
			'Field "%1$s" offers "%2$s", which "%3$s" does not accept.',
			[$mapped->name, $this->scalarLabel(value: $option), $mapped->propertyName]
		);

		return $mapped->finding(code: self::ENUM_VALUE_UNKNOWN, message: $message);
	}//end unknownOptionFinding()

	/**
	 * Constraint findings for one field: one per bound the field leaves looser.
	 *
	 * @param MappedField $mapped The field and its property.
	 *
	 * @return array<int, array{field?: string, property: string, code: string, message: string}>
	 */
	private function constraintFindings(MappedField $mapped): array {
		$findings = [];
		foreach ($this->rules->looserConstraints(field: $mapped->field, property: $mapped->property) as $constraint) {
			$message = $this->l10n->t(
				'Field "%1$s" allows more than "%2$s" does: set %3$s to %4$s or tighter.',
				[$mapped->name, $mapped->propertyName, $constraint, $this->scalarLabel(value: $mapped->property[$constraint])]
			);
			$findings[] = $mapped->finding(code: self::CONSTRAINT_LOOSER, message: $message);
		}

		return $findings;
	}//end constraintFindings()

	/**
	 * Findings for one fixed value.
	 *
	 * @param FixedValue $fixed  The property and the value fixed into it.
	 * @param Schema     $schema The destination schema.
	 *
	 * @return array<int, array{property: string, code: string, message: string}>
	 */
	private function fixedFindings(FixedValue $fixed, Schema $schema): array {
		$property = $fixed->property;
		$properties = $schema->getProperties();
		if (array_key_exists($property, $properties) === false) {
			return $this->unknownFixedFindings(property: $property, schema: $schema);
		}

		if ($this->rules->fixedValueFits(value: $fixed->value, property: (array)$properties[$property]) === true) {
			return [];
		}

		$message = $this->l10n->t('The fixed value for "%1$s" is one the property does not accept.', [$property]);

		return [(new FormFinding(property: $property, code: self::FIXED_VALUE_INVALID, message: $message))->toArray()];
	}//end fixedFindings()

	/**
	 * Findings for a fixed value into a property the schema does not have: none when it allows extras.
	 *
	 * @param string $property The property the value is fixed into.
	 * @param Schema $schema   The destination schema.
	 *
	 * @return array<int, array{property: string, code: string, message: string}>
	 */
	private function unknownFixedFindings(string $property, Schema $schema): array {
		if ($this->allowsExtras(schema: $schema) === true) {
			return [];
		}

		return [
			(new FormFinding(
				property: $property,
				code: self::PROPERTY_UNKNOWN,
				message: $this->l10n->t('A fixed value writes into "%1$s", which schema "%2$s" does not have.', [$property, (string)$schema->getSlug()])
			))->toArray(),
		];
	}//end unknownFixedFindings()

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
		$findings = [];
		foreach ($this->requiredOf(schema: $schema) as $name) {
			if (in_array($name, $sourced, true) === true || self::isServerFilled(property: (array)($properties[$name] ?? [])) === true) {
				continue;
			}

			$findings[] = (new FormFinding(
				property: $name,
				code: self::REQUIRED_UNMAPPED,
				message: $this->l10n->t('"%1$s" is required, and no field, fixed value or server default fills it.', [$name])
			))->toArray();
		}

		return $findings;
	}//end requiredFindings()

	/**
	 * The required properties of a schema: its `required` list, then each property flagged `required: true`, once each.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int, string> The property names.
	 */
	private function requiredOf(Schema $schema): array {
		$required = $schema->getRequired();
		foreach ($schema->getProperties() as $name => $property) {
			if (is_array($property) === true && ($property['required'] ?? false) === true) {
				$required[] = (string)$name;
			}
		}

		return array_values(array_unique(array_map('strval', $required)));
	}//end requiredOf()

	/**
	 * Whether the server fills a property: a default, a computation, a generated identifier, or the serverSet marker.
	 *
	 * The one definition of "no field needs to": the form's mapping check and
	 * the draft policy's `required` excusals both ask it, so a draft that one
	 * accepts the other accepts too.
	 *
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return bool True when no field needs to.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public static function isServerFilled(array $property): bool {
		if (array_key_exists('default', $property) === true || isset($property['computed']) === true) {
			return true;
		}

		// An `x-openregister-generated` identifier is issued by the creating
		// listener, and on the save that leaves draft (decision 227).
		if (array_key_exists(GeneratedIdentifierDeclaration::ANNOTATION, $property) === true) {
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
