<?php

/**
 * Characterisation tests pinning the branch outcomes of the SchemaImport
 * dialect importers, snapshots, detector and options before their
 * complexity refactor. Pure mapping over small synthetic fixtures.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\SchemaImport
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\SchemaImport;

use OCA\OpenRegister\Exception\SchemaImportException;
use OCA\OpenRegister\Service\SchemaImport\DialectDetector;
use OCA\OpenRegister\Service\SchemaImport\GgmImporter;
use OCA\OpenRegister\Service\SchemaImport\GgmSnapshot;
use OCA\OpenRegister\Service\SchemaImport\ImportOptions;
use OCA\OpenRegister\Service\SchemaImport\SchemaOrgImporter;
use OCA\OpenRegister\Service\SchemaImport\SchemaImportService;
use OCA\OpenRegister\Service\SchemaImport\SchemaOrgSnapshot;
use OCA\OpenRegister\Service\SchemaImport\ThreeWayMerge;
use PHPUnit\Framework\TestCase;

class SchemaImportDialectGoldenTest extends TestCase {

	/**
	 * Temp files created by a test.
	 *
	 * @var array<int, string>
	 */
	private array $files = [];

	protected function tearDown(): void {
		foreach ($this->files as $file) {
			if (is_file($file) === true) {
				unlink($file);
			}
		}
	}

	private function writeFixture(string $content): string {
		$file = tempnam(sys_get_temp_dir(), 'sigolden');
		file_put_contents($file, $content);
		$this->files[] = $file;
		return $file;
	}

	/**
	 * A small Schema.org graph covering every range kind and node shape.
	 */
	private function schemaOrgGraph(): array {
		$prop = static function (string $name, mixed $domains, mixed $ranges, ?string $comment = null): array {
			$node = [
				'@id' => 'schema:' . $name,
				'@type' => 'rdf:Property',
				'rdfs:label' => $name,
				'schema:domainIncludes' => $domains,
				'schema:rangeIncludes' => $ranges,
			];
			if ($comment !== null) {
				$node['rdfs:comment'] = $comment;
			}

			return $node;
		};
		$id = static fn (string $name): array => ['@id' => 'schema:' . $name];

		$graph = [
			'not-a-node',
			['@type' => 'rdfs:Class'],
			['@id' => 'schema:Thing', '@type' => 'rdfs:Class', 'rdfs:label' => 'Thing', 'rdfs:comment' => 'The most generic type.'],
			['@id' => 'schema:Widget', '@type' => ['rdfs:Class', 7], 'rdfs:subClassOf' => $id('Thing'), 'rdfs:comment' => 'A gadget.'],
			['@id' => 'http://schema.org/Gizmo', '@type' => 'rdfs:Class', 'rdfs:label' => 'Gizmo'],
			['@id' => 'schema:Ignored', '@type' => 'rdfs:Datatype'],
			['@id' => 'schema:NoType'],
			$prop('name', $id('Thing'), $id('Text'), 'The name.'),
		];
		$ranges = [
			'pText' => 'Text', 'pPron' => 'PronounceableText', 'pCss' => 'CssSelectorType', 'pXpath' => 'XPathType',
			'pNumber' => 'Number', 'pFloat' => 'Float', 'pInt' => 'Integer', 'pBool' => 'Boolean',
			'pDate' => 'Date', 'pDateTime' => 'DateTime', 'pTime' => 'Time', 'pUrl' => 'URL', 'pRef' => 'Gizmo',
		];
		foreach ($ranges as $name => $range) {
			$graph[] = $prop($name, [$id('Widget'), 'junk'], $id($range));
		}

		$graph[] = $prop('pNone', $id('Widget'), null);
		$graph[] = $prop('pTextUrl', $id('Widget'), [$id('URL'), $id('Text')]);
		$graph[] = $prop('pNumInt', $id('Widget'), [$id('Integer'), $id('Number')]);
		$graph[] = $prop('pRefBool', $id('Widget'), [$id('Boolean'), $id('Gizmo')]);
		$graph[] = $prop('pDateInt', $id('Widget'), [$id('Integer'), $id('Date')]);
		$graph[] = $prop('pBoolInt', $id('Widget'), [$id('Boolean'), $id('Integer'), 'x']);

		return ['@graph' => $graph];
	}

	private function schemaOrgImporter(): SchemaOrgImporter {
		$file = $this->writeFixture((string)json_encode($this->schemaOrgGraph()));
		return new SchemaOrgImporter(new SchemaOrgSnapshot($file, 'v-golden'));
	}

	public function testSchemaOrgRangeMappingTable(): void {
		$result = $this->schemaOrgImporter()->import('Widget', new ImportOptions());
		$expected = [
			'pText' => ['type' => 'string'],
			'pPron' => ['type' => 'string'],
			'pCss' => ['type' => 'string'],
			'pXpath' => ['type' => 'string'],
			'pNumber' => ['type' => 'number'],
			'pFloat' => ['type' => 'number'],
			'pInt' => ['type' => 'integer'],
			'pBool' => ['type' => 'boolean'],
			'pDate' => ['type' => 'string', 'format' => 'date'],
			'pDateTime' => ['type' => 'string', 'format' => 'date-time'],
			'pTime' => ['type' => 'string', 'format' => 'time'],
			'pUrl' => ['type' => 'string', 'format' => 'uri'],
			'pRef' => ['type' => 'string', 'format' => 'uri', 'description' => 'Reference to a Gizmo (https://schema.org/Gizmo).'],
			'pNone' => ['type' => 'string'],
			'pTextUrl' => ['type' => 'string'],
			'pNumInt' => ['type' => 'number'],
			'pRefBool' => ['type' => 'string'],
			'pDateInt' => ['type' => 'string'],
			'pBoolInt' => ['type' => 'integer'],
		];
		$this->assertSame($expected, $result->properties);
		$this->assertSame([], $result->unknownRequested);
		$this->assertSame('Widget', $result->title);
		$this->assertSame('A gadget.', $result->description);
	}

	public function testSchemaOrgImportBlocksAndAncestors(): void {
		$importer = $this->schemaOrgImporter();
		$result = $importer->import('https://schema.org/Widget', new ImportOptions(propertySubset: ['name', 'pInt', 'nope'], includeAncestors: true));

		$this->assertSame(['name' => ['type' => 'string', 'description' => 'The name.'], 'pInt' => ['type' => 'integer']], $result->properties);
		$this->assertSame(['nope'], $result->unknownRequested);
		$this->assertSame(
			['@vocab' => 'https://schema.org/', 'type' => 'schema:Widget', 'properties' => ['name' => 'schema:name', 'pInt' => 'schema:pInt']],
			$result->jsonld
		);
		$source = $result->importSource;
		$this->assertArrayHasKey('importedAt', $source);
		unset($source['importedAt']);
		$this->assertSame(
			['dialect' => 'schema.org', 'reference' => 'schema:Widget', 'snapshotVersion' => 'v-golden', 'baseline' => $result->properties],
			$source
		);

		// Without ancestors the inherited property is unknown.
		$direct = $importer->import('schema:Widget', new ImportOptions(propertySubset: ['name']));
		$this->assertSame([], $direct->properties);
		$this->assertSame(['name'], $direct->unknownRequested);

		// Class label defaults to bare name; comment defaults to empty.
		$gizmo = $importer->import('Gizmo', new ImportOptions());
		$this->assertSame('Gizmo', $gizmo->title);
		$this->assertSame('', $gizmo->description);
		$this->assertSame([], $gizmo->properties);
	}

	public function testSchemaOrgSnapshotIndexing(): void {
		$file = $this->writeFixture((string)json_encode($this->schemaOrgGraph()));
		$snapshot = new SchemaOrgSnapshot($file, 'v');

		$this->assertNull($snapshot->findClass('Ignored'));
		$this->assertNull($snapshot->findClass('NoType'));
		$this->assertSame(['iri' => 'schema:Widget', 'label' => 'Widget', 'comment' => 'A gadget.', 'parent' => 'schema:Thing'], $snapshot->findClass('Widget'));
		$this->assertSame(['Widget', 'Thing'], $snapshot->ancestryOf('Widget'));
		$this->assertSame(['Widget'], $snapshot->directPropertiesOf('Widget')['pText']['domains']);
		$this->assertSame(['URL', 'Text'], $snapshot->directPropertiesOf('Widget')['pTextUrl']['ranges']);
		$this->assertSame([], $snapshot->directPropertiesOf('Widget')['pNone']['ranges']);
		$this->assertCount(2, $snapshot->searchClasses('', 2));
		$this->assertSame(['schema:Thing'], array_column($snapshot->searchClasses('generic'), 'iri'));

		// Parsed once: the file is no longer read after the first access.
		unlink($file);
		$this->assertNotNull($snapshot->findClass('Thing'));
	}

	public function testSchemaOrgSnapshotRefusals(): void {
		$cases = [
			'missing' => ['/nonexistent/dir/release.jsonld', 'Schema.org snapshot file is missing: /nonexistent/dir/release.jsonld'],
			'not-json' => [$this->writeFixture('{not json'), 'Schema.org snapshot is not a valid JSON-LD release file.'],
			'no-graph' => [$this->writeFixture('{"a":1}'), 'Schema.org snapshot is not a valid JSON-LD release file.'],
		];
		foreach ($cases as $label => [$file, $message]) {
			try {
				(new SchemaOrgSnapshot($file, 'v'))->findClass('Thing');
				$this->fail('Expected refusal for ' . $label);
			} catch (SchemaImportException $e) {
				$this->assertSame($message, $e->getMessage(), $label);
				$this->assertSame(500, $e->getHttpStatus(), $label);
			}
		}

		$this->expectException(SchemaImportException::class);
		$this->schemaOrgImporter()->import('Nope', new ImportOptions());
	}

	private function ggmNormalised(): array {
		return [
			'version' => 'ggm-golden',
			'objecttypen' => [
				'skip',
				['naam' => 'no id'],
				[
					'id' => 'ot1',
					'naam' => 'Zaak',
					'definitie' => 'Een zaak.',
					'attribuutsoorten' => [
						['naam' => 'tekst', 'type' => ' Tekst ', 'definitie' => 'Vrij.'],
						['naam' => 'default'],
						['naam' => 'heel', 'type' => 'geheel getal'],
						['naam' => 'dec', 'type' => 'decimaal'],
						['naam' => 'bool', 'type' => 'boolean'],
						['naam' => 'dat', 'type' => 'datum'],
						['naam' => 'dt', 'type' => 'datumtijd'],
						['naam' => 'rel', 'type' => 'relatie', 'doelObjecttype' => 'PERSOON'],
						['naam' => 'relnone', 'type' => 'relatie'],
						['naam' => 'odd', 'type' => 'blob'],
						['naam' => 'lijst', 'type' => 'tekst', 'referentielijst' => ['waarden' => ['a' => 'x', 'b' => 'y']]],
						['naam' => 'leeg', 'referentielijst' => ['waarden' => []]],
						['type' => 'tekst'],
						'garbage',
						['naam' => 7, 'type' => 'boolean'],
					],
				],
				['id' => 'ot2', 'naam' => 'Kaal', 'attribuutsoorten' => 'not-a-list'],
			],
		];
	}

	public function testGgmImportMappingTable(): void {
		$importer = new GgmImporter(GgmSnapshot::fromNormalised($this->ggmNormalised()), 'upload.json');
		$result = $importer->import('Zaak', new ImportOptions());

		$this->assertSame(
			[
				'tekst' => ['type' => 'string', 'description' => 'Vrij.'],
				'default' => ['type' => 'string'],
				'heel' => ['type' => 'integer'],
				'dec' => ['type' => 'number'],
				'bool' => ['type' => 'boolean'],
				'dat' => ['type' => 'string', 'format' => 'date'],
				'dt' => ['type' => 'string', 'format' => 'date-time'],
				'rel' => ['type' => 'string', 'format' => 'uri', 'description' => 'Reference to GGM objecttype PERSOON.'],
				'relnone' => ['type' => 'string', 'format' => 'uri'],
				'odd' => ['type' => 'string'],
				'lijst' => ['type' => 'string', 'enum' => ['x', 'y']],
				'leeg' => ['type' => 'string'],
				'7' => ['type' => 'boolean'],
			],
			$result->properties
		);
		$this->assertSame('Zaak', $result->title);
		$this->assertSame('Een zaak.', $result->description);
		$this->assertSame([], $result->jsonld);
		$source = $result->importSource;
		unset($source['importedAt']);
		$this->assertSame(
			['dialect' => 'ggm', 'reference' => 'ot1', 'snapshotVersion' => 'ggm-golden', 'source' => 'upload.json', 'baseline' => $result->properties],
			$source
		);
	}

	public function testGgmImportSubsetAndEdgeCases(): void {
		$importer = new GgmImporter(GgmSnapshot::fromNormalised($this->ggmNormalised()));
		$result = $importer->import('OT1', new ImportOptions(propertySubset: ['dat', 'missing', 'heel']));
		$this->assertSame(['dat' => ['type' => 'string', 'format' => 'date'], 'heel' => ['type' => 'integer']], $result->properties);
		$this->assertSame(['missing'], $result->unknownRequested);
		$this->assertSame('snapshot', $result->importSource['source']);

		$bare = $importer->import('kaal', new ImportOptions());
		$this->assertSame([], $bare->properties);
		$this->assertSame('', $bare->description);

		$this->expectException(SchemaImportException::class);
		$importer->import('nope', new ImportOptions());
	}

	public function testGgmSnapshotLoading(): void {
		$file = $this->writeFixture((string)json_encode(['objecttypen' => [['id' => 'a', 'naam' => 'A', 'definitie' => 'alpha']]]));
		$snapshot = new GgmSnapshot($file, 'bundled-v');
		$this->assertSame('bundled-v', $snapshot->version());
		$this->assertSame('A', $snapshot->findObjecttype('A')['naam']);
		unlink($file);
		$this->assertCount(1, $snapshot->searchObjecttypes('ALPHA'));

		$this->assertSame('upload', GgmSnapshot::fromNormalised(['objecttypen' => []])->version());

		$cases = [
			'missing' => [new GgmSnapshot('/nonexistent/ggm.json', 'v'), 'GGM snapshot file is missing: /nonexistent/ggm.json', 500],
			'not-json' => [new GgmSnapshot($this->writeFixture('nope'), 'v'), 'GGM source is not a valid normalised GGM intermediate (missing "objecttypen").', 422],
			'no-key' => [GgmSnapshot::fromNormalised(['version' => 'x']), 'GGM source is not a valid normalised GGM intermediate (missing "objecttypen").', 422],
			'not-list' => [GgmSnapshot::fromNormalised(['objecttypen' => 'x']), 'GGM source is not a valid normalised GGM intermediate (missing "objecttypen").', 422],
		];
		foreach ($cases as $label => [$broken, $message, $status]) {
			try {
				$broken->version();
				$this->fail('Expected refusal for ' . $label);
			} catch (SchemaImportException $e) {
				$this->assertSame($message, $e->getMessage(), $label);
				$this->assertSame($status, $e->getHttpStatus(), $label);
			}
		}
	}

	public function testDialectDetectorDecisionTable(): void {
		$detector = new DialectDetector();
		$cases = [
			[['openapi' => '3.0', 'components' => [], '@context' => 'https://schema.org'], 'openapi'],
			[['openapi' => '3.0', '$schema' => 'x'], 'json-schema'],
			[['@context' => 'https://schema.org/', 'standard' => 'ggm'], 'schema.org'],
			[['@context' => ['@vocab' => 'http://schema.org/']], 'schema.org'],
			[['@context' => ['x' => 1, 'y' => 'other']], null],
			[['@context' => 5, '@type' => 'https://schema.org/Person'], 'schema.org'],
			[['@type' => ['schema.org']], null],
			[['@type' => 'Person'], null],
			[['standard' => 'GGM'], 'ggm'],
			[['standard' => 3, 'objecttypen' => []], 'ggm'],
			[['objecttypen' => 'x'], null],
			[['attribuutsoorten' => null], null],
			[['attribuutsoorten' => 'x', '$schema' => 'y'], 'ggm'],
			[['$schema' => null], null],
			[['$schema' => 'x'], 'json-schema'],
			[['type' => 'object', 'properties' => []], 'json-schema'],
			[['type' => 'object', 'properties' => 'x'], null],
			[['properties' => []], null],
			[[], null],
		];
		foreach ($cases as $index => [$document, $expected]) {
			$this->assertSame($expected, $detector->detect($document), 'case ' . $index);
		}
	}

	public function testImportOptionsFromArray(): void {
		$options = ImportOptions::fromArray(['propertySubset' => ['a', '', 3, 'b'], 'includeAncestors' => 'yes', 'targetRegister' => '12']);
		$this->assertSame(['a', 'b'], $options->propertySubset);
		$this->assertTrue($options->includeAncestors);
		$this->assertSame(12, $options->targetRegister);

		$defaults = ImportOptions::fromArray(['propertySubset' => 'a', 'includeAncestors' => null, 'targetRegister' => 'x']);
		$this->assertSame([], $defaults->propertySubset);
		$this->assertFalse($defaults->includeAncestors);
		$this->assertNull($defaults->targetRegister);
		$this->assertFalse($defaults->hasSubset());

		$this->assertFalse(ImportOptions::fromArray(['includeAncestors' => 'off'])->includeAncestors);
	}

	public function testResolveUploadDialectEmptyExplicitFallsBackToDetection(): void {
		$service = new SchemaImportService(new DialectDetector(), new ThreeWayMerge(), dirname(__DIR__, 4) . '/lib/Resources');
		$this->assertSame('ggm', $service->resolveUploadDialect(['standard' => 'ggm'], ''));
		$this->assertSame('ggm', $service->resolveUploadDialect(['standard' => 'ggm'], 'ggm'));
		try {
			$service->resolveUploadDialect(['standard' => 'ggm'], 'GGM');
			$this->fail('Expected refusal for a non-exact explicit dialect');
		} catch (SchemaImportException $e) {
			$this->assertSame(422, $e->getHttpStatus());
		}

		try {
			$service->resolveUploadDialect([], '');
			$this->fail('Expected refusal for an undetectable document');
		} catch (SchemaImportException $e) {
			$this->assertSame(422, $e->getHttpStatus());
		}
	}
}
