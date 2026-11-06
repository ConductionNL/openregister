<?php

/**
 * Golden of Schema::hydrate(), jsonSerialize() and getSchemaObject().
 *
 * Each case hydrates a new schema and records what it serialises to, which
 * fields it marks as updated, and the JSON Schema document it renders.
 * Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\InversedByNormaliser
 * @uses   \OCA\OpenRegister\Db\Schema\SchemaPropertyDocument
 */
class SchemaDocumentGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/schema-document-golden.json';

	/**
	 * The hydrate inputs.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		$inversed = static fn (mixed $value): array => ['properties' => ['p' => ['type' => 'object', 'inversedBy' => $value, 'items' => ['inversedBy' => ['id' => 'item']]]]];
		$itemInversed = static fn (mixed $value): array => ['properties' => ['p' => ['type' => 'array', 'items' => ['inversedBy' => $value]]]];

		return [
			'empty' => [],
			'required null' => ['required' => null],
			'required empty' => ['required' => []],
			'required list' => ['required' => ['a', 'b']],
			'hard validation off' => ['hardValidation' => false],
			'hard validation truthy' => ['hardValidation' => 1],
			'configuration json' => ['configuration' => '{"objectNameField":"title"}'],
			'configuration bad json' => ['configuration' => '{bad'],
			'configuration array' => ['configuration' => ['objectNameField' => 'title']],
			'configuration refused' => ['configuration' => ['objectNameField' => ['not', 'a', 'string']]],
			'configuration empty' => ['configuration' => []],
			'configuration null' => ['configuration' => null],
			'date text' => ['created' => '2026-01-02T03:04:05+00:00', 'updated' => '2026-02-03T04:05:06+00:00', 'deleted' => '2026-03-04T05:06:07+00:00'],
			'date bad text' => ['created' => 'not a date'],
			'date empty text' => ['updated' => ''],
			'date object' => ['created' => new DateTime('2026-01-02T03:04:05+00:00')],
			'date number' => ['deleted' => 12345],
			'empty json fields' => ['allOf' => [], 'oneOf' => [], 'anyOf' => [], 'archive' => [], 'hooks' => []],
			'plain fields' => ['title' => 'T', 'description' => 'D', 'version' => '1.0.0', 'slug' => 'MixedCase', 'icon' => 'mdi', 'maxDepth' => 3, 'summary' => 'S'],
			'wrong type field' => ['maxDepth' => 'many'],
			'unknown field' => ['nothingLikeThis' => 1],
			'draft dropped' => ['draft' => ['title' => 'x'], 'title' => 'T'],
			'exportable' => ['exportable' => true],
			'metadata' => ['metadata' => ['a' => 1]],
			'authorization valid' => ['authorization' => ['read' => ['public'], 'update' => ['editors']]],
			'authorization refused' => ['authorization' => ['read' => 'public']],
			'authorization unknown action' => ['authorization' => ['nothing' => ['public']]],
			'authorization null' => ['authorization' => null],
			'inversed string' => $inversed('field'),
			'inversed legacy' => $inversed(['id' => 'field']),
			'inversed list' => $inversed(['a', 'b']),
			'inversed map' => $inversed(['x' => 'y']),
			'inversed number' => $inversed(5),
			'items string' => $itemInversed('field'),
			'items legacy' => $itemInversed(['id' => 'field']),
			'items list' => $itemInversed(['a', 'b']),
			'items number' => $itemInversed(5),
			'property required flag' => ['required' => ['b'], 'properties' => ['a' => ['type' => 'string', 'required' => true], 'b' => ['type' => 'string', 'required' => true], 'c' => ['type' => 'string', 'required' => false]]],
			'falsy keys' => ['properties' => ['a' => ['type' => 'boolean', 'default' => false, 'minimum' => 0, 'title' => '', 'description' => null, 'const' => '', 'required' => true]]],
			'computed' => ['properties' => ['a' => ['type' => 'string', 'computed' => ['x' => 1]], 'b' => ['type' => 'string', 'calculation' => ['+' => [1]]], 'c' => ['type' => 'string', 'computed' => 'text']]],
			'nested' => ['properties' => ['a' => ['title' => 'A', 'type' => 'object', 'properties' => [
				'x' => ['type' => 'string', 'required' => true, 'oneOf' => []],
				'y' => ['type' => 'string', 'required' => false, 'oneOf' => [['type' => 'string']]],
				'z' => ['type' => 'string', 'required' => 'yes'],
			]]]],
		];
	}//end cases()

	public function testDocumentsMatchGolden(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('https://example.test');

		$validator = $this->createMock(PropertyValidatorHandler::class);
		$validator->method('validateProperties')->willReturn(true);

		$actual = [];
		foreach (self::cases() as $name => $input) {
			$schema = new Schema();
			$schema->setUuid('schema-uuid');
			$outcome = [];
			try {
				$schema->hydrate($input, $validator);
			} catch (\Throwable $e) {
				$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
			}

			$outcome['serialised'] = $schema->jsonSerialize();
			$outcome['updated'] = array_keys($schema->getUpdatedFields());
			try {
				$outcome['document'] = json_decode((string) json_encode($schema->getSchemaObject($urls)), true);
			} catch (\Throwable $e) {
				$outcome['documentThrown'] = get_class($e) . ': ' . $e->getMessage();
			}

			$actual[$name] = $outcome;
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertNotEmpty($actual['nested']['document']['properties']);
		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), json_decode((string) json_encode($actual), true));
	}//end testDocumentsMatchGolden()
}//end class
