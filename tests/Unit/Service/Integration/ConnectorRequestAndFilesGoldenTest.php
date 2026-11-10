<?php

/**
 * Characterisation tests for ConnectorRequestTrait (endpoint joining and body
 * options) and FilesProvider::normalize() (node rows).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Service\Integration\BuiltinProviders\FilesProvider;
use OCA\OpenRegister\Service\Integration\ConnectorRequestTrait;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins connector request shaping and file rows before their units are split.
 */
class ConnectorRequestAndFilesGoldenTest extends TestCase {

	/**
	 * A host for the trait's private methods.
	 *
	 * @return object
	 */
	private function host(): object {
		return new class {
			use ConnectorRequestTrait;

			public function endpoint(mixed $source, string $path): string {
				return $this->connectorEndpoint(source: $source, path: $path);
			}//end endpoint()

			public function options(array $options): array {
				return $this->connectorOptions(options: $options);
			}//end options()
		};
	}//end host()

	public function testEndpointJoinsOnTheSourceLocation(): void {
		$host = $this->host();

		$entity = new class {
			public function getObject(): array {
				return ['location' => 'https://api.example/v1/'];
			}//end getObject()

			public function getLocation(): string {
				return 'ignored/';
			}//end getLocation()
		};
		$source = new class {
			public function getLocation(): string {
				return 'https://api.example/v1';
			}//end getLocation()
		};

		$this->assertSame('', $host->endpoint(source: [], path: ''));
		$this->assertSame('///', $host->endpoint(source: ['location' => 'x/'], path: '///'));
		$this->assertSame('people', $host->endpoint(source: $entity, path: '/people'));
		$this->assertSame('/people', $host->endpoint(source: $source, path: 'people'));
		$this->assertSame('people', $host->endpoint(source: ['location' => 'https://a/'], path: '//people'));
		$this->assertSame('/people', $host->endpoint(source: ['location' => null], path: 'people'));
		$this->assertSame('/people', $host->endpoint(source: 'not-a-source', path: 'people'));
		$this->assertSame('/people', $host->endpoint(source: new \stdClass(), path: 'people'));
	}//end testEndpointJoinsOnTheSourceLocation()

	public function testArrayBodiesBecomeFormParamsOrJson(): void {
		$host = $this->host();

		$this->assertSame(['body' => 'raw', 'query' => ['a' => 1]], $host->options(options: ['body' => 'raw', 'query' => ['a' => 1]]));
		$this->assertSame(['query' => []], $host->options(options: ['query' => []]));
		$this->assertSame(['body' => null], $host->options(options: ['body' => null]));
		$this->assertSame(
			['headers' => ['Content-Type' => 'Application/X-WWW-Form-Urlencoded; charset=utf-8'], 'form_params' => ['a' => 1]],
			$host->options(options: ['body' => ['a' => 1], 'headers' => ['Content-Type' => 'Application/X-WWW-Form-Urlencoded; charset=utf-8']])
		);
		$this->assertSame(
			['headers' => ['content-type' => 'application/x-www-form-urlencoded', 'CONTENT-TYPE' => 'text/plain'], 'json' => ['a' => 1]],
			$host->options(
				options: ['body' => ['a' => 1], 'headers' => ['content-type' => 'application/x-www-form-urlencoded', 'CONTENT-TYPE' => 'text/plain']]
			)
		);
		$this->assertSame(['json' => []], $host->options(options: ['body' => []]));
	}//end testArrayBodiesBecomeFormParamsOrJson()

	public function testNormalizeKeepsArraysAndReadsNodeAccessors(): void {
		$provider = (new ReflectionClass(FilesProvider::class))->newInstanceWithoutConstructor();
		$method   = new ReflectionMethod(FilesProvider::class, 'normalize');

		$node = new class {
			public function getId(): int {
				return 7;
			}//end getId()

			public function getName(): string {
				return 'a.pdf';
			}//end getName()

			public function getPath(): string {
				throw new RuntimeException('gone');
			}//end getPath()

			public function getSize(): int {
				return 12;
			}//end getSize()
		};

		$this->assertSame([], $method->invoke($provider, 'nope'));
		$this->assertSame(
			[['id' => 1], ['id' => 7, 'name' => 'a.pdf', 'size' => 12], []],
			$method->invoke($provider, [['id' => 1], $node, 'skip', 5, new \stdClass()])
		);
	}//end testNormalizeKeepsArraysAndReadsNodeAccessors()
}//end class
