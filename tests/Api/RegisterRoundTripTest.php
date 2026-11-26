<?php

/**
 * A register moves as one unit: export it with its records, import it where it is gone.
 *
 * Over HTTP as the administrator: a register with two schemas and three
 * records is exported through `registers#export` (configuration format, with
 * its records), then the register, its schemas and its records are deleted,
 * and the exported file is imported through `registers#import`. The register
 * must come back with both schemas linked and every record in the schema it
 * was exported from.
 *
 * Needs a Nextcloud with OpenRegister: set NEXTCLOUD_URL (or BASE_URL) to its
 * address, and ADMIN_USER / ADMIN_PASSWORD when they are not admin/admin.
 * Without a URL the test is skipped, never pointed at a default instance.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Api
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-rows-closing-round/specs/data-import-export/spec.md#requirement-a-register-groups-record-types-and-moves-as-one-unit
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Api;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class RegisterRoundTripTest extends TestCase {

	/**
	 * The records the register is exported with: name => schema base slug.
	 *
	 * @var array<string, string>
	 */
	private const RECORDS = [
		'Losliggende stoeptegel' => 'melding',
		'Kapotte lantaarnpaal' => 'melding',
		'Bea Jansen' => 'melder',
	];

	/**
	 * The instance's address.
	 *
	 * @var string
	 */
	private string $baseUrl = '';

	/**
	 * A suffix that keeps this run's register and schemas apart from any other run's.
	 *
	 * @var string
	 */
	private string $run = '';

	protected function setUp(): void {
		$url = (getenv('NEXTCLOUD_URL') ?: getenv('BASE_URL'));
		if (is_string($url) === false || $url === '') {
			$this->markTestSkipped('Set NEXTCLOUD_URL (or BASE_URL) to a Nextcloud with OpenRegister to run the register round-trip API test.');
		}

		$this->baseUrl = rtrim($url, '/');
		$this->run = substr(bin2hex(random_bytes(4)), 0, 8);
	}//end setUp()

	protected function tearDown(): void {
		if ($this->baseUrl !== '') {
			$this->removeRegister();
		}
	}//end tearDown()

	public function testARegisterWithTwoSchemasAndItsRecordsComesBackFromItsExport(): void {
		$registerId = $this->createRegister();

		$export = $this->request(
			method: 'GET',
			path: '/registers/' . $registerId . '/export',
			options: ['query' => ['format' => 'configuration', 'includeObjects' => 'true']]
		);
		$this->assertSame(200, $export['status'], $export['raw']);
		$bundle = $export['body'];
		$this->assertSame([$this->slug(base: 'melding'), $this->slug(base: 'melder')], array_keys($bundle['components']['schemas']));
		$this->assertCount(3, $bundle['components']['objects']);

		$this->removeRegister();
		$gone = $this->request(method: 'GET', path: '/objects/' . $this->slug(base: 'meldingen') . '/' . $this->slug(base: 'melding'));
		$this->assertGreaterThanOrEqual(400, $gone['status'], 'the register should be gone before the import');

		$import = $this->request(
			method: 'POST',
			path: '/registers/' . $this->slug(base: 'meldingen') . '/import',
			options: [
				'multipart' => [
					['name' => 'file', 'contents' => $export['raw'], 'filename' => 'meldingen.json', 'headers' => ['Content-Type' => 'application/json']],
				],
			]
		);
		$this->assertSame(200, $import['status'], $import['raw']);
		$summary = $import['body']['summary'];
		foreach (['melding' => 2, 'melder' => 1] as $base => $count) {
			$part = $summary[$this->slug(base: $base)];
			$this->assertSame([], $part['errors'], $import['raw']);
			$this->assertCount($count, array_merge($part['created'], $part['updated']), $import['raw']);
		}

		$this->assertArrayNotHasKey('unassigned', $summary);

		$register = $this->findRegister();
		$this->assertNotNull($register, 'the import should have created the register');
		$schemaIds = array_map('intval', $register['schemas']);
		sort($schemaIds);
		$this->assertSame($this->schemaIds(), $schemaIds, 'the register links both schemas, by their ids here');

		foreach (['melding', 'melder'] as $base) {
			$listed = $this->request(method: 'GET', path: '/objects/' . $this->slug(base: 'meldingen') . '/' . $this->slug(base: $base));
			$this->assertSame(200, $listed['status'], $listed['raw']);
			$names = array_column($listed['body']['results'], 'naam');
			sort($names);
			$expected = array_keys(array_filter(self::RECORDS, static fn (string $schema): bool => $schema === $base));
			sort($expected);
			$this->assertSame($expected, $names, $base);
		}
	}//end testARegisterWithTwoSchemasAndItsRecordsComesBackFromItsExport()

	/**
	 * Create the register, its two schemas and its records.
	 *
	 * @return int The register's id.
	 */
	private function createRegister(): int {
		$ids = [];
		foreach (['melding', 'melder'] as $base) {
			$schema = $this->request(
				method: 'POST',
				path: '/schemas',
				options: ['json' => ['title' => $this->slug(base: $base), 'slug' => $this->slug(base: $base), 'properties' => ['naam' => ['type' => 'string']]]]
			);
			$this->assertLessThan(300, $schema['status'], $schema['raw']);
			$ids[] = (int) $schema['body']['id'];
		}

		$register = $this->request(
			method: 'POST',
			path: '/registers',
			options: ['json' => ['title' => $this->slug(base: 'meldingen'), 'slug' => $this->slug(base: 'meldingen'), 'schemas' => $ids]]
		);
		$this->assertLessThan(300, $register['status'], $register['raw']);

		foreach (self::RECORDS as $name => $base) {
			$record = $this->request(
				method: 'POST',
				path: '/objects/' . $this->slug(base: 'meldingen') . '/' . $this->slug(base: $base),
				options: ['json' => ['naam' => $name]]
			);
			$this->assertLessThan(300, $record['status'], $record['raw']);
		}

		return (int) $register['body']['id'];
	}//end createRegister()

	/**
	 * Delete this run's register, its records and its schemas, whatever is left of them.
	 *
	 * @return void
	 */
	private function removeRegister(): void {
		foreach (['melding', 'melder'] as $base) {
			$listed = $this->request(method: 'GET', path: '/objects/' . $this->slug(base: 'meldingen') . '/' . $this->slug(base: $base));
			foreach (($listed['body']['results'] ?? []) as $record) {
				$this->request(method: 'DELETE', path: '/objects/' . $this->slug(base: 'meldingen') . '/' . $this->slug(base: $base) . '/' . $record['@self']['id']);
			}
		}

		$register = $this->findRegister();
		if ($register !== null) {
			$this->request(method: 'DELETE', path: '/registers/' . $register['id']);
		}

		foreach ($this->schemaIds() as $id) {
			$this->request(method: 'DELETE', path: '/schemas/' . $id);
		}
	}//end removeRegister()

	/**
	 * This run's register, or null when there is none.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findRegister(): ?array {
		$found = $this->request(method: 'GET', path: '/registers', options: ['query' => ['_search' => $this->slug(base: 'meldingen')]]);
		foreach (($found['body']['results'] ?? []) as $register) {
			if (($register['slug'] ?? null) === $this->slug(base: 'meldingen')) {
				return $register;
			}
		}

		return null;
	}//end findRegister()

	/**
	 * The ids of this run's schemas that exist now, ascending.
	 *
	 * @return list<int>
	 */
	private function schemaIds(): array {
		$ids = [];
		foreach (['melding', 'melder'] as $base) {
			$found = $this->request(method: 'GET', path: '/schemas', options: ['query' => ['_search' => $this->slug(base: $base)]]);
			foreach (($found['body']['results'] ?? []) as $schema) {
				if (($schema['slug'] ?? null) === $this->slug(base: $base)) {
					$ids[] = (int) $schema['id'];
				}
			}
		}

		sort($ids);
		return $ids;
	}//end schemaIds()

	/**
	 * One API call as the administrator.
	 *
	 * @param string               $method  The HTTP method.
	 * @param string               $path    The path under the OpenRegister API.
	 * @param array<string, mixed> $options Guzzle request options.
	 *
	 * @return array{status: int, body: mixed, raw: string}
	 */
	private function request(string $method, string $path, array $options = []): array {
		$options['auth'] = [(getenv('ADMIN_USER') ?: 'admin'), (getenv('ADMIN_PASSWORD') ?: 'admin')];
		$options['headers'] = ['OCS-APIRequest' => 'true', 'Accept' => 'application/json'];

		$response = (new Client(['http_errors' => false]))->request($method, $this->baseUrl . '/index.php/apps/openregister/api' . $path, $options);
		$raw = (string) $response->getBody();

		return ['status' => $response->getStatusCode(), 'body' => json_decode($raw, true), 'raw' => $raw];
	}//end request()

	/**
	 * A register or schema slug unique to this run.
	 *
	 * @param string $base The base slug.
	 *
	 * @return string
	 */
	private function slug(string $base): string {
		return 'rt-' . $base . '-' . $this->run;
	}//end slug()
}//end class
