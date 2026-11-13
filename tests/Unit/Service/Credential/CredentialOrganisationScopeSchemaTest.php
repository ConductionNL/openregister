<?php

/**
 * CredentialOrganisationScopeSchemaTest — the `scope`/`organisation` properties
 * the organisation-scope feature reads are declared on the credential schema,
 * and remain secret-free (the shape, asserted rather than assumed).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credential-broker-organisation-scope/specs/credential-broker/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use PHPUnit\Framework\TestCase;

/**
 * The credential schema's organisation-scope properties.
 */
class CredentialOrganisationScopeSchemaTest extends TestCase {

	/**
	 * The `brokeredcredential` schema properties, decoded from the register JSON.
	 *
	 * @return array<string, mixed>
	 */
	private function properties(): array {
		$register = json_decode(
			(string) file_get_contents(__DIR__ . '/../../../../lib/Settings/credential_broker_register.json'),
			true
		);

		return $register['components']['schemas']['brokeredcredential']['properties'];
	}//end properties()

	/**
	 * `scope` is a personal|organisation enum defaulting to personal.
	 *
	 * @return void
	 */
	public function testScopeIsAPersonalOrOrganisationEnumDefaultingToPersonal(): void {
		$scope = $this->properties()['scope'] ?? null;

		$this->assertIsArray($scope);
		$this->assertSame('string', $scope['type']);
		$this->assertSame(['personal', 'organisation'], $scope['enum']);
		$this->assertSame('personal', $scope['default']);
	}//end testScopeIsAPersonalOrOrganisationEnumDefaultingToPersonal()

	/**
	 * `organisation` is a string UUID field; it is NOT in the schema's `required`
	 * list (its requirement is conditional on `scope`, enforced in the controller).
	 *
	 * @return void
	 */
	public function testOrganisationIsAnOptionalStringUuidField(): void {
		$organisation = $this->properties()['organisation'] ?? null;

		$this->assertIsArray($organisation);
		$this->assertSame('string', $organisation['type']);

		$register = json_decode(
			(string) file_get_contents(__DIR__ . '/../../../../lib/Settings/credential_broker_register.json'),
			true
		);
		$this->assertNotContains('organisation', ($register['components']['schemas']['brokeredcredential']['required'] ?? []));
	}//end testOrganisationIsAnOptionalStringUuidField()

	/**
	 * Neither scope property is or looks like a secret store: both are plain
	 * string metadata with no secret/injection markers.
	 *
	 * @return void
	 */
	public function testTheScopePropertiesHoldNoSecret(): void {
		$properties = $this->properties();

		foreach (['scope', 'organisation'] as $name) {
			$property = $properties[$name];
			$this->assertSame('string', $property['type'], $name);
			$this->assertArrayNotHasKey('x-openregister-inject-only', $property, $name);
			$this->assertArrayNotHasKey('writeOnly', $property, $name);
		}
	}//end testTheScopePropertiesHoldNoSecret()
}//end class
