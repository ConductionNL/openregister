<?php

/**
 * Golden test for CodedFilterExpander.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Vocabulary
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Vocabulary;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Vocabulary\CodedFilterExpander;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclarationFactory;
use OCA\OpenRegister\Service\Vocabulary\ConceptHierarchy;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each case expands one filter set against one schema reference and records
 * the filters returned plus every schema lookup (and its rbac/multitenancy
 * flags) and concept-scheme read. Compared with
 * fixtures/coded-filter-expander-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\Vocabulary\CodedFilterExpander
 */
class CodedFilterExpanderGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/coded-filter-expander-golden.json';

	private const SCHEME = 'https://example.org/vergunningen';

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: mixed, 2: array<string, mixed>}>
	 */
	public static function cases(): array {
		$branch = ['categorie' => ['branch' => 'urn:vergunning']];
		return [
			'no branch filter'            => [['categorie' => 'urn:kap', 'q' => 'x'], 7, []],
			'branch not a string'         => [['categorie' => ['branch' => 5]], 7, []],
			'branch blank'                => [['categorie' => ['branch' => '  ']], 7, []],
			'branch uri store'            => [$branch + ['other' => 'x'], 7, []],
			'branch trimmed'              => [['categorie' => ['branch' => ' urn:kap ']], 7, []],
			'branch notation store'       => [$branch, 7, ['store' => 'notation']],
			'notation blank skipped'      => [['categorie' => ['branch' => 'urn:melding']], 7, ['store' => 'notation']],
			'unknown branch'              => [['categorie' => ['branch' => 'urn:nope']], 7, []],
			'leaf branch'                 => [['categorie' => ['branch' => 'urn:kap-spoed']], 7, []],
			'depth zero'                  => [['categorie' => ['branch' => 'urn:vergunning', 'depth' => 0]], 7, []],
			'depth one string'            => [['categorie' => ['branch' => 'urn:vergunning', 'depth' => '1']], 7, []],
			'depth not numeric'           => [['categorie' => ['branch' => 'urn:vergunning', 'depth' => 'deep']], 7, ['maxDepth' => 1]],
			'declared max depth'          => [$branch, 7, ['maxDepth' => 1]],
			'filter depth beats max'      => [['categorie' => ['branch' => 'urn:vergunning', 'depth' => 2]], 7, ['maxDepth' => 0]],
			'undeclared property'         => [['status' => ['branch' => 'urn:vergunning']], 7, []],
			'mixed properties'            => [['status' => ['branch' => 'urn:vergunning'], 'categorie' => ['branch' => 'urn:kap'], 'q' => ['in' => ['a']]], 7, []],
			'schema not coded'            => [$branch, 7, ['plain' => true]],
			'schema missing'              => [$branch, 404, []],
			'schema ref null'             => [$branch, null, []],
			'schema ref empty'            => [$branch, '', []],
			'schema ref slug'             => [$branch, 'permits', []],
			'schema ref list of one'      => [$branch, ['permits'], []],
			'schema ref keyed one'        => [$branch, ['x' => 7], []],
			'schema ref list of two'      => [$branch, [7, 8], []],
			'schema ref empty list'       => [$branch, [], []],
			'schema ref list of null'     => [$branch, [null], []],
			'schema ref list of array'    => [$branch, [[7]], []],
		];
	}

	/**
	 * @param array<string, mixed> $filters   The filters.
	 * @param mixed                $schemaRef The schema reference.
	 * @param array<string, mixed> $options   The declaration options.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testExpand(array $filters, mixed $schemaRef, array $options): void {
		$record = ['schemaLookups' => [], 'schemeReads' => []];
		$scheme = [
			'urn:vergunning' => ['uri' => 'urn:vergunning', 'narrower' => ['urn:kap', 'urn:melding'], 'notation' => 'V'],
			'urn:kap' => ['uri' => 'urn:kap', 'broader' => ['urn:vergunning'], 'narrower' => ['urn:kap-spoed'], 'notation' => 'VK'],
			'urn:kap-spoed' => ['uri' => 'urn:kap-spoed', 'broader' => ['urn:kap'], 'notation' => 'VKS'],
			'urn:melding' => ['uri' => 'urn:melding', 'broader' => ['urn:vergunning'], 'notation' => ' '],
		];

		$concepts = $this->createMock(ConceptRepository::class);
		$concepts->method('conceptsOf')->willReturnCallback(
			static function (string $schemeUri) use ($scheme, &$record): array {
				$record['schemeReads'][] = $schemeUri;
				return $scheme;
			}
		);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function (...$args) use ($options, &$record): Schema {
				$record['schemaLookups'][] = $args;
				if (($args['id'] ?? $args[0]) === 404) {
					throw new \RuntimeException('not found');
				}

				$declaration = array_diff_key($options, ['plain' => true]);
				$schema = new Schema();
				$property = ['type' => 'string'];
				if (isset($options['plain']) === false) {
					$property[CodedPropertyDeclaration::ANNOTATION] = ['scheme' => self::SCHEME] + $declaration;
				}

				$schema->setProperties(['categorie' => $property, 'q' => ['type' => 'string']]);
				return $schema;
			}
		);

		$lifecycle = new ConceptLifecycle();
		$expander = new CodedFilterExpander(
			scheme: new \OCA\OpenRegister\Service\Vocabulary\ConceptSchemeAccess(concepts: $concepts, hierarchy: new ConceptHierarchy(lifecycle: $lifecycle), lifecycle: $lifecycle),
			tables: new \OCA\OpenRegister\Service\Vocabulary\CodedSchemaTables(schemas: $schemas, declarations: new CodedPropertyDeclarationFactory(), objects: $this->createMock(\OCA\OpenRegister\Db\MagicMapperInterface::class))
		);

		try {
			$outcome = $expander->expand($filters, $schemaRef);
		} catch (\Throwable $e) {
			$outcome = ['thrown' => get_class($e)];
		}

		$actual = json_decode((string) json_encode(['filters' => $outcome, 'record' => $record]), true);
		$case = (string) $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$case] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($case, $golden, 'No golden entry; record with UPDATE_GOLDEN=1.');
		$this->assertSame($golden[$case], $actual);
	}
}
