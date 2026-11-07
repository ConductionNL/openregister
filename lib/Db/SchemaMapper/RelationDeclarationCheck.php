<?php

/**
 * RelationDeclarationCheck: save-time check of the relation declarations on a schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use Closure;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Relation\LinkExposure;
use OCA\OpenRegister\Service\Relation\RelationAnnotationValidator;
use OCA\OpenRegister\Service\Relation\RelationDeclarationException;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;

/**
 * Refuses relation declarations that cannot be honoured, and exposures of properties the far schema lacks.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class RelationDeclarationCheck {

	/**
	 * Constructor.
	 *
	 * @param Closure $findSchema Finds a schema by identifier, across organisations; throws when there is none.
	 */
	public function __construct(
		private readonly Closure $findSchema,
	) {
	}//end __construct()

	/**
	 * Validate the relation declarations on a schema's properties.
	 *
	 * Blocking, and deliberately so. Both keys are new, so no register carries
	 * one and refusing breaks no existing import, which is the test the
	 * advisory policy sets. And both failures they catch are silent ones: a
	 * symmetric relation that also names an inverse reads one way on one side
	 * and the other way on the other, and a `type` naming a vocabulary entry
	 * that does not exist renders as the generic "referenced by" fallback
	 * forever while its author believes the link is typed.
	 *
	 * Validated at the mapper because this is the one choke point the create,
	 * update and file-upload paths all pass through.
	 *
	 * What a link EXPOSES is refused too, and only once the shape is sound: a
	 * type naming a vocabulary key nobody declared has already failed, and
	 * asking what that type exposes would be asking about nothing.
	 *
	 * @param Schema $schema Schema being saved.
	 *
	 * @throws RelationDeclarationException When a relation declaration cannot be honoured.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function check(Schema $schema): void {
		$errors = (new RelationAnnotationValidator())->validate($this->shape(schema: $schema));
		if ($errors === []) {
			$errors = $this->exposureRefusals(schema: $schema);
		}

		if ($errors !== []) {
			throw new RelationDeclarationException(errors: $errors);
		}
	}//end check()

	/**
	 * Refuse an `exposes` list naming a property the linked schema does not declare.
	 *
	 * Refused at SAVE, because the alternative is silent. A name that matches
	 * nothing is simply absent from every projection afterwards: the author
	 * reads a 200 and ships a link that hands over one field fewer than they
	 * wrote, and nothing anywhere says so.
	 *
	 * 🔑 A FAR SCHEMA THAT CANNOT BE RESOLVED IS NOT A REFUSAL, deliberately.
	 * Schemas arrive in whatever order a configuration import walks them, so
	 * the schema a `$ref` names may genuinely not exist yet when this one is
	 * saved. Refusing there would make a valid import fail on ordering alone.
	 * The check is therefore what it can honestly be: a refusal when the far
	 * schema IS resolvable and does not declare the property. The
	 * `relation-without-ref` refusal already covers a link with no `$ref` at all.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function exposureRefusals(Schema $schema): array {
		$exposure = new LinkExposure();
		$reasons = [];
		foreach ((new RelationTypeResolver())->descriptors(schema: $schema) as $property => $descriptor) {
			$far = $this->exposedSchema(descriptor: $descriptor, property: ($schema->getProperties()[$property] ?? null));
			if ($far !== null) {
				$reasons[] = $exposure->refusalFor(
					relationType: $descriptor,
					farProperties: array_map('strval', array_keys($far->getProperties())),
					typeName: (string)(($descriptor['type'] ?? null) ?? $property)
				);
			}
		}

		return $this->refusals(reasons: $reasons);
	}//end exposureRefusals()

	/**
	 * The refusals for the links that have a reason.
	 *
	 * @param array<int, string|null> $reasons One reason or null per exposing link.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function refusals(array $reasons): array {
		return array_map(
			static fn (string $reason): array => ['code' => 'relation-exposes-unknown-property', 'message' => $reason],
			array_values(array_filter($reasons, static fn (?string $reason): bool => $reason !== null))
		);
	}//end refusals()

	/**
	 * The properties and the vocabulary, as the validator reads them.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return array<string, mixed>
	 */
	private function shape(Schema $schema): array {
		$key = RelationAnnotationValidator::VOCABULARY_ANNOTATION;
		$annotation = new SchemaAnnotation(schema: $schema, key: $key);
		$shape = ['properties' => $schema->getProperties()];
		if ($annotation->hasValue() === true) {
			$shape[$key] = $annotation->value();
		}

		return $shape;
	}//end shape()

	/**
	 * The far schema of a link that declares what it exposes, or null.
	 *
	 * @param array<string, mixed> $descriptor The relation descriptor.
	 * @param mixed                $property   The property definition.
	 *
	 * @return Schema|null
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	private function exposedSchema(array $descriptor, mixed $property): ?Schema {
		if ((new LinkExposure())->declaresExposure(relationType: $descriptor) === false) {
			return null;
		}

		$identifier = $this->identifierOf(reference: $this->referenceOf(property: $property));
		if ($identifier === '') {
			return null;
		}

		try {
			return ($this->findSchema)($identifier);
		} catch (\Throwable $e) {
			// Unresolvable is not a refusal; see the note on exposureRefusals().
			return null;
		}
	}//end exposedSchema()

	/**
	 * The `$ref` of a property, or of its items.
	 *
	 * @param mixed $property The property definition.
	 *
	 * @return mixed
	 */
	private function referenceOf(mixed $property): mixed {
		$definition = $this->asArray(value: $property);
		$reference = ($definition['$ref'] ?? null);
		if (is_string($reference) === true) {
			return $reference;
		}

		return ($this->asArray(value: ($definition['items'] ?? null))['$ref'] ?? null);
	}//end referenceOf()

	/**
	 * The identifier a reference ends in, or an empty string.
	 *
	 * `#/components/schemas/Besluit`, a URL, a uuid, an id or a bare slug. The
	 * lookup already resolves the last three; the first two reduce to a slug.
	 *
	 * @param mixed $reference The reference.
	 *
	 * @return string
	 */
	private function identifierOf(mixed $reference): string {
		if (is_string($reference) === false) {
			return '';
		}

		$identifier = trim($reference);
		if (str_contains($identifier, '/') === true) {
			return substr($identifier, (strrpos($identifier, '/') + 1));
		}

		return $identifier;
	}//end identifierOf()

	/**
	 * An array or object as an array, anything else as an empty one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<mixed>
	 */
	private function asArray(mixed $value): array {
		if (is_object($value) === true) {
			return (array)$value;
		}

		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end asArray()
}//end class
