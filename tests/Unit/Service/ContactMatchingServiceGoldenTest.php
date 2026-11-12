<?php

/**
 * Golden test for ContactMatchingService matching.
 *
 * Records matchContact, matchByEmail, matchByName and matchByOrganization results,
 * the searches and the cache traffic over a fixed object set, per scenario, in
 * fixtures/contact-matching-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ContactDetails;
use OCA\OpenRegister\Service\ContactMatchingMappers;
use OCA\OpenRegister\Service\ContactMatchingService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ContactMatchingService
 * @covers \OCA\OpenRegister\Service\ContactMatchQuery
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 */
class ContactMatchingServiceGoldenTest extends TestCase {

	/**
	 * The object set every search returns.
	 *
	 * @return array<int, mixed>
	 */
	private static function objects(): array {
		return [
			['@self' => ['uuid' => 'u1', 'schema' => 1, 'register' => 1], 'email' => 'Jan@Example.nl', 'naam' => 'Jan de Vries', 'title' => 'Jan'],
			['@self' => ['uuid' => 'u2', 'schema' => 2, 'register' => 9], 'emailadres' => 'other@example.nl', 'voornaam' => 'Jan', 'achternaam' => 'Jansen', '_x' => 1, 'n' => ['nested']],
			['@self' => ['uuid' => 'u3', 'schema' => 3, 'register' => 1], 'schema' => ['title' => 'Organisaties'], 'name' => 'Acme BV', 'company' => 'Acme'],
			['@self' => ['uuid' => 'u4'], 'schema' => ['name' => 'Bedrijven'], 'bedrijf' => 'Vries Holding', 'naam' => 'Vries Holding'],
			['uuid' => 'u5', 'schema' => ['title' => 'Personen'], 'organisation' => 'Acme', 'mail' => 'jan@example.nl', 'count' => 3],
			'not an array',
			['@self' => ['uuid' => 'u6', 'schema' => 5, 'register' => 5], 'fullname' => 'J', 'e-mail' => ''],
			['@self' => ['uuid' => 'u2'], 'naam' => 'Jan Jansen bis', 'title' => 'Duplicate of u2'],
		];
	}

	/**
	 * Scenarios: [call, args, cache mode (none|miss|hit|bad), search mode (ok|throws|scalar), linked].
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		return [
			'contact all three' => ['matchContact', ['JAN@example.nl ', 'Jan de Vries', 'Acme'], 'miss', 'ok', true],
			'contact email only' => ['matchContact', ['jan@example.nl'], 'none', 'ok', true],
			'contact name partial' => ['matchContact', ['', 'Jan Pietersen', null], 'none', 'ok', true],
			'contact org substring' => ['matchContact', ['x@y.z', '', 'Vries'], 'none', 'ok', true],
			'email cached' => ['matchByEmail', ['jan@example.nl'], 'hit', 'ok', true],
			'email bad cache' => ['matchByEmail', ['jan@example.nl'], 'bad', 'ok', true],
			'email not linked' => ['matchByEmail', ['jan@example.nl'], 'miss', 'ok', false],
			'email search throws' => ['matchByEmail', ['jan@example.nl'], 'miss', 'throws', true],
			'email search scalar' => ['matchByEmail', ['jan@example.nl'], 'miss', 'scalar', true],
			'name cached' => ['matchByName', ['Jan'], 'hit', 'ok', true],
			'name single letters' => ['matchByName', ['J x Vries'], 'miss', 'ok', true],
			'name throws' => ['matchByName', ['Jan'], 'none', 'throws', true],
			'name empty' => ['matchByName', [''], 'miss', 'ok', true],
			'org cached' => ['matchByOrganization', ['Acme'], 'hit', 'ok', true],
			'org miss' => ['matchByOrganization', ['acme'], 'miss', 'ok', true],
			'org longer term' => ['matchByOrganization', ['Acme BV Amsterdam'], 'none', 'ok', true],
			'org throws' => ['matchByOrganization', ['Acme'], 'miss', 'throws', true],
			'org scalar' => ['matchByOrganization', ['Acme'], 'miss', 'scalar', true],
			'org empty' => ['matchByOrganization', [null], 'miss', 'ok', true],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testMatchingGolden(string $call, array $args, string $cacheMode, string $searchMode, bool $linked): void {
		$log = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (...$a) use (&$log, $searchMode) {
				$log[] = ['search', $a[0]];
				if ($searchMode === 'throws') {
					throw new RuntimeException('index down');
				}

				return $searchMode === 'scalar' ? 0 : self::objects();
			}
		);

		$linkedSchema = $this->createMock(Schema::class);
		$linkedSchema->method('getLinkedTypes')->willReturn($linked === true ? ['contact'] : []);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findAll')->willReturn([$linkedSchema]);
		$schemas->method('find')->willReturnCallback(
			function ($id) {
				if ($id === 2) {
					throw new Exception('gone');
				}

				$schema = new Schema();
				$schema->setTitle($id === 1 ? 'Personen' : null);
				return $schema;
			}
		);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			function ($id) {
				if ($id === 9) {
					throw new Exception('gone');
				}

				$register = new Register();
				$register->setTitle($id === 5 ? null : 'Register ' . $id);
				return $register;
			}
		);

		$factory = $this->createMock(ICacheFactory::class);
		if ($cacheMode === 'none') {
			$factory->method('createDistributed')->willThrowException(new Exception('no cache'));
		} else {
			$cache = $this->createMock(ICache::class);
			$cache->method('get')->willReturnCallback(
				function ($key) use (&$log, $cacheMode) {
					$log[] = ['cache.get', $key];
					if ($cacheMode === 'hit') {
						return json_encode([['uuid' => 'c1', 'confidence' => 0.9]]);
					}

					return $cacheMode === 'bad' ? '"x"' : null;
				}
			);
			$cache->method('set')->willReturnCallback(
				function ($key, $value, $ttl) use (&$log) {
					$log[] = ['cache.set', $key, json_decode($value, true), $ttl];
					return true;
				}
			);
			$factory->method('createDistributed')->willReturn($cache);
		}

		$service = new ContactMatchingService($objects, new ContactMatchingMappers($schemas, $registers), $factory, $this->createMock(LoggerInterface::class));
		if ($call === 'matchContact') {
			$args = [new ContactDetails(...$args)];
		}

		$actual = ['result' => $service->$call(...$args), 'log' => $log];
		$actual = json_decode(json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);

		$file = __DIR__ . '/fixtures/contact-matching-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$this->assertSame($all[$name], $actual);
	}
}
