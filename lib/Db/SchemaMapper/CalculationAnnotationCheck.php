<?php

/**
 * CalculationAnnotationCheck: save-time check of declared calculations.
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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Calculation\CalculationDeclarationException;
use OCA\OpenRegister\Service\Schemas\SchemaDeclarationValidators;
use Psr\Log\LoggerInterface;

/**
 * Validates calculations from the annotation and from the properties together.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class CalculationAnnotationCheck {

	private const ANNOTATION = 'x-openregister-calculations';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Receives the advisory faults.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Validate declared calculations, from the annotation and from the properties.
	 *
	 * Two declarations reach one engine. `x-openregister-calculations` is
	 * written by hand in a register file; a `calculation` key on a property is
	 * forwarded by an administration surface. They are merged and validated
	 * together, so a cycle between one of each is still a cycle.
	 *
	 * They differ in what a refusal costs. The annotation stays ADVISORY: a
	 * register file shipped by an app must not be able to break the whole
	 * import, so a malformed block is logged and the schema is stored. A
	 * forwarded declaration is BLOCKING: a person just wrote it in a form and
	 * pressed save, and storing an expression that will silently never
	 * evaluate is the failure ADR-005 names. The save refuses and the response
	 * carries the code and the node that refused it.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @throws CalculationDeclarationException When a forwarded property declaration is invalid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function check(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: self::ANNOTATION);
		$forwarding = SchemaDeclarationValidators::propertyCalculations();
		$forwarded = $forwarding->fromProperties(properties: $schema->getProperties());
		if ($annotation->block() === [] && $forwarded === []) {
			return;
		}

		$errors = $this->errors(annotation: $annotation, properties: $schema->getProperties());
		$blocking = $forwarding->blockingErrors(errors: $errors, forwarded: array_map('strval', array_keys($forwarded)));
		if ($blocking !== []) {
			throw new CalculationDeclarationException(errors: $blocking);
		}

		$this->warn(annotation: $annotation, errors: $errors);
	}//end check()

	/**
	 * Every fault of the merged declarations.
	 *
	 * A name declared in both places has two expressions and no rule says which
	 * wins, so that is a fault of its own rather than one being picked.
	 *
	 * @param SchemaAnnotation     $annotation The annotation.
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function errors(SchemaAnnotation $annotation, array $properties): array {
		$forwarding = SchemaDeclarationValidators::propertyCalculations();
		$merged = $forwarding->merge(annotation: $annotation->block(), properties: $properties);
		$duplicates = array_map(
			static fn (string $duplicate): array => [
				'code' => 'calculation-duplicate-declaration',
				'message' => sprintf(
					'Calculation "%s" is declared both on the property and in x-openregister-calculations.',
					$duplicate
				),
			],
			array_values($forwarding->duplicates(annotation: $annotation->block(), properties: $properties))
		);

		return array_merge($duplicates, SchemaDeclarationValidators::calculation()->validate($this->shape(annotation: $annotation, merged: $merged)));
	}//end errors()

	/**
	 * The merged declarations as the validator reads them.
	 *
	 * @param SchemaAnnotation     $annotation The annotation.
	 * @param array<string, mixed> $merged     The merged calculations.
	 *
	 * @return array<string, mixed>
	 */
	private function shape(SchemaAnnotation $annotation, array $merged): array {
		return array_merge(
			$annotation->shape(),
			[
				self::ANNOTATION => $merged,
				'x-openregister-references' => ($annotation->configured(key: 'x-openregister-references') ?? []),
				'x-openregister-aggregate-refs' => ($annotation->configured(key: 'x-openregister-aggregate-refs') ?? []),
			]
		);
	}//end shape()

	/**
	 * Log the advisory faults, if there are any.
	 *
	 * @param SchemaAnnotation                   $annotation The annotation.
	 * @param array<int, array{message: string}> $errors     The faults.
	 *
	 * @return void
	 */
	private function warn(SchemaAnnotation $annotation, array $errors): void {
		if ($errors !== []) {
			$this->logger->warning(
				$annotation->label() . ' is invalid and was ignored (calculation not evaluated): '
				. implode(' ', array_map(static fn (array $err) => $err['message'], $errors))
			);
		}
	}//end warn()
}//end class
