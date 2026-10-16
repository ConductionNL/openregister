<?php

/**
 * A schema's name and description fields may be dot-notation paths.
 *
 * The reader (MetadataHydrationHandler::getValueFromPath) walks
 * `description.nl.longDescription` into an object property, but the save-time
 * check compared the whole path with the top-level property keys and refused
 * the schema. opencatalogi's `publiccode` schema was rejected on every import
 * for it. These tests drive the private `validateConfigurationFields()`
 * directly, because the public insert/update paths are DB-bound.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Exception;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Save-time check of objectNameField and objectDescriptionField paths.
 */
class SchemaMapperConfigurationPathTest extends TestCase {

	private SchemaMapper $mapper;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = new SchemaMapper(
			$this->createMock(IDBConnection::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(PropertyValidatorHandler::class),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Run the private check against a schema with these properties and configuration.
	 *
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return void
	 */
	private function validate(array $configuration): void {
		$schema = new Schema();
		$schema->setProperties(
			[
				'name'        => ['type' => 'string'],
				'description' => ['type' => 'object'],
			]
		);
		$schema->setConfiguration($configuration);

		$method = new ReflectionMethod(SchemaMapper::class, 'validateConfigurationFields');
		$method->invoke($this->mapper, $schema);
	}//end validate()

	/**
	 * The publiccode shape: fallbacks that are paths into an object property.
	 *
	 * @return void
	 */
	public function testAFallbackOfPathsIntoAnObjectPropertyIsAccepted(): void {
		$this->validate(['objectNameField' => 'name', 'objectDescriptionField' => 'description.nl.longDescription | description.en.longDescription']);

		$this->addToAssertionCount(1);
	}//end testAFallbackOfPathsIntoAnObjectPropertyIsAccepted()

	/**
	 * A single path into an object property is accepted.
	 *
	 * @return void
	 */
	public function testASinglePathIntoAnObjectPropertyIsAccepted(): void {
		$this->validate(['objectDescriptionField' => 'description.en.longDescription']);

		$this->addToAssertionCount(1);
	}//end testASinglePathIntoAnObjectPropertyIsAccepted()

	/**
	 * Control: a path whose first segment is no property is still refused.
	 *
	 * @return void
	 */
	public function testAPathIntoAPropertyTheSchemaDoesNotHaveIsRefused(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage("None of the fallback fields in objectDescriptionField");

		$this->validate(['objectDescriptionField' => 'summary.nl.longDescription | summary.en.longDescription']);
	}//end testAPathIntoAPropertyTheSchemaDoesNotHaveIsRefused()

	/**
	 * Control: a plain name that is no property is still refused.
	 *
	 * @return void
	 */
	public function testAPlainNameTheSchemaDoesNotHaveIsRefused(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage("('title') does not exist as a property");

		$this->validate(['objectNameField' => 'title']);
	}//end testAPlainNameTheSchemaDoesNotHaveIsRefused()
}//end class
