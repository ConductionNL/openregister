<?php

/**
 * Golden characterization test for PreviewHandler::previewConfigurationChanges().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\FetchHandler;
use OCA\OpenRegister\Service\Configuration\PreviewHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runs previewConfigurationChanges() over a fixed corpus of remote documents
 * and compares the preview (with its timestamp blanked) and every mapper call
 * with `fixtures/preview-changes-golden.json`. Set `OR_GOLDEN_WRITE=1` to
 * regenerate the fixture.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Configuration\PreviewHandler
 */
class PreviewHandlerGoldenTest extends TestCase {

	/**
	 * Calls recorded during one case.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	private static function goldenPath(): string {
		return __DIR__.'/fixtures/preview-changes-golden.json';
	}//end goldenPath()

	/**
	 * The remote documents, by case name.
	 *
	 * @return array<string, mixed>
	 */
	private static function corpus(): array {
		$registers = [
			'zaken' => ['title' => 'Zaken', 'version' => '2.0.0'],
			'nieuw' => ['title' => 'Nieuw', 'version' => '1.0.0'],
		];
		$schemas = [
			'zaaktype' => ['title' => 'Zaaktype', 'version' => '2.0.0', 'properties' => ['title' => ['type' => 'string']]],
			'besluit' => ['title' => 'Besluit', 'version' => '1.0.0'],
		];
		$objects = [
			['title' => 'Bestaand', '@self' => ['register' => 'zaken', 'schema' => 'zaaktype', 'slug' => 'bestaand', 'version' => '1.1.0']],
			['title' => 'Nieuw', '@self' => ['register' => 'ZAKEN', 'schema' => 'zaaktype', 'slug' => 'nieuw', 'version' => '1.0.0']],
			['title' => 'Gepland', '@self' => ['register' => 'nieuw', 'schema' => 'besluit', 'slug' => 'gepland']],
			['title' => 'Onbekend', '@self' => ['register' => 'elders', 'schema' => 'niets', 'slug' => 'weg']],
		];

		return [
			'fetch-failed' => 'response',
			'empty' => [],
			'everything' => ['version' => '3.0.0', 'components' => ['registers' => $registers, 'schemas' => $schemas, 'objects' => $objects]],
			'info-version' => ['info' => ['version' => '4.0.0'], 'components' => ['registers' => $registers]],
			'objects-only' => ['components' => ['objects' => $objects]],
			'objects-empty' => ['components' => ['objects' => []]],
			'objects-as-objects' => ['components' => ['objects' => [(object)['title' => 'Obj', '@self' => ['register' => 'zaken', 'schema' => 'zaaktype', 'slug' => 'obj']]]]],
			'not-arrays' => ['components' => ['registers' => 'x', 'schemas' => 5, 'objects' => null]],
			'components-scalar' => ['components' => 'x'],
		];
	}//end corpus()

	/**
	 * Case names for the data provider.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (array_keys(self::corpus()) as $name) {
			$cases[$name] = [$name];
		}

		return $cases;
	}//end cases()

	/**
	 * Each document previews as recorded.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 *
	 * @covers ::previewConfigurationChanges
	 *
	 * @dataProvider cases
	 */
	public function testPreviewMatchesGolden(string $name): void {
		$actual = $this->runCase(remote: self::corpus()[$name]);

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$golden = [];
			if (is_file(self::goldenPath()) === true) {
				$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::goldenPath(), json_encode($golden, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end testPreviewMatchesGolden()

	/**
	 * Run one case and return what it did.
	 *
	 * @param mixed $remote The remote document, or 'response' for a failed fetch.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(mixed $remote): array {
		$this->calls = [];

		$register = new Register();
		$register->setId(4);
		$register->setSlug('Zaken');
		$register->setVersion('1.0.0');
		$register->setTitle('Zaken');

		$schema = new Schema();
		$schema->setId(9);
		$schema->setSlug('zaaktype');
		$schema->setVersion('1.0.0');
		$schema->setTitle('Zaaktype');
		$schema->setProperties(['title' => ['type' => 'string']]);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturnCallback(
			function () use ($register): array {
				$this->calls[] = ['register.findAll'];
				return [$register];
			}
		);
		$registerMapper->method('find')->willReturnCallback(
			function (int|string $slug) use ($register): Register {
				$this->calls[] = ['register.find', $slug];
				if ($slug !== 'zaken') {
					throw new DoesNotExistException('none');
				}

				return $register;
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturnCallback(
			function () use ($schema): array {
				$this->calls[] = ['schema.findAll'];
				return [$schema];
			}
		);
		$schemaMapper->method('find')->willReturnCallback(
			function (int|string $slug) use ($schema): Schema {
				$this->calls[] = ['schema.find', $slug];
				if ($slug !== 'zaaktype') {
					throw new DoesNotExistException('none');
				}

				return $schema;
			}
		);

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('find')->willReturnCallback(
			function (...$args): ObjectEntity {
				$this->calls[] = ['object.find', $args];
				if ($args[0] !== 'bestaand') {
					throw new DoesNotExistException('none');
				}

				$object = new ObjectEntity();
				$object->setUuid('11111111-2222-3333-4444-555555555555');
				$object->setSlug('bestaand');
				$object->setVersion('1.0.0');
				$object->setRegister('4');
				$object->setSchema('9');
				$object->setObject(['title' => 'Oud']);
				return $object;
			}
		);

		$fetchHandler = $this->createMock(FetchHandler::class);
		$fetchHandler->method('fetchRemoteConfiguration')->willReturn(
			$remote === 'response' ? new JSONResponse(['error' => 'unreachable'], 502) : $remote
		);

		$handler = new PreviewHandler(
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			logger: $this->createMock(LoggerInterface::class),
			fetchHandler: $fetchHandler,
			objectMapper: $objectMapper
		);

		$configuration = new Configuration();
		$configuration->setId(1);
		$configuration->setTitle('Config');
		$configuration->setSourceUrl('https://example.org/c.json');
		$configuration->setLocalVersion('1.0.0');

		$out = $handler->previewConfigurationChanges($configuration);
		if ($out instanceof JSONResponse) {
			$out = ['response' => $out->getData(), 'status' => $out->getStatus()];
		}

		if (isset($out['metadata']['previewedAt']) === true) {
			$out['metadata']['previewedAt'] = 'time';
		}

		return json_decode((string)json_encode(['out' => $out, 'calls' => $this->calls]), true);
	}//end runCase()
}//end class
