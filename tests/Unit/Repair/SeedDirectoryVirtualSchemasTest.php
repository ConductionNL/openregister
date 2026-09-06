<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Repair\SeedDirectoryVirtualSchemas}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
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
 * @spec openspec/changes/the-organisation-projection-is-writable/specs/organisation-projection/spec.md#requirement-an-organisation-can-be-created-through-the-projection-req-orp-104
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Repair\SeedDirectoryVirtualSchemas;
use OCA\OpenRegister\Service\ObjectSource\NcEntitySemanticMap;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Locks the `readOnly` annotation the save dispatch reads.
 *
 * THE INTERESTING CASE IS THE EXISTING SCHEMA, NOT THE NEW ONE. `ensureSchema()`
 * returns the moment it finds the schema, so a flag set only on the create
 * branch reaches a fresh install and no instance that has already seeded. The
 * provider would implement the write interface, the seed would report success,
 * and every write would still be refused by an annotation nothing pointed at.
 */
class SeedDirectoryVirtualSchemasTest extends TestCase {

	/**
	 * The register mapper double.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private $registerMapper;

	/**
	 * The schema mapper double.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private $schemaMapper;

	/**
	 * Wire fresh doubles, with the `directory` register already present.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		$register = new Register();
		$register->setId(1);
		$register->setSlug(NcEntitySemanticMap::DIRECTORY_REGISTER);
		$register->setSchemas([]);
		$this->registerMapper->method('find')->willReturn($register);
	}//end setUp()

	/**
	 * Build a virtual schema carrying an object-source annotation.
	 *
	 * @param string $slug     The schema slug.
	 * @param bool   $readOnly The annotation's current value.
	 * @param int    $id       The schema id.
	 *
	 * @return Schema The schema.
	 */
	private function virtualSchema(string $slug, bool $readOnly, int $id = 10): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setConfiguration(
			[
				'x-schema-org' => 'schema:Organization',
				'x-openregister-object-source' => [
					'provider' => 'organisation-source',
					'readOnly' => $readOnly,
				],
			]
		);

		return $schema;
	}//end virtualSchema()

	/**
	 * Build the repair step over the current doubles.
	 *
	 * @param LoggerInterface|null $logger An explicit logger, for the error case.
	 *
	 * @return SeedDirectoryVirtualSchemas The step under test.
	 */
	private function step(?LoggerInterface $logger = null): SeedDirectoryVirtualSchemas {
		return new SeedDirectoryVirtualSchemas(
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end step()

	/**
	 * An instance that seeded `nc-organisation` while it was read-only gets the
	 * annotation flipped, rather than skipped for already existing.
	 *
	 * @return void
	 */
	public function testAnAlreadySeededOrganisationSchemaIsFlippedToWritable(): void {
		$organisation = $this->virtualSchema(slug: 'nc-organisation', readOnly: true);

		$this->schemaMapper->method('find')->willReturnCallback(
			function (string $slug) use ($organisation): Schema {
				if ($slug === 'nc-organisation') {
					return $organisation;
				}

				return $this->virtualSchema(slug: $slug, readOnly: true, id: 11);
			}
		);

		$updated = [];
		$this->schemaMapper->method('update')->willReturnCallback(
			function (Schema $schema) use (&$updated): Schema {
				$updated[] = (string)$schema->getSlug();

				return $schema;
			}
		);

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertFalse(
			$organisation->getConfiguration()['x-openregister-object-source']['readOnly'],
			'nc-organisation must end up writable'
		);
		$this->assertContains('nc-organisation', $updated, 'the flip must be persisted, not only set in memory');
	}//end testAnAlreadySeededOrganisationSchemaIsFlippedToWritable()

	/**
	 * The other directory schemas project someone else's system and stay
	 * read-only. Without this, "the flag is applied" and "the flag is applied to
	 * everything" look identical.
	 *
	 * @return void
	 */
	public function testTheOtherDirectorySchemasStayReadOnly(): void {
		$schemas = [];
		$this->schemaMapper->method('find')->willReturnCallback(
			function (string $slug) use (&$schemas): Schema {
				$schemas[$slug] = $this->virtualSchema(slug: $slug, readOnly: true, id: (count($schemas) + 10));

				return $schemas[$slug];
			}
		);
		$this->schemaMapper->method('update')->willReturnArgument(0);

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertArrayHasKey('nc-user', $schemas, 'the seed must have reached nc-user at all');
		foreach (['nc-user', 'nc-group'] as $slug) {
			$this->assertTrue(
				$schemas[$slug]->getConfiguration()['x-openregister-object-source']['readOnly'],
				$slug . ' must stay read-only'
			);
		}
	}//end testTheOtherDirectorySchemasStayReadOnly()

	/**
	 * A schema already carrying the right value is left alone, so the step stays
	 * idempotent and does not write on every upgrade.
	 *
	 * @return void
	 */
	public function testASchemaAlreadyCorrectIsNotWritten(): void {
		$this->schemaMapper->method('find')->willReturnCallback(
			function (string $slug): Schema {
				return $this->virtualSchema(
					slug: $slug,
					readOnly: ($slug !== 'nc-organisation')
				);
			}
		);

		$this->schemaMapper->expects($this->never())->method('update');

		$this->step()->run($this->createMock(IOutput::class));
	}//end testASchemaAlreadyCorrectIsNotWritten()

	/**
	 * A reconcile that cannot be persisted is logged at ERROR with the
	 * consequence, not swallowed into `run()`'s generic warning.
	 *
	 * The schema still exists and still works; it just refuses writes, and the
	 * caller sees a generic "read-only projection" rejection that names the
	 * provider rather than the annotation. That is the wrong bug to go looking
	 * for, so the log has to say which one it is.
	 *
	 * @return void
	 */
	public function testAFailedReconcileIsLoggedAsAnError(): void {
		$this->schemaMapper->method('find')->willReturnCallback(
			function (string $slug): Schema {
				return $this->virtualSchema(slug: $slug, readOnly: true);
			}
		);
		$this->schemaMapper->method('update')->willThrowException(new RuntimeException('access denied'));

		$logger = $this->createMock(LoggerInterface::class);
		$messages = [];
		$logger->method('error')->willReturnCallback(
			function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$this->step($logger)->run($this->createMock(IOutput::class));

		$this->assertNotEmpty($messages, 'a failed reconcile must be logged at error level');
		$this->assertStringContainsString('nc-organisation', $messages[0]);
		$this->assertStringContainsString('refused', $messages[0], 'the log must name the consequence');
	}//end testAFailedReconcileIsLoggedAsAnError()

	/**
	 * Only `nc-organisation` carries the writable flag. A second one appearing is
	 * a decision, and should have to be made deliberately rather than noticed.
	 *
	 * @return void
	 */
	public function testOnlyTheOrganisationRowIsWritable(): void {
		$writable = [];
		foreach (NcEntitySemanticMap::ENTITIES as $key => $row) {
			if (($row['writable'] ?? false) === true) {
				$writable[] = $row['schema'];
			}
		}

		$this->assertSame(['nc-organisation'], $writable);
	}//end testOnlyTheOrganisationRowIsWritable()

}//end class
