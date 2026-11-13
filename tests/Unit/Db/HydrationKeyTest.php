<?php

/**
 * Unit tests for HydrationKey and the entity hydrators that use it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\Endpoint;
use OCA\OpenRegister\Db\EndpointLog;
use OCA\OpenRegister\Db\HydrationKey;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Source;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A key reaches a setter only in its exact spelling.
 */
class HydrationKeyTest extends TestCase {

	/**
	 * Entity, field and getter for every hydrator that resolves setters by name.
	 *
	 * @return array<string, array{0: callable(): Entity, 1: string, 2: string}>
	 */
	public static function guardedFields(): array {
		return [
			'Application organisation' => [static fn (): Entity => new Application(), 'organisation', 'getOrganisation'],
			'Application owner'        => [static fn (): Entity => new Application(), 'owner', 'getOwner'],
			'Configuration owner'      => [static fn (): Entity => new Configuration(), 'owner', 'getOwner'],
			'Mapping organisation'     => [static fn (): Entity => new Mapping(), 'organisation', 'getOrganisation'],
			'Endpoint organisation'    => [static fn (): Entity => new Endpoint(), 'organisation', 'getOrganisation'],
			'Source organisation'      => [static fn (): Entity => new Source(), 'organisation', 'getOrganisation'],
			'Object organisation'      => [static fn (): Entity => new ObjectEntity(), 'organisation', 'getOrganisation'],
			'Object owner'             => [static fn (): Entity => new ObjectEntity(), 'owner', 'getOwner'],
			'Consumer userId'          => [static fn (): Entity => new Consumer(), 'userId', 'getUserId'],
			'EndpointLog userId'       => [static fn (): Entity => new EndpointLog(), 'userId', 'getUserId'],
		];
	}//end guardedFields()

	#[DataProvider('guardedFields')]
	public function testAnotherSpellingSetsNothing(callable $make, string $field, string $getter): void {
		foreach ([ucfirst($field), strtoupper($field)] as $spelling) {
			$entity = $make();
			$before = $entity->$getter();

			$entity->hydrate([$spelling => 'sent-value']);

			self::assertSame($before, $entity->$getter(), $spelling);
		}
	}//end testAnotherSpellingSetsNothing()

	#[DataProvider('guardedFields')]
	public function testTheExactSpellingIsSet(callable $make, string $field, string $getter): void {
		$entity = $make();

		$entity->hydrate([$field => 'sent-value']);

		self::assertSame('sent-value', $entity->$getter());
	}//end testTheExactSpellingIsSet()

	public function testAnExplicitSetterWithoutAFieldIsAdmittedOnlyInItsOwnSpelling(): void {
		$application = new Application();

		self::assertTrue(HydrationKey::admits(entity: $application, key: 'quota'));
		self::assertFalse(HydrationKey::admits(entity: $application, key: 'Quota'));
		self::assertFalse(HydrationKey::admits(entity: $application, key: 'qUOTA'));
	}//end testAnExplicitSetterWithoutAFieldIsAdmittedOnlyInItsOwnSpelling()

	public function testEmptyAndNumericKeysAreRefused(): void {
		$application = new Application();

		self::assertFalse(HydrationKey::admits(entity: $application, key: ''));
		self::assertFalse(HydrationKey::admits(entity: $application, key: 0));
		self::assertFalse(HydrationKey::admits(entity: $application, key: 'unknownField'));
	}//end testEmptyAndNumericKeysAreRefused()

	public function testOnlyLowerCaseFirstKeysAreFieldNames(): void {
		self::assertTrue(HydrationKey::isFieldName(key: 'organisation'));
		self::assertTrue(HydrationKey::isFieldName(key: 'quota'));
		self::assertFalse(HydrationKey::isFieldName(key: 'Organisation'));
		self::assertFalse(HydrationKey::isFieldName(key: ''));
		self::assertFalse(HydrationKey::isFieldName(key: 0));
	}//end testOnlyLowerCaseFirstKeysAreFieldNames()

	/**
	 * Entities with an owner and an organisation, one per hydrate path that carries them.
	 *
	 * @return array<string, array{0: object}>
	 */
	public static function entities(): array {
		return [
			'empty-json trait (application)' => [new Application()],
			'empty-json hydration (object)'  => [new ObjectEntity()],
		];
	}//end entities()

	#[DataProvider('entities')]
	public function testACapitalisedOwnerOrOrganisationIsNotHydrated(object $entity): void {
		$entity->hydrate(['Owner' => 'mallory', 'Organisation' => 'other-org']);

		self::assertNull($entity->getOwner());
		self::assertNull($entity->getOrganisation());
	}//end testACapitalisedOwnerOrOrganisationIsNotHydrated()

	public function testTheLowerCaseSpellingStillHydrates(): void {
		$application = new Application();
		$application->hydrate(['organisation' => 'own-org']);

		self::assertSame('own-org', $application->getOrganisation());
	}//end testTheLowerCaseSpellingStillHydrates()
}//end class
