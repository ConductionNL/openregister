<?php

/**
 * ImportHandler::importSchema() refuses a malformed schema shape.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * With a shape validator wired, a schema without a `properties` map is
 * refused before it is touched, and a well-shaped one passes on to the next
 * check.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ImportHandler
 * @uses   \OCA\OpenRegister\Service\Oas\OasRequestValidator
 */
class ImportHandlerSchemaShapeTest extends TestCase {

	/**
	 * Build the handler with a real shape validator.
	 *
	 * @return ImportHandler
	 */
	private function handler(): ImportHandler {
		return new ImportHandler(
			schemaMapper: $this->createMock(SchemaMapper::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper: $this->createMock(MappingMapper::class),
			client: $this->createMock(Client::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			appDataPath: '/tmp',
			uploadHandler: $this->createMock(UploadHandler::class),
			objectService: $this->createMock(ObjectService::class),
			schemaShapeValidator: new OasRequestValidator()
		);
	}//end handler()

	/**
	 * A `properties` value that is not a map is refused with the failing path.
	 *
	 * @return void
	 */
	public function testAPropertiesListIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('imported schema fails OAS-shape validation: ');

		$this->handler()->importSchema(
			data: ['slug' => 'demo', 'title' => 'Demo', 'properties' => 'not a map'],
			slugsAndIdsMap: []
		);
	}//end testAPropertiesListIsRefused()

	/**
	 * A schema without `properties` is refused.
	 *
	 * @return void
	 */
	public function testAMissingPropertiesMapIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('imported schema fails OAS-shape validation');

		$this->handler()->importSchema(data: ['slug' => 'demo', 'title' => 'Demo'], slugsAndIdsMap: []);
	}//end testAMissingPropertiesMapIsRefused()

	/**
	 * A well-shaped schema passes the shape check and meets the slug check next.
	 *
	 * @return void
	 */
	public function testAWellShapedSchemaReachesTheSlugCheck(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("missing a 'slug'");

		$this->handler()->importSchema(
			data: ['title' => 'Demo', 'properties' => ['name' => ['type' => 'string']]],
			slugsAndIdsMap: []
		);
	}//end testAWellShapedSchemaReachesTheSlugCheck()
}//end class
