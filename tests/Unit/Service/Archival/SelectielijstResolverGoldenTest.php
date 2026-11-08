<?php

declare(strict_types=1);

/**
 * Golden test for SelectielijstResolver: pins the exact outcomes (allowed and refused,
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

use Exception;
use OCA\OpenRegister\Service\Archival\SelectielijstResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use Psr\Log\NullLogger;
use RuntimeException;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of SelectielijstResolver.
 */
class SelectielijstResolverGoldenTest extends TestCase {

	/**
	 * A resolver over the given rows and settings; the mapper throws for category `boom`.
	 *
	 * @param array<int, array<string, mixed>> $rows     Row data, in store order.
	 * @param array<string, mixed>             $settings Archival settings.
	 * @param ObjectRetentionHandler|null      $handler  Settings reader replacing the one built from $settings.
	 *
	 * @return SelectielijstResolver The resolver.
	 */
	private function resolver(array $rows, array $settings, ?ObjectRetentionHandler $handler = null): SelectielijstResolver {
		$objectMapper = $this->getMockBuilder(MagicMapper::class)->disableOriginalConstructor()->onlyMethods(['findAll'])->getMock();
		$objectMapper->method('findAll')->willReturnCallback(
			static function (?int $limit = null, ?int $offset = null, ?array $filters = null) use ($rows): array {
				$category = ($filters['object->categorie'] ?? null);
				if ($category === 'boom') {
					throw new Exception('store down');
				}

				$found = [];
				foreach ($rows as $index => $data) {
					if (($data['categorie'] ?? null) === $category) {
						$row = new ObjectEntity();
						$row->setUuid('row-' . $index);
						$row->setObject($data);
						$found[] = $row;
					}
				}

				return $found;
			}
		);
		if ($handler === null) {
			$handler = $this->createMock(originalClassName: ObjectRetentionHandler::class);
			$handler->method('getArchivalSettingsOnly')->willReturn($settings);
		}

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn(new Register());
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn(new Schema());

		return new SelectielijstResolver($objectMapper, $schemaMapper, $registerMapper, $handler, new NullLogger());
	}

	/**
	 * The uuid of the row each category resolves to, or null.
	 *
	 * @param SelectielijstResolver $resolver   The resolver.
	 * @param array<int, string>    $categories The categories.
	 *
	 * @return array<string, mixed> Per category: the uuid and the lookup data.
	 */
	private function lookups(SelectielijstResolver $resolver, array $categories): array {
		$out = [];
		foreach ($categories as $category) {
			$out[$category] = [$resolver->entryFor($category)?->getUuid(), $resolver->lookupSelectielijstEntry($category)];
		}

		return $out;
	}

	/**
	 * Provenance of an entry, with the wall-clock consultation time checked and masked.
	 *
	 * @param array<string, mixed>|null $data    The entry data.
	 * @param int|string|null           $version The entity's own version.
	 *
	 * @return array<string, string> The provenance.
	 */
	private function provenance(?array $data, int|string|null $version): array {
		$entry = new ObjectEntity();
		$entry->setObject($data);
		$entry->setVersion($version === null ? null : (string)$version);
		$provenance = $this->resolver([], [])->provenanceOf($entry);
		$this->assertNotFalse(strtotime($provenance['selectionListConsultedAt']));
		$provenance['selectionListConsultedAt'] = '<now>';
		return $provenance;
	}

	/**
	 * Golden: unconfigured.
	 *
	 * @return void
	 */
	public function testUnconfigured(): void {
		$this->assertSame(
			array (
			  0 => array (
			    '1.1' => array (
			      0 => NULL,
			      1 => NULL,
			    ),
			  ),
			  1 => array (
			    '1.1' => array (
			      0 => NULL,
			      1 => NULL,
			    ),
			  ),
			  2 => array (
			    '1.1' => array (
			      0 => NULL,
			      1 => NULL,
			    ),
			  ),
			),
			$this->caseUnconfigured()
		);
	}

	/**
	 * Golden: noVersionApplied.
	 *
	 * @return void
	 */
	public function testNoVersionApplied(): void {
		$this->assertSame(
			array (
			  0 => array (
			    '1.1' => array (
			      0 => 'row-0',
			      1 => array (
			        'id' => 'row-0',
			        'categorie' => '1.1',
			        'selectielijstVersie' => 'v1',
			        'x' => 1,
			      ),
			    ),
			    '2.2' => array (
			      0 => 'row-4',
			      1 => array (
			        'id' => 'row-4',
			        'categorie' => '2.2',
			        'selectielijstVersie' => 'v1',
			        'versie' => 'v9',
			        'x' => 5,
			      ),
			    ),
			    '3.3' => array (
			      0 => NULL,
			      1 => NULL,
			    ),
			    'boom' => array (
			      0 => NULL,
			      1 => NULL,
			    ),
			  ),
			  1 => array (
			    '1.1' => array (
			      0 => 'row-0',
			      1 => array (
			        'id' => 'row-0',
			        'categorie' => '1.1',
			        'selectielijstVersie' => 'v1',
			        'x' => 1,
			      ),
			    ),
			  ),
			  2 => array (
			    '1.1' => array (
			      0 => 'row-0',
			      1 => array (
			        'id' => 'row-0',
			        'categorie' => '1.1',
			        'selectielijstVersie' => 'v1',
			        'x' => 1,
			      ),
			    ),
			  ),
			),
			$this->caseNoVersionApplied()
		);
	}

	/**
	 * Golden: versionApplied.
	 *
	 * @return void
	 */
	public function testVersionApplied(): void {
		$this->assertSame(
			array (
			  'v1' => array (
			    '1.1' => array (
			      0 => 'row-0',
			      1 => array (
			        'id' => 'row-0',
			        'categorie' => '1.1',
			        'selectielijstVersie' => 'v1',
			        'x' => 1,
			      ),
			    ),
			    '2.2' => array (
			      0 => 'row-4',
			      1 => array (
			        'id' => 'row-4',
			        'categorie' => '2.2',
			        'selectielijstVersie' => 'v1',
			        'versie' => 'v9',
			        'x' => 5,
			      ),
			    ),
			    '3.3' => array (
			      0 => NULL,
			      1 => NULL,
			    ),
			  ),
			  ' v2' => array (
			    '1.1' => array (
			      0 => 'row-1',
			      1 => array (
			        'id' => 'row-1',
			        'categorie' => '1.1',
			        'versie' => ' v2 ',
			        'x' => 2,
			      ),
			    ),
			    '2.2' => array (
			      0 => NULL,
			      1 => NULL,
			    ),
			    '3.3' => array (
			      0 => NULL,
			      1 => NULL,
			    ),
			  ),
			  'v3' => array (
			    '1.1' => array (
			      0 => 'row-2',
			      1 => array (
			        'id' => 'row-2',
			        'categorie' => '1.1',
			        'version' => 'v3',
			        'x' => 3,
			      ),
			    ),
			    '2.2' => array (
			      0 => NULL,
			      1 => NULL,
			    ),
			    '3.3' => array (
			      0 => NULL,
			      1 => NULL,
			    ),
			  ),
			  'v9' => array (
			    '1.1' => array (
			      0 => NULL,
			      1 => NULL,
			    ),
			    '2.2' => array (
			      0 => NULL,
			      1 => NULL,
			    ),
			    '3.3' => array (
			      0 => NULL,
			      1 => NULL,
			    ),
			  ),
			  'v4' => array (
			    '1.1' => array (
			      0 => NULL,
			      1 => NULL,
			    ),
			    '2.2' => array (
			      0 => NULL,
			      1 => NULL,
			    ),
			    '3.3' => array (
			      0 => NULL,
			      1 => NULL,
			    ),
			  ),
			),
			$this->caseVersionApplied()
		);
	}

	/**
	 * Golden: provenance.
	 *
	 * @return void
	 */
	public function testProvenance(): void {
		$this->assertSame(
			array (
			  0 => array (
			    'selectionListConsultedAt' => '<now>',
			    'selectionListVersion' => '2020',
			  ),
			  1 => array (
			    'selectionListConsultedAt' => '<now>',
			    'selectionListVersion' => '3',
			  ),
			  2 => array (
			    'selectionListConsultedAt' => '<now>',
			    'selectionListVersion' => '4',
			  ),
			  3 => array (
			    'selectionListConsultedAt' => '<now>',
			    'selectionListVersion' => 'y',
			  ),
			  4 => array (
			    'selectionListConsultedAt' => '<now>',
			  ),
			  5 => array (
			    'selectionListConsultedAt' => '<now>',
			    'selectionListVersion' => '8',
			  ),
			  6 => array (
			    'selectionListConsultedAt' => '<now>',
			    'selectionListVersion' => '2',
			  ),
			),
			$this->caseProvenance()
		);
	}

	/**
	 * Golden: the settings read made while picking the applicable row fails.
	 *
	 * The lookup reads the archival settings once for the register and schema and
	 * again for the applied version; a failure on the second read resolves to no
	 * entry, as a failed store read does, instead of escaping to the caller.
	 *
	 * @return void
	 */
	public function testSettingsFailureWhilePickingRowResolvesToNull(): void {
		$handler = $this->createMock(originalClassName: ObjectRetentionHandler::class);
		$calls = 0;
		$handler->method('getArchivalSettingsOnly')->willReturnCallback(
			static function () use (&$calls): array {
				$calls++;
				if ($calls > 1) {
					throw new RuntimeException('settings unavailable');
				}

				return ["selectielijstRegister" => 1, "selectielijstSchema" => 2, "selectielijstVersion" => "v1"];
			}
		);
		$resolver = $this->resolver(rows: [["categorie" => "1.1", "selectielijstVersie" => "v1", "x" => 1]], settings: [], handler: $handler);

		$this->assertNull(actual: $resolver->entryFor(category: '1.1'));
		$this->assertSame(expected: 2, actual: $calls);
	}

	private function caseUnconfigured(): mixed {
		return [$this->lookups($this->resolver([["categorie" => "1.1", "selectielijstVersie" => "v1", "x" => 1], ["categorie" => "1.1", "versie" => " v2 ", "x" => 2], ["categorie" => "1.1", "version" => "v3", "x" => 3], ["categorie" => "1.1", "x" => 4], ["categorie" => "2.2", "selectielijstVersie" => "v1", "versie" => "v9", "x" => 5]], []), ["1.1"]), $this->lookups($this->resolver([["categorie" => "1.1", "selectielijstVersie" => "v1", "x" => 1], ["categorie" => "1.1", "versie" => " v2 ", "x" => 2], ["categorie" => "1.1", "version" => "v3", "x" => 3], ["categorie" => "1.1", "x" => 4], ["categorie" => "2.2", "selectielijstVersie" => "v1", "versie" => "v9", "x" => 5]], ["selectielijstRegister" => 1]), ["1.1"]), $this->lookups($this->resolver([["categorie" => "1.1", "selectielijstVersie" => "v1", "x" => 1], ["categorie" => "1.1", "versie" => " v2 ", "x" => 2], ["categorie" => "1.1", "version" => "v3", "x" => 3], ["categorie" => "1.1", "x" => 4], ["categorie" => "2.2", "selectielijstVersie" => "v1", "versie" => "v9", "x" => 5]], ["selectielijstSchema" => 1]), ["1.1"])];
	}

	private function caseNoVersionApplied(): mixed {
		return [$this->lookups($this->resolver([["categorie" => "1.1", "selectielijstVersie" => "v1", "x" => 1], ["categorie" => "1.1", "versie" => " v2 ", "x" => 2], ["categorie" => "1.1", "version" => "v3", "x" => 3], ["categorie" => "1.1", "x" => 4], ["categorie" => "2.2", "selectielijstVersie" => "v1", "versie" => "v9", "x" => 5]], ["selectielijstRegister" => 1, "selectielijstSchema" => 2]), ["1.1", "2.2", "3.3", "boom"]), $this->lookups($this->resolver([["categorie" => "1.1", "selectielijstVersie" => "v1", "x" => 1], ["categorie" => "1.1", "versie" => " v2 ", "x" => 2], ["categorie" => "1.1", "version" => "v3", "x" => 3], ["categorie" => "1.1", "x" => 4], ["categorie" => "2.2", "selectielijstVersie" => "v1", "versie" => "v9", "x" => 5]], ["selectielijstRegister" => "1", "selectielijstSchema" => 2, "selectielijstVersion" => "  "]), ["1.1"]), $this->lookups($this->resolver([["categorie" => "1.1", "selectielijstVersie" => "v1", "x" => 1], ["categorie" => "1.1", "versie" => " v2 ", "x" => 2], ["categorie" => "1.1", "version" => "v3", "x" => 3], ["categorie" => "1.1", "x" => 4], ["categorie" => "2.2", "selectielijstVersie" => "v1", "versie" => "v9", "x" => 5]], ["selectielijstRegister" => 0, "selectielijstSchema" => 2, "selectielijstVersion" => 5]), ["1.1"])];
	}

	private function caseVersionApplied(): mixed {
		$out = []; foreach (["v1", " v2", "v3", "v9", "v4"] as $v) { $out[$v] = $this->lookups($this->resolver([["categorie" => "1.1", "selectielijstVersie" => "v1", "x" => 1], ["categorie" => "1.1", "versie" => " v2 ", "x" => 2], ["categorie" => "1.1", "version" => "v3", "x" => 3], ["categorie" => "1.1", "x" => 4], ["categorie" => "2.2", "selectielijstVersie" => "v1", "versie" => "v9", "x" => 5]], ["selectielijstRegister" => 1, "selectielijstSchema" => 2, "selectielijstVersion" => $v]), ["1.1", "2.2", "3.3"]); } return $out;
	}

	private function caseProvenance(): mixed {
		return [$this->provenance(["versie" => " 2020 "], 7), $this->provenance(["version" => 3], null), $this->provenance(["versie" => "", "version" => "x"], 4), $this->provenance(["versie" => null, "version" => "y"], null), $this->provenance([], null), $this->provenance(null, "8"), $this->provenance(["versie" => 1.5], 2)];
	}
}
