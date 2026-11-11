<?php

declare(strict_types=1);

/**
 * Golden test for SelectionListSeeder: pins the exact outcomes (allowed and refused,
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

use OCA\OpenRegister\Service\Archival\SelectionListSeeder;
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
use RuntimeException;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\Archival\SelectielijstStorage;
use OCA\OpenRegister\Db\SchemaCatalog;

/**
 * Golden outcomes of SelectionListSeeder.
 */
class SelectionListSeederGoldenTest extends TestCase {

	/** @var array<string, ObjectEntity> The selectielijst register, by uuid. */
	private array $rows = [];

	/**
	 * A seeder over an in-memory register.
	 *
	 * @param array<string, mixed> $settings Archival settings.
	 *
	 * @return SelectionListSeeder The seeder.
	 */
	private function seeder(array $settings = ['selectielijstRegister' => 4, 'selectielijstSchema' => 6]): SelectionListSeeder {
		$objectMapper = $this->getMockBuilder(MagicMapper::class)->disableOriginalConstructor()->onlyMethods(['findAllListed'])->getMock();
		$objectMapper->method('findAllListed')->willReturnCallback(fn (): array => array_values($this->rows));
		$saveObject = $this->createMock(SaveObject::class);
		$saveObject->method('saveObject')->willReturnCallback(
			function ($register, $schema, array $data, ?string $uuid = null): ObjectEntity {
				if (($data['categorie'] ?? null) === 'boom') {
					throw new RuntimeException('disk full');
				}

				$uuid = ($uuid ?? 'row-' . (count($this->rows) + 1));
				$row = new ObjectEntity();
				$row->setUuid($uuid);
				$row->setObject($data);
				$this->rows[$uuid] = $row;
				return $row;
			}
		);
		$handler = $this->createMock(ObjectRetentionHandler::class);
		$handler->method('getArchivalSettingsOnly')->willReturn($settings);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn(new Register());
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn(new Schema());

		return new SelectionListSeeder(storage: new SelectielijstStorage(settings: $handler, catalog: new SchemaCatalog(registers: $registerMapper, schemas: $schemaMapper, tables: $objectMapper), writer: $saveObject), logger: new NullLogger());
	}

	/**
	 * Seed, and return the result with every stored row's data.
	 *
	 * @param SelectionListSeeder      $seeder  The seeder.
	 * @param array<int|string, mixed> $entries The entries.
	 * @param string|null              $appId   The app.
	 *
	 * @return array<string, mixed> The result and the register.
	 */
	private function seedOnce(SelectionListSeeder $seeder, array $entries, ?string $appId = 'app1'): array {
		$result = $seeder->seed(entries: $entries, appId: $appId);
		$stored = [];
		foreach ($this->rows as $uuid => $row) {
			$stored[$uuid] = $row->getObject();
		}

		return ['result' => $result, 'rows' => $stored];
	}

	/**
	 * A stored row.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return void
	 */
	private function store(string $uuid, array $data): void {
		$row = new ObjectEntity();
		$row->setUuid($uuid);
		$row->setObject($data);
		$this->rows[$uuid] = $row;
	}

	/**
	 * Golden: unconfigured.
	 *
	 * @return void
	 */
	public function testUnconfigured(): void {
		$this->assertSame(
			array (
			  'result' => array (
			    'created' => 0,
			    'updated' => 0,
			    'unchanged' => 0,
			    'kept' => 0,
			    'failed' => array (
			      0 => array (
			        'category' => '2.1',
			        'reason' => 'No selectielijst register and schema are configured, so there is nowhere to put the row. Set them under Retention settings.',
			      ),
			      1 => array (
			        'category' => '3.1',
			        'reason' => 'No selectielijst register and schema are configured, so there is nowhere to put the row. Set them under Retention settings.',
			      ),
			      2 => array (
			        'category' => '',
			        'reason' => 'No selectielijst register and schema are configured, so there is nowhere to put the row. Set them under Retention settings.',
			      ),
			      3 => array (
			        'category' => '',
			        'reason' => 'No selectielijst register and schema are configured, so there is nowhere to put the row. Set them under Retention settings.',
			      ),
			    ),
			  ),
			  'rows' => array (
			  ),
			),
			$this->caseUnconfigured()
		);
	}

	/**
	 * Golden: refusals.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$this->assertSame(
			array (
			  'result' => array (
			    'created' => 1,
			    'updated' => 0,
			    'unchanged' => 0,
			    'kept' => 0,
			    'failed' => array (
			      0 => array (
			        'category' => '',
			        'reason' => 'An entry is an object with a category, an action and retentionYears.',
			      ),
			      1 => array (
			        'category' => '',
			        'reason' => 'An entry needs a category.',
			      ),
			      2 => array (
			        'category' => '',
			        'reason' => 'An entry needs a category.',
			      ),
			      3 => array (
			        'category' => 'a',
			        'reason' => 'The action must be one of: retain_permanently, bewaren, blijvend_bewaren, destroy, vernietigen, anonymise, anonymize, anonymiseren, not_yet_determined, nog_niet_bepaald.',
			      ),
			      4 => array (
			        'category' => 'b',
			        'reason' => 'retentionYears must be a whole number of 0 or more (it may be left out only when the record is kept permanently).',
			      ),
			      5 => array (
			        'category' => 'c',
			        'reason' => 'retentionYears must be a whole number of 0 or more (it may be left out only when the record is kept permanently).',
			      ),
			      6 => array (
			        'category' => 'd',
			        'reason' => 'retentionYears must be a whole number of 0 or more (it may be left out only when the record is kept permanently).',
			      ),
			      7 => array (
			        'category' => 'e',
			        'reason' => 'retentionYears must be a whole number of 0 or more (it may be left out only when the record is kept permanently).',
			      ),
			      8 => array (
			        'category' => 'f',
			        'reason' => 'retentionYears must be a whole number of 0 or more (it may be left out only when the record is kept permanently).',
			      ),
			      9 => array (
			        'category' => 'g',
			        'reason' => 'retentionYears must be a whole number of 0 or more (it may be left out only when the record is kept permanently).',
			      ),
			    ),
			  ),
			  'rows' => array (
			    'row-1' => array (
			      'id' => 'row-1',
			      'categorie' => '7',
			      'archiefnominatie' => 'destroy',
			      'bewaartermijn' => 'P0Y',
			      'omschrijving' => NULL,
			      'organisatie' => NULL,
			      'bron' => 'app:app1',
			    ),
			  ),
			),
			$this->caseRefusals()
		);
	}

	/**
	 * Golden: createdShapes.
	 *
	 * @return void
	 */
	public function testCreatedShapes(): void {
		$this->assertSame(
			array (
			  'result' => array (
			    'created' => 3,
			    'updated' => 0,
			    'unchanged' => 0,
			    'kept' => 0,
			    'failed' => array (
			    ),
			  ),
			  'rows' => array (
			    'row-1' => array (
			      'id' => 'row-1',
			      'categorie' => 'h.1',
			      'archiefnominatie' => 'bewaren',
			      'bewaartermijn' => NULL,
			      'omschrijving' => 'Besluiten',
			      'organisatie' => NULL,
			      'bron' => NULL,
			      'selectielijstVersie' => '2020',
			    ),
			    'row-2' => array (
			      'id' => 'row-2',
			      'categorie' => 'h.2',
			      'archiefnominatie' => 'vernietigen',
			      'bewaartermijn' => 'P7Y',
			      'omschrijving' => '1',
			      'organisatie' => '12',
			      'bron' => 'Selectielijst 2020',
			    ),
			    'row-3' => array (
			      'id' => 'row-3',
			      'categorie' => 'h.3',
			      'archiefnominatie' => 'anonymise',
			      'bewaartermijn' => 'P3Y',
			      'omschrijving' => NULL,
			      'organisatie' => NULL,
			      'bron' => NULL,
			      'selectielijstVersie' => '2',
			    ),
			  ),
			),
			$this->caseCreatedShapes()
		);
	}

	/**
	 * Golden: createdWithApp.
	 *
	 * @return void
	 */
	public function testCreatedWithApp(): void {
		$this->assertSame(
			array (
			  'result' => array (
			    'created' => 1,
			    'updated' => 0,
			    'unchanged' => 1,
			    'kept' => 0,
			    'failed' => array (
			    ),
			  ),
			  'rows' => array (
			    'row-1' => array (
			      'id' => 'row-1',
			      'categorie' => 'k',
			      'archiefnominatie' => 'bewaren',
			      'bewaartermijn' => NULL,
			      'omschrijving' => NULL,
			      'organisatie' => NULL,
			      'bron' => 'app:decidiq',
			    ),
			  ),
			),
			$this->caseCreatedWithApp()
		);
	}

	/**
	 * Golden: existingOutcomes.
	 *
	 * @return void
	 */
	public function testExistingOutcomes(): void {
		$this->assertSame(
			array (
			  'result' => array (
			    'created' => 1,
			    'updated' => 1,
			    'unchanged' => 1,
			    'kept' => 1,
			    'failed' => array (
			      0 => array (
			        'category' => 'boom',
			        'reason' => 'The row could not be stored; see the log.',
			      ),
			      1 => array (
			        'category' => 'boom',
			        'reason' => 'The row could not be stored; see the log.',
			      ),
			    ),
			  ),
			  'rows' => array (
			    'u1' => array (
			      'id' => 'u1',
			      'categorie' => 'same',
			      'archiefnominatie' => 'bewaren',
			      'bewaartermijn' => NULL,
			      'omschrijving' => NULL,
			      'organisatie' => NULL,
			      'bron' => 'app:app1',
			    ),
			    'u2' => array (
			      'id' => 'u2',
			      'categorie' => 'changed',
			      'archiefnominatie' => 'vernietigen',
			      'bron' => 'app:app1',
			      'extra' => 'kept',
			      'bewaartermijn' => 'P2Y',
			      'omschrijving' => NULL,
			      'organisatie' => NULL,
			    ),
			    'u3' => array (
			      'id' => 'u3',
			      'categorie' => 'theirs',
			      'archiefnominatie' => 'bewaren',
			      'bron' => 'archivist',
			    ),
			    'u4' => array (
			      'id' => 'u4',
			      'categorie' => 'boom',
			      'bron' => 'app:app1',
			    ),
			    'row-5' => array (
			      'id' => 'row-5',
			      'categorie' => 'same',
			      'archiefnominatie' => 'bewaren',
			      'bewaartermijn' => NULL,
			      'omschrijving' => NULL,
			      'organisatie' => NULL,
			      'bron' => 'app:app1',
			      'selectielijstVersie' => 'v2',
			    ),
			  ),
			),
			$this->caseExistingOutcomes()
		);
	}

	private function caseUnconfigured(): mixed {
		return $this->seedOnce($this->seeder(["selectielijstRegister" => 4]), ["2.1" => ["action" => "bewaren"], ["category" => " 3.1 "], "x", ["category" => ["no"]]]);
	}

	private function caseRefusals(): mixed {
		return $this->seedOnce($this->seeder(), ["not an object", ["action" => "bewaren"], ["category" => "  "], ["category" => "a", "action" => "verbranden", "retentionYears" => 1], ["category" => "b", "action" => "vernietigen"], ["category" => "c", "action" => "vernietigen", "retentionYears" => -1], ["category" => "d", "action" => "vernietigen", "retentionYears" => 1.5], ["category" => "e", "action" => "vernietigen", "retentionYears" => "abc"], ["category" => "f", "action" => "vernietigen", "retentionYears" => true], ["category" => "g", "action" => " vernietigen ", "retentionYears" => ""], 20 => ["category" => 7, "action" => "destroy", "retentionYears" => "0"]]);
	}

	private function caseCreatedShapes(): mixed {
		return $this->seedOnce($this->seeder(), ["h.1" => ["action" => "bewaren", "description" => " Besluiten ", "organisation" => "", "version" => " 2020 "], ["category" => "h.2", "action" => "vernietigen", "retentionYears" => 7, "source" => "Selectielijst 2020", "organisation" => 12, "description" => true], ["category" => "h.3", "action" => "anonymise", "retentionYears" => "3", "version" => 2]], null);
	}

	private function caseCreatedWithApp(): mixed {
		return $this->seedOnce($this->seeder(), [["category" => "k", "action" => "bewaren"], ["category" => "k", "action" => "bewaren"]], "decidiq");
	}

	private function caseExistingOutcomes(): mixed {
		$this->store("u1", ["categorie" => "same", "archiefnominatie" => "bewaren", "bewaartermijn" => null, "omschrijving" => null, "organisatie" => null, "bron" => "app:app1"]); $this->store("u2", ["categorie" => "changed", "archiefnominatie" => "bewaren", "bron" => "app:app1", "extra" => "kept"]); $this->store("u3", ["categorie" => "theirs", "archiefnominatie" => "bewaren", "bron" => "archivist"]); $this->store("u4", ["categorie" => "boom", "bron" => "app:app1"]); return $this->seedOnce($this->seeder(), [["category" => "same", "action" => "bewaren"], ["category" => "changed", "action" => "vernietigen", "retentionYears" => 2], ["category" => "theirs", "action" => "vernietigen", "retentionYears" => 2], ["category" => "boom", "action" => "bewaren"], ["category" => "boom", "action" => "bewaren", "organisation" => "o"], ["category" => "same", "action" => "bewaren", "version" => "v2"]]);
	}
}
