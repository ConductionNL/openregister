<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Configuration\ForeignSeedSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * When a seed's schema counts as foreign.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ForeignSeedSchema
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class ForeignSeedSchemaTest extends TestCase {

	/**
	 * A register with its schema list and app.
	 *
	 * @param array<int, mixed> $schemas     The listed schemas.
	 * @param string|null       $application The owning app.
	 *
	 * @return Register
	 */
	private function register(array $schemas, ?string $application): Register {
		$register = new Register();
		$register->setSlug('publication');
		$register->setSchemas($schemas);
		$register->setApplication($application);

		return $register;
	}//end register()

	/**
	 * Schema 7, `organization`, owned by the given app.
	 *
	 * @param string|null $application The owning app.
	 *
	 * @return Schema
	 */
	private function schema(?string $application): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('organization');
		$schema->setApplication($application);

		return $schema;
	}//end schema()

	/**
	 * Situations that keep a seed working.
	 *
	 * @return array<string, array{0: array<int, mixed>, 1: string|null, 2: string|null, 3: string|null}>
	 */
	public static function admitted(): array {
		return [
			'no schema list yet'   => [[], 'other', 'stackiq', 'opencatalogi'],
			'listed by id'         => [[7], 'other', 'stackiq', 'opencatalogi'],
			'listed by id as text' => [['7'], 'other', 'stackiq', 'opencatalogi'],
			'listed by slug'       => [['organization'], 'other', 'stackiq', 'opencatalogi'],
			'no owner'             => [[1], 'other', null, 'opencatalogi'],
			'an empty owner'       => [[1], 'other', '', 'opencatalogi'],
			'the importing app'    => [[1], 'other', 'opencatalogi', 'opencatalogi'],
			'the register\'s app'  => [[1], 'stackiq', 'stackiq', 'opencatalogi'],
		];
	}//end admitted()

	/**
	 * A seed that meets any one exception is written, without a warning.
	 *
	 * @param array<int, mixed> $listed      The register's schemas.
	 * @param string|null       $registerApp The register's app.
	 * @param string|null       $owner       The schema's app.
	 * @param string|null       $appId       The importing app.
	 *
	 * @return void
	 */
	#[DataProvider('admitted')]
	public function testASeedThatMeetsAnExceptionIsNotForeign(array $listed, ?string $registerApp, ?string $owner, ?string $appId): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$check = new ForeignSeedSchema(logger: $logger, appId: $appId);

		$this->assertFalse($check->isForeign(register: $this->register(schemas: $listed, application: $registerApp), schema: $this->schema(application: $owner)));
	}//end testASeedThatMeetsAnExceptionIsNotForeign()

	/**
	 * A schema that is not listed and that neither app owns is foreign, and the skip is logged.
	 *
	 * @return void
	 */
	public function testAnotherAppsUnlistedSchemaIsForeign(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[ImportHandler] Skipping seed object: its schema belongs to another app and is not in its register',
			$this->callback(static fn (array $context): bool => $context['appId'] === 'opencatalogi'
				&& $context['registerSlug'] === 'publication'
				&& $context['schemaSlug'] === 'organization'
				&& $context['schemaId'] === 7
				&& $context['schemaApplication'] === 'stackiq')
		);

		$check = new ForeignSeedSchema(logger: $logger, appId: 'opencatalogi');

		$this->assertTrue($check->isForeign(register: $this->register(schemas: [1, 'other'], application: 'other'), schema: $this->schema(application: 'stackiq')));
	}//end testAnotherAppsUnlistedSchemaIsForeign()

	/**
	 * An import without an app still finds a foreign schema.
	 *
	 * @return void
	 */
	public function testAnImportWithoutAnAppStillFindsAForeignSchema(): void {
		$check = new ForeignSeedSchema(logger: $this->createMock(LoggerInterface::class), appId: null);

		$this->assertTrue($check->isForeign(register: $this->register(schemas: [1], application: null), schema: $this->schema(application: 'stackiq')));
	}//end testAnImportWithoutAnAppStillFindsAForeignSchema()
}//end class
