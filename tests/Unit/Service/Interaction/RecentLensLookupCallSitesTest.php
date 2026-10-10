<?php

/**
 * Which internal reads count as a person opening an object, asserted from the callers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Interaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/recently-opened-means-opened/specs/object-interactions/spec.md#requirement-only-a-person-opening-an-object-counts-as-recently-opened
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Interaction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DsarService;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WriteCause;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The cause `ObjectService::find()` sees, from the code that calls it.
 *
 * `find()` writes the audit `read` row, and the row takes its cause from the
 * ambient frame. So the cause the mocked `find()` observes is the cause the
 * row would carry, and only `person` lands in the recent list.
 */
class RecentLensLookupCallSitesTest extends TestCase {

	/**
	 * Calls that ARE a person opening the object: the response is the object.
	 *
	 * Keyed by file, valued by the enclosing method. Every other
	 * `ObjectService::find()` in lib/ must be a lookup or skip the audit row.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const OPENED = [
		'lib/Controller/ObjectsController.php' => ['show'],
		'lib/Controller/TmloController.php' => ['exportSingle'],
		'lib/Controller/ObjectShareLinkController.php' => ['show'],
		'lib/Service/CaseTokenService.php' => ['resolve'],
		'lib/Service/ObjectServiceMapperAdapter.php' => ['find'],
	];

	/**
	 * Calls on a receiver that is not ObjectService (no audit row).
	 *
	 * @var array<string, array<int, string>>
	 */
	private const NOT_OBJECT_SERVICE = [
		'lib/Controller/ContentReportController.php' => ['create'],
		'lib/Service/Portal/PortalPartyResolver.php' => ['resolveFromObject'],
	];

	/**
	 * Start every test from a person acting directly.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		WriteCause::reset();
	}//end setUp()

	/**
	 * The relations tab's read of the object is a lookup.
	 *
	 * @return void
	 */
	public function testTheRelationsTabReadIsALookup(): void {
		$seen = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function () use (&$seen): ObjectEntity {
				$seen[] = WriteCause::current()['cause'];
				return new ObjectEntity();
			}
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		$access = new ObjectReadAccess(
			objectService: $objects,
			userSession: $session,
			schemaMapper: $this->createMock(SchemaMapper::class)
		);
		$access->readable(register: 'zaken', schema: 'zaak', id: 'a');

		$this->assertSame([WriteCause::LOOKUP], $seen);
		$this->assertSame(WriteCause::PERSON, WriteCause::current()['cause'], 'the frame closes after the read');
	}//end testTheRelationsTabReadIsALookup()

	/**
	 * A DSAR case loaded by a service is a lookup; inside a scheduled sweep
	 * it keeps the sweep's cause.
	 *
	 * @return void
	 */
	public function testACaseLoadIsALookupAndKeepsAScheduledCause(): void {
		$seen = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function () use (&$seen): ObjectEntity {
				$seen[] = WriteCause::current()['cause'];
				return new ObjectEntity();
			}
		);

		$accessor = new CaseObjectAccessor(
			objectService: $objects,
			dsarService: $this->createMock(DsarService::class)
		);
		$accessor->load(caseUuid: 'case-1');
		WriteCause::runAs(
			cause: WriteCause::SCHEDULED,
			run: 'sweep-1',
			operation: static fn () => $accessor->load(caseUuid: 'case-1')
		);

		$this->assertSame([WriteCause::LOOKUP, WriteCause::SCHEDULED], $seen);
	}//end testACaseLoadIsALookupAndKeepsAScheduledCause()

	/**
	 * Every `ObjectService::find()` in lib/ has a decision.
	 *
	 * Either it is a lookup (`WriteCause::asLookup`), or it writes no audit
	 * row (`_audit: false`), or it is one of the listed opens. A new internal
	 * read that is none of these would put objects in people's recent lists
	 * without anyone deciding it should, so it fails here.
	 *
	 * @return void
	 */
	public function testEveryInternalFindHasADecision(): void {
		$root = dirname(__DIR__, 4);
		$undecided = [];
		$counted = 0;

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/lib'));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$relative = substr($file->getPathname(), strlen($root) + 1);
			$source = (string) file_get_contents($file->getPathname());
			$pattern = '/(\$this->objectService|\$objectService|\$this->objects)->find\(/';
			if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
				continue;
			}

			foreach ($matches[0] as [$text, $offset]) {
				$counted++;
				$call = $this->callAt(source: $source, open: $offset + strlen($text) - 1);
				$method = $this->enclosingMethod(source: $source, offset: $offset);
				$prefix = substr($source, max(0, $offset - 40), min(40, $offset));

				if (str_contains($prefix, 'WriteCause::asLookup(fn () => ') === true
					|| preg_match('/_audit:\s*false/', $call) === 1
					|| in_array($method, (self::OPENED[$relative] ?? []), true) === true
					|| in_array($method, (self::NOT_OBJECT_SERVICE[$relative] ?? []), true) === true
				) {
					continue;
				}

				$undecided[] = $relative.'::'.$method;
			}//end foreach
		}//end foreach

		$this->assertGreaterThan(70, $counted, 'the scan must actually see the call sites');
		$this->assertSame([], $undecided, 'decide each: lookup, `_audit: false`, or a person opening the object');
	}//end testEveryInternalFindHasADecision()

	/**
	 * The argument list of the call whose `(` is at `$open`.
	 *
	 * @param string $source The file.
	 * @param int    $open   Offset of the opening parenthesis.
	 *
	 * @return string The call's arguments, parentheses included.
	 */
	private function callAt(string $source, int $open): string {
		$depth = 0;
		$length = strlen($source);
		for ($i = $open; $i < $length; $i++) {
			if ($source[$i] === '(') {
				$depth++;
			}

			if ($source[$i] === ')') {
				$depth--;
				if ($depth === 0) {
					return substr($source, $open, $i - $open + 1);
				}
			}
		}

		return substr($source, $open);
	}//end callAt()

	/**
	 * The name of the function the offset sits in.
	 *
	 * @param string $source The file.
	 * @param int    $offset The offset.
	 *
	 * @return string The function name, or '' when none precedes it.
	 */
	private function enclosingMethod(string $source, int $offset): string {
		if (preg_match_all('/function\s+(\w+)\s*\(/', substr($source, 0, $offset), $names) === 0) {
			return '';
		}

		return (string) end($names[1]);
	}//end enclosingMethod()
}//end class
