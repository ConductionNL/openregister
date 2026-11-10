<?php

declare(strict_types=1);

/**
 * Golden test for SelectielijstImportService: pins the exact outcomes (allowed and refused,
 * messages, codes, order and key order) captured on the code before the
 * A15 maintainability refactor, so the refactor provably changes nothing.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service\Archival;

use RuntimeException;
use Throwable;
use OCA\OpenRegister\Service\Archival\SelectielijstImportService;
use OCA\OpenRegister\Service\Archival\ArchivalRegisterMappers;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\Archival\SelectielijstStorage;
use OCA\OpenRegister\Db\SchemaCatalog;

/**
 * Golden outcomes of SelectielijstImportService.
 */
class SelectielijstImportServiceGoldenTest extends TestCase {

	/** @var array<int, array<string, mixed>> The stored rows. */
	private array $stored = [];

	/**
	 * An import service over an in-memory register; saving a row of category `boom` fails.
	 *
	 * @param array<string, mixed> $settings Archival settings.
	 *
	 * @return SelectielijstImportService The service.
	 */
	private function service(array $settings = ['selectielijstRegister' => 1, 'selectielijstSchema' => 2]): SelectielijstImportService {
		$handler = $this->createMock(ObjectRetentionHandler::class);
		$handler->method('getArchivalSettingsOnly')->willReturn($settings);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn(new Register());
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn(new Schema());
		$objectMapper = $this->getMockBuilder(MagicMapper::class)->disableOriginalConstructor()->onlyMethods(['findAllListed'])->getMock();
		$objectMapper->method('findAllListed')->willReturnCallback(
			function (): array {
				$objects = ['not an entity'];
				foreach ($this->stored as $data) {
					$object = new ObjectEntity();
					$object->setObject($data);
					$objects[] = $object;
				}

				return $objects;
			}
		);
		$saveObject = $this->createMock(SaveObject::class);
		$saveObject->method('saveObject')->willReturnCallback(
			function ($register, $schema, array $data): ObjectEntity {
				if (($data['categorie'] ?? null) === 'boom') {
					throw new RuntimeException('disk full');
				}

				$this->stored[] = $data;
				return new ObjectEntity();
			}
		);

		return new SelectielijstImportService(storage: new SelectielijstStorage(settings: $handler, catalog: new SchemaCatalog(registers: $registerMapper, schemas: $schemaMapper, tables: $objectMapper), writer: $saveObject), logger: new NullLogger());
	}

	/**
	 * The outcome of a call, or the class and message of what it threw.
	 *
	 * @param callable $call The call.
	 *
	 * @return mixed The outcome.
	 */
	private function attempt(callable $call): mixed {
		try {
			return $call();
		} catch (Throwable $e) {
			return ['threw' => get_class($e), 'message' => $e->getMessage()];
		}
	}

	/**
	 * Golden: parse.
	 *
	 * @return void
	 */
	public function testParse(): void {
		$this->assertSame(
			array (
			  0 => array (
			    0 => array (
			      'categorie' => '1.1',
			      'archiefnominatie' => 'vernietigen',
			      'bewaartermijn' => 'P5Y',
			    ),
			    1 => array (
			      'categorie' => '2.1',
			      'archiefnominatie' => 'bewaren',
			      'bewaartermijn' => '',
			    ),
			    2 => array (
			      'categorie' => '3.1',
			      'archiefnominatie' => 'a,b',
			      'bewaartermijn' => 'P1Y',
			    ),
			  ),
			  1 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'The selectielijst CSV has no header row',
			  ),
			  2 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'The selectielijst CSV has no header row',
			  ),
			  3 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'Row 1 of the selectielijst CSV has 1 columns and the header has 2. A shifted column turns a bewaartermijn into a category, so the import refuses rather than pads.',
			  ),
			  4 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'Row 1 of the selectielijst has no "categorie". A row nothing can match is a rule nobody notices is broken, so the import refuses it.',
			  ),
			  5 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'The selectielijst file holds no rows',
			  ),
			  6 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'The selectielijst file holds no rows',
			  ),
			  7 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'The selectielijst file is not readable JSON: Syntax error',
			  ),
			  8 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'The selectielijst file is not readable JSON: No error',
			  ),
			  9 => array (
			    0 => array (
			      'categorie' => '4.4',
			      'x' => 1,
			    ),
			    1 => array (
			      'categorie' => '5',
			    ),
			  ),
			  10 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'Row 1 of the selectielijst has no "categorie". A row nothing can match is a rule nobody notices is broken, so the import refuses it.',
			  ),
			  11 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'Row 1 of the selectielijst is not a set of fields',
			  ),
			  12 => array (
			    0 => array (
			      'categorie' => 'z',
			    ),
			  ),
			  13 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'A selectielijst is imported from a .csv or a .json file, not a ".xml"',
			  ),
			  14 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'A selectielijst is imported from a .csv or a .json file, not a "."',
			  ),
			),
			$this->caseParse()
		);
	}

	/**
	 * Golden: tooManyRows.
	 *
	 * @return void
	 */
	public function testTooManyRows(): void {
		$this->assertSame(
			array (
			  'threw' => 'InvalidArgumentException',
			  'message' => 'The selectielijst file holds 5001 rows; the import accepts at most 5000',
			),
			$this->caseTooManyRows()
		);
	}

	/**
	 * Golden: importAndVersions.
	 *
	 * @return void
	 */
	public function testImportAndVersions(): void {
		$this->assertSame(
			array (
			  0 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'A selectielijst import names the version it is, so the rows it writes can be told from the ones already here',
			  ),
			  1 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'No selectielijst register and schema are configured, so there is nowhere to put a list. Set them under Retention settings first.',
			  ),
			  2 => array (
			    'version' => 'v1',
			    'imported' => 2,
			    'failed' => 1,
			  ),
			  3 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'Version "v1" is already imported. Import it under a different name, or compare it with the one in use instead of loading it twice.',
			  ),
			  4 => array (
			    'version' => 'v2',
			    'imported' => 2,
			    'failed' => 0,
			  ),
			  5 => array (
			    'v1' => 2,
			    'v2' => 3,
			    'unversioned' => 1,
			    'v0' => 1,
			  ),
			  6 => array (
			    'a' => array (
			      'id' => NULL,
			      'categorie' => 'a',
			      'archiefnominatie' => 'bewaren',
			      'bewaartermijn' => 'P1Y',
			      'selectielijstVersie' => 'v1',
			      'bron' => 'upload.csv',
			    ),
			    'b' => array (
			      'id' => NULL,
			      'categorie' => 'b',
			      'bron' => 'eigen',
			      'selectielijstVersie' => 'v1',
			    ),
			  ),
			  7 => array (
			    'old' => array (
			      'id' => NULL,
			      'categorie' => 'old',
			    ),
			  ),
			  8 => array (
			    'a' => array (
			      'id' => NULL,
			      'categorie' => 'a',
			      'archiefnominatie' => 'vernietigen',
			      'bewaartermijn' => 'P1Y',
			      'selectielijstVersie' => 'v2',
			    ),
			    'c' => array (
			      'id' => NULL,
			      'categorie' => 'c',
			      'selectielijstVersie' => 'v2',
			    ),
			  ),
			  9 => array (
			    0 => array (
			      'categorie' => 'a',
			      'archiefnominatie' => 'bewaren',
			      'bewaartermijn' => 'P1Y',
			      'selectielijstVersie' => 'v1',
			      'bron' => 'upload.csv',
			    ),
			    1 => array (
			      'categorie' => 'b',
			      'bron' => 'eigen',
			      'selectielijstVersie' => 'v1',
			    ),
			    2 => array (
			      'categorie' => 'a',
			      'archiefnominatie' => 'vernietigen',
			      'bewaartermijn' => 'P1Y',
			      'selectielijstVersie' => 'v2',
			    ),
			    3 => array (
			      'categorie' => 'c',
			      'selectielijstVersie' => 'v2',
			    ),
			    4 => array (
			      'categorie' => 'old',
			    ),
			    5 => array (
			      'categorie' => 'older',
			      'versie' => ' v0 ',
			    ),
			    6 => array (
			      'categorie' => '',
			      'version' => 'v2',
			    ),
			  ),
			  10 => array (
			  ),
			),
			$this->caseImportAndVersions()
		);
	}

	/**
	 * Golden: diff.
	 *
	 * @return void
	 */
	public function testDiff(): void {
		$this->assertSame(
			array (
			  0 => array (
			    'from' => 'v1',
			    'to' => 'v2',
			    'added' => array (
			      0 => 'd',
			    ),
			    'removed' => array (
			      0 => 'c',
			    ),
			    'changed' => array (
			      0 => array (
			        'category' => 'a',
			        'fields' => array (
			          'archiefnominatie' => array (
			            'from' => 'bewaren',
			            'to' => 'vernietigen',
			          ),
			          'bewaartermijn' => array (
			            'from' => 'P1Y',
			            'to' => 'P2Y',
			          ),
			        ),
			      ),
			      1 => array (
			        'category' => '7',
			        'fields' => array (
			          'bewaartermijn' => array (
			            'from' => 'P1Y',
			            'to' => 'P3Y',
			          ),
			        ),
			      ),
			    ),
			  ),
			  1 => array (
			    'from' => 'v2',
			    'to' => 'v1',
			    'added' => array (
			      0 => 'c',
			    ),
			    'removed' => array (
			      0 => 'd',
			    ),
			    'changed' => array (
			      0 => array (
			        'category' => 'a',
			        'fields' => array (
			          'archiefnominatie' => array (
			            'from' => 'vernietigen',
			            'to' => 'bewaren',
			          ),
			          'bewaartermijn' => array (
			            'from' => 'P2Y',
			            'to' => 'P1Y',
			          ),
			        ),
			      ),
			      1 => array (
			        'category' => '7',
			        'fields' => array (
			          'bewaartermijn' => array (
			            'from' => 'P3Y',
			            'to' => 'P1Y',
			          ),
			        ),
			      ),
			    ),
			  ),
			  2 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'No selectielijst rows are stored under version "none"',
			  ),
			  3 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'No selectielijst rows are stored under version "none"',
			  ),
			  4 => array (
			    'threw' => 'InvalidArgumentException',
			    'message' => 'No selectielijst rows are stored under version "none"',
			  ),
			),
			$this->caseDiff()
		);
	}

	private function caseParse(): mixed {
		$s = $this->service(); $csv = "categorie, archiefnominatie ,bewaartermijn\r\n 1.1 ,vernietigen, P5Y \n\n2.1,bewaren,\r3.1,\"a,b\",P1Y"; $out = []; foreach ([[$csv, "list.CSV"], ["", "x.csv"], ["   \n", "x.csv"], ["a,b\n1", "x.csv"], ["a,b\n1,2", "x.csv"], ["categorie\n", "x.csv"], ["[]", "x.json"], ["{bad", "x.json"], ["5", "x.json"], ["[{\"categorie\": \" 4.4 \", \"x\": 1}, {\"categorie\": 5}]", "x.json"], ["[{\"categorie\": \"\"}]", "x.json"], ["[1]", "x.json"], ["{\"a\": {\"categorie\": \"z\"}}", "x.json"], ["x", "x.xml"], ["x", "noext"]] as [$contents, $name]) { $out[] = $this->attempt(fn () => $s->parse($contents, $name)); } return $out;
	}

	private function caseTooManyRows(): mixed {
		$rows = []; for ($i = 0; $i <= 5000; $i++) { $rows[] = ["categorie" => "c" . $i]; } return $this->attempt(fn () => $this->service()->parse(json_encode($rows), "x.json"));
	}

	private function caseImportAndVersions(): mixed {
		$s = $this->service(); $out = []; $out[] = $this->attempt(fn () => $s->import([["categorie" => "a"]], "  ")); $out[] = $this->attempt(fn () => $this->service(["selectielijstRegister" => 1])->import([["categorie" => "a"]], "v1")); $out[] = $this->attempt(fn () => $s->import([["categorie" => "a", "archiefnominatie" => "bewaren", "bewaartermijn" => "P1Y"], ["categorie" => "boom"], ["categorie" => "b", "bron" => "eigen"]], " v1 ", "upload.csv")); $out[] = $this->attempt(fn () => $s->import([["categorie" => "a"]], "v1")); $out[] = $this->attempt(fn () => $s->import([["categorie" => "a", "archiefnominatie" => "vernietigen", "bewaartermijn" => "P1Y"], ["categorie" => "c", "selectielijstVersie" => "ignored"]], "v2")); $this->stored[] = ["categorie" => "old"]; $this->stored[] = ["categorie" => "older", "versie" => " v0 "]; $this->stored[] = ["categorie" => "", "version" => "v2"]; $out[] = $s->versions(); $out[] = $s->rowsOf("v1"); $out[] = $s->rowsOf("unversioned"); $out[] = $s->rowsOf("v2"); $out[] = $this->stored; $out[] = $this->service([])->versions(); return $out;
	}

	private function caseDiff(): mixed {
		$s = $this->service(); $this->stored = [["categorie" => "a", "archiefnominatie" => "bewaren", "bewaartermijn" => "P1Y", "selectielijstVersie" => "v1"], ["categorie" => "b", "archiefnominatie" => "vernietigen", "bewaartermijn" => "P5Y", "omschrijving" => "x", "selectielijstVersie" => "v1"], ["categorie" => "c", "selectielijstVersie" => "v1"], ["categorie" => "a", "archiefnominatie" => "vernietigen", "bewaartermijn" => "P2Y", "selectielijstVersie" => "v2"], ["categorie" => "b", "archiefnominatie" => "vernietigen", "bewaartermijn" => "P5Y", "omschrijving" => "y", "selectielijstVersie" => "v2"], ["categorie" => "d", "bewaartermijn" => "P9Y", "selectielijstVersie" => "v2"], ["categorie" => "7", "bewaartermijn" => "P1Y", "selectielijstVersie" => "v1"], ["categorie" => "7", "bewaartermijn" => "P3Y", "selectielijstVersie" => "v2"]]; return [$s->diff("v1", "v2"), $s->diff("v2", "v1"), $this->attempt(fn () => $s->diff("v1", "none")), $this->attempt(fn () => $s->diff("none", "v1")), $this->attempt(fn () => $s->diff("none", "nada"))];
	}
}
