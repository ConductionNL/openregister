<?php

/**
 * The contract opencatalogi's copy path relies on (files-create-from-url, task 2.1).
 *
 * The opencatalogi app (integration-publish-by-reference, "copy") posts
 * `{name, source: {url}, share?, tags?}` to an object's files and reads
 * `metadata.sourceUrl`, `metadata.fetchedAt` and `metadata.sha256` back, or a
 * 422 `{error, reason}`. This pins the route, both shapes and the reasons, so a
 * rename here breaks this test before it breaks them.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/files-create-from-url/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\File\FetchedFile;
use OCA\OpenRegister\Service\File\UrlFetchRefused;
use OCA\OpenRegister\Tests\Unit\Support\DeclaredRoutes;
use PHPUnit\Framework\TestCase;

/**
 * The route, the request body, the success metadata and the failure body.
 *
 * @covers \OCA\OpenRegister\Service\File\FetchedFile
 * @covers \OCA\OpenRegister\Service\File\UrlFetchRefused
 */
class FilesCreateFromUrlContractTest extends TestCase {

	private const OPENAPI = __DIR__ . '/../../../openapi.json';

	public function testTheCreateRouteIsPostOnTheObjectsFiles(): void {
		$create = array_values(array_filter(
			DeclaredRoutes::unversioned(),
			static fn (array $route): bool => $route['name'] === 'files#create'
		));

		$this->assertCount(1, $create);
		$this->assertSame('/api/objects/{register}/{schema}/{id}/files', $create[0]['url']);
		$this->assertSame('POST', $create[0]['verb']);
	}

	public function testTheSuccessCarriesTheProvenanceKeys(): void {
		$fetched = new FetchedFile('bytes', 'https://example.org/besluit.pdf', '2026-10-05T09:30:00+00:00', hash('sha256', 'bytes'));

		$this->assertSame(['sourceUrl', 'fetchedAt', 'sha256'], array_keys($fetched->metadata()));
		$this->assertSame('https://example.org/besluit.pdf', $fetched->metadata()['sourceUrl']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fetched->metadata()['sha256']);
	}

	public function testTheFailureReasonsAreTheFiveTheSpecNames(): void {
		$this->assertSame(
			['refused-address', 'timeout', 'status', 'too-large', 'empty'],
			[UrlFetchRefused::REFUSED_ADDRESS, UrlFetchRefused::TIMEOUT, UrlFetchRefused::STATUS, UrlFetchRefused::TOO_LARGE, UrlFetchRefused::EMPTY]
		);
		$this->assertSame('status', (new UrlFetchRefused(reason: UrlFetchRefused::STATUS, message: 'm'))->reason);
	}

	public function testTheApiDocumentsTheSourceBodyAndThe422(): void {
		$spec = json_decode((string)file_get_contents(self::OPENAPI), true, 512, JSON_THROW_ON_ERROR);

		foreach (['/objects/{register}/{schema}/{id}/files', '/api/objects/{register}/{schema}/{id}/files'] as $path) {
			$post = $spec['paths'][$path]['post'];
			$body = $post['requestBody']['content']['application/json']['schema'];

			$this->assertSame(['name'], $body['required'], $path);
			$this->assertSame(['url'], $body['properties']['source']['required'], $path);
			$this->assertArrayHasKey('content', $body['properties'], $path);

			$failure = $post['responses']['422']['content']['application/problem+json']['schema']['allOf'][1];
			$this->assertSame(['error', 'reason'], $failure['required'], $path);
			$this->assertSame(['refused-address', 'timeout', 'status', 'too-large', 'empty'], $failure['properties']['reason']['enum'], $path);
		}
	}
}
