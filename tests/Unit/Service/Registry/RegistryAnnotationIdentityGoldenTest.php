<?php

/**
 * Characterisation test pinning the exact findings for a registry annotation's shape and identity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Registry;

use OCA\OpenRegister\Service\Registry\RegistryAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * The malformed, missing-identity and undeclared-identity findings, word for word.
 */
class RegistryAnnotationIdentityGoldenTest extends TestCase {

	/**
	 * A non-object annotation is one malformed finding.
	 *
	 * @return void
	 */
	public function testANonObjectAnnotationIsMalformed(): void {
		$this->assertSame(
			[['code' => 'registry-malformed', 'message' => 'x-openregister-registry must be an object.']],
			(new RegistryAnnotationValidator())->validate(schema: ['x-openregister-registry' => 'yes'])
		);
	}//end testANonObjectAnnotationIsMalformed()

	/**
	 * A missing identity and an undeclared one each give their own finding; a declared one gives none.
	 *
	 * @return void
	 */
	public function testTheIdentityFindingsAreExact(): void {
		$validator = new RegistryAnnotationValidator();
		$annotation = ['registry' => 'r', 'owned' => ['name']];
		$properties = ['name' => ['type' => 'string'], 'code' => ['type' => 'string']];

		$missing = $validator->validate(schema: ['x-openregister-registry' => $annotation, 'properties' => $properties]);
		$undeclared = $validator->validate(schema: ['x-openregister-registry' => $annotation + ['identity' => 'nope'], 'properties' => $properties]);
		$declared = $validator->validate(schema: ['x-openregister-registry' => $annotation + ['identity' => 'code'], 'properties' => $properties]);
		$notAMap = $validator->validate(schema: ['x-openregister-registry' => $annotation + ['identity' => 'code'], 'properties' => 'x']);

		$this->assertSame([['code' => 'registry-identity-missing', 'message' => 'x-openregister-registry.identity must be a non-empty string.']], array_values(array_filter($missing, static fn (array $f): bool => str_starts_with($f['code'], 'registry-identity'))));
		$this->assertSame([['code' => 'registry-identity-undeclared', 'message' => 'x-openregister-registry.identity "nope" is not declared in this schema\'s properties.']], array_values(array_filter($undeclared, static fn (array $f): bool => str_starts_with($f['code'], 'registry-identity'))));
		$this->assertSame([], array_values(array_filter($declared, static fn (array $f): bool => str_starts_with($f['code'], 'registry-identity'))));
		$this->assertSame(['registry-identity-undeclared'], array_values(array_filter(array_column($notAMap, 'code'), static fn (string $c): bool => str_starts_with($c, 'registry-identity'))));
	}//end testTheIdentityFindingsAreExact()
}//end class
