<?php

/**
 * Characterisation tests for how OasRequestValidator flattens an error tree.
 *
 * Pins the path and message read off each node (and their fallbacks), the
 * depth-first order of sub-errors and the formatted message of a real opis
 * error.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Oas;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OasRequestValidatorErrorTreeTest extends TestCase {

	/**
	 * @param mixed $error The error tree.
	 *
	 * @return array<int, array{path: string, message: string}> The flattened errors.
	 */
	private function flatten(mixed $error): array {
		$errors = [];
		$collect = new ReflectionMethod(OasRequestValidator::class, 'collectErrors');
		$collect->invokeArgs(new OasRequestValidator(), [$error, &$errors]);

		return $errors;
	}

	private function node(?array $fullPath, ?string $keyword, array $subErrors = []): object {
		return new class($fullPath, $keyword, $subErrors) {
			public function __construct(private ?array $fullPath, private ?string $keyword, private array $subErrors) {
			}

			public function data(): object {
				if ($this->fullPath === null) {
					return new \stdClass();
				}

				return new class($this->fullPath) {
					public function __construct(private array $fullPath) {
					}

					public function fullPath(): array {
						return $this->fullPath;
					}
				};
			}

			public function keyword(): string {
				return (string)$this->keyword;
			}

			public function subErrors(): array {
				return $this->subErrors;
			}
		};
	}

	public function testANonObjectIsNoError(): void {
		$this->assertSame([], $this->flatten('error'));
		$this->assertSame([], $this->flatten(null));
	}

	public function testAnObjectWithNothingToReadFallsBackToTheRootAndAGenericMessage(): void {
		$this->assertSame([['path' => '/', 'message' => 'value does not validate']], $this->flatten(new \stdClass()));
	}

	public function testThePathAndKeywordAreReadAndSubErrorsFollowDepthFirst(): void {
		$tree = $this->node(
			['a'],
			'properties',
			[
				$this->node(['a', 'b'], 'type', [$this->node(['a', 'b', 0], '')]),
				$this->node(null, 'required'),
				'not an error',
				$this->node([], 'enum'),
			]
		);

		$this->assertSame(
			[
				['path' => '/a', 'message' => 'properties'],
				['path' => '/a/b', 'message' => 'type'],
				['path' => '/a/b/0', 'message' => 'value does not validate'],
				['path' => '/', 'message' => 'required'],
				['path' => '/', 'message' => 'enum'],
			],
			$this->flatten($tree)
		);
	}

	public function testARealErrorCarriesItsFormattedMessage(): void {
		$errors = (new OasRequestValidator())->validate(
			['name' => 5],
			['type' => 'object', 'required' => ['name', 'age'], 'properties' => ['name' => ['type' => 'string']]]
		);

		$this->assertSame(
			[['path' => '/', 'message' => 'The required properties (age) are missing']],
			$errors
		);
	}
}//end class
