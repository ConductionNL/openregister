<?php

/**
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Which constraint is a schema's identity, which identity declarations are refused, and an object's key.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator
 */
final class UniqueConstraintIdentityTest extends TestCase {

	/**
	 * The zaken schema's configuration with the given constraints.
	 *
	 * @param array<int,array<string,mixed>> $constraints The declared constraints.
	 *
	 * @return array<string,mixed>
	 */
	private static function config(array $constraints): array {
		return ['uniqueConstraints' => $constraints];
	}//end config()

	public function testARefuseConstraintMarkedIdentityIsTheIdentity(): void {
		$identity = (new UniqueConstraintEvaluator())->identity(
			configuration: self::config(
				[
					['name' => 'email', 'properties' => ['email'], 'action' => 'report'],
					['name' => 'zaaksleutel', 'properties' => ['gemeentecode', 'zaaknummer'], 'action' => 'refuse', 'identity' => true],
				]
			)
		);

		$this->assertSame(
			['name' => 'zaaksleutel', 'properties' => ['gemeentecode', 'zaaknummer'], 'action' => 'refuse'],
			$identity
		);
	}//end testARefuseConstraintMarkedIdentityIsTheIdentity()

	public function testNoConstraintMarkedIdentityMeansNoIdentity(): void {
		$evaluator = new UniqueConstraintEvaluator();

		$this->assertNull($evaluator->identity(configuration: self::config([['name' => 'k', 'properties' => ['a'], 'action' => 'refuse']])));
		$this->assertNull($evaluator->identity(configuration: null));
		$this->assertNull($evaluator->identity(configuration: ['unique' => ['a']]));
	}//end testNoConstraintMarkedIdentityMeansNoIdentity()

	public function testOnlyALiteralTrueDeclaresAnIdentity(): void {
		$identity = (new UniqueConstraintEvaluator())->identity(
			configuration: self::config([['name' => 'k', 'properties' => ['a'], 'action' => 'refuse', 'identity' => 'true']])
		);

		$this->assertNull($identity);
	}//end testOnlyALiteralTrueDeclaresAnIdentity()

	public function testAReportConstraintIsNeverTheIdentity(): void {
		$evaluator = new UniqueConstraintEvaluator();
		$config = self::config([['name' => 'mail', 'properties' => ['email'], 'action' => 'report', 'identity' => true]]);

		$this->assertNull($evaluator->identity(configuration: $config));
		$refusals = $evaluator->identityRefusals(configuration: $config);
		$this->assertCount(1, $refusals);
		$this->assertSame('identity-on-report', $refusals[0]['code']);
		$this->assertStringContainsString('"mail"', $refusals[0]['message']);
	}//end testAReportConstraintIsNeverTheIdentity()

	public function testASecondIdentityIsRefusedByName(): void {
		$refusals = (new UniqueConstraintEvaluator())->identityRefusals(
			configuration: self::config(
				[
					['name' => 'first', 'properties' => ['a'], 'action' => 'refuse', 'identity' => true],
					['name' => 'second', 'properties' => ['b'], 'action' => 'refuse', 'identity' => true],
				]
			)
		);

		$this->assertCount(1, $refusals);
		$this->assertSame('identity-declared-twice', $refusals[0]['code']);
		$this->assertStringContainsString('"second"', $refusals[0]['message']);
		$this->assertStringContainsString('"first"', $refusals[0]['message']);
	}//end testASecondIdentityIsRefusedByName()

	public function testOneIdentityOnARefuseConstraintIsAccepted(): void {
		$refusals = (new UniqueConstraintEvaluator())->identityRefusals(
			configuration: self::config(
				[
					['name' => 'zaaksleutel', 'properties' => ['gemeentecode', 'zaaknummer'], 'action' => 'refuse', 'identity' => true],
					['name' => 'mail', 'properties' => ['email'], 'action' => 'report'],
				]
			)
		);

		$this->assertSame([], $refusals);
	}//end testOneIdentityOnARefuseConstraintIsAccepted()

	public function testTheKeyJoinsTheValuesInDeclaredOrder(): void {
		$evaluator = new UniqueConstraintEvaluator();
		$identity = ['name' => 'zaaksleutel', 'properties' => ['gemeentecode', 'zaaknummer'], 'action' => 'refuse'];

		$this->assertSame('0363:Z-2026-0042', $evaluator->keyOf(identity: $identity, object: ['zaaknummer' => 'Z-2026-0042', 'gemeentecode' => '0363']));
		$this->assertNull($evaluator->keyOf(identity: $identity, object: ['gemeentecode' => '0363']));
		$this->assertNull($evaluator->keyOf(identity: $identity, object: ['gemeentecode' => '0363', 'zaaknummer' => ['x']]));
	}//end testTheKeyJoinsTheValuesInDeclaredOrder()
}//end class
