<?php

declare(strict_types=1);

/**
 * Guard for the API coverage commands in composer.json and the Newman orchestrator they run.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\ApiCoverage
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-test-coverage/tasks.md
 */

namespace OCA\OpenRegister\Tests\Unit\ApiCoverage;

use PHPUnit\Framework\TestCase;

/**
 * `composer api-test` runs the integration suite and every Newman collection.
 * The Newman leg is `tests/newman/run-all.sh`, which runs the domains in
 * DOMAIN_ORDER through its DOMAIN_COLLECTIONS table. A domain without a
 * collection, or a collection path that does not exist or is not a Postman
 * collection, would only surface on a live run; these tests catch it here.
 */
class ApiCoverageCommandsTest extends TestCase {

	/**
	 * Root of the app.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The composer scripts.
	 *
	 * @return array<string,mixed>
	 */
	private static function scripts(): array {
		$composer = json_decode((string) file_get_contents(self::root().'/composer.json'), true);

		return $composer['scripts'];
	}//end scripts()

	/**
	 * The orchestrator's source.
	 *
	 * @return string
	 */
	private static function orchestrator(): string {
		return (string) file_get_contents(self::root().'/tests/newman/run-all.sh');
	}//end orchestrator()

	/**
	 * The domains the orchestrator runs by default, in order.
	 *
	 * @return array<int,string>
	 */
	private static function domainOrder(): array {
		preg_match('/^DOMAIN_ORDER=\((.*?)^\)/ms', self::orchestrator(), $match);
		preg_match_all('/"([a-z0-9-]+)"/', $match[1] ?? '', $domains);

		return $domains[1];
	}//end domainOrder()

	/**
	 * The collection path of each domain, relative to the app root.
	 *
	 * @return array<string,string>
	 */
	private static function domainCollections(): array {
		preg_match_all('/^\s*\[([a-z0-9-]+)\]="\$REPO_ROOT\/([^"]+)"/m', self::orchestrator(), $rows, PREG_SET_ORDER);

		$collections = [];
		foreach ($rows as $row) {
			$collections[$row[1]] = $row[2];
		}

		return $collections;
	}//end domainCollections()

	/**
	 * `api-test` runs the integration suite, then Newman; the Newman scripts run the orchestrator.
	 *
	 * @return void
	 */
	public function testTheApiCoverageCommandsRunTheOrchestrator(): void {
		$scripts = self::scripts();

		$this->assertSame(['@test:api', '@test:newman'], $scripts['api-test']);
		$this->assertSame('bash tests/newman/run-all.sh', $scripts['test:newman']);
		$this->assertSame('FAIL_FAST=1 bash tests/newman/run-all.sh', $scripts['test:newman:fail-fast']);
		$this->assertStringContainsString('--testsuite="Integration Tests"', $scripts['test:api']);
		$this->assertFileExists(self::root().'/tests/newman/run-all.sh');
	}//end testTheApiCoverageCommandsRunTheOrchestrator()

	/**
	 * Every default domain has a collection, and is listed once.
	 *
	 * @return void
	 */
	public function testEveryDefaultDomainHasACollection(): void {
		$order       = self::domainOrder();
		$collections = self::domainCollections();

		$this->assertNotSame([], $order, 'DOMAIN_ORDER could not be read from run-all.sh.');
		$this->assertSame(array_values(array_unique($order)), $order, 'A domain is listed twice in DOMAIN_ORDER.');
		$this->assertSame([], array_values(array_diff($order, array_keys($collections))), 'Domains in DOMAIN_ORDER without a collection.');
	}//end testEveryDefaultDomainHasACollection()

	/**
	 * Every collection the orchestrator names exists and is a Postman v2 collection with requests.
	 *
	 * @return void
	 */
	public function testEveryCollectionIsAPostmanCollectionOnDisk(): void {
		$broken = [];
		foreach (self::domainCollections() as $domain => $path) {
			$file = self::root().'/'.$path;
			if (is_file($file) === false) {
				$broken[] = $domain.': '.$path.' does not exist';
				continue;
			}

			$collection = json_decode((string) file_get_contents($file), true);
			$schema     = (string) ($collection['info']['schema'] ?? '');
			if (str_contains($schema, 'schema.getpostman.com/json/collection/v2') === false || is_array($collection['item'] ?? null) === false || $collection['item'] === []) {
				$broken[] = $domain.': '.$path.' is not a Postman v2 collection with requests';
			}
		}

		$this->assertNotSame([], self::domainCollections(), 'DOMAIN_COLLECTIONS could not be read from run-all.sh.');
		$this->assertSame([], $broken);
	}//end testEveryCollectionIsAPostmanCollectionOnDisk()
}//end class
