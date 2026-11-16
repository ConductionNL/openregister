<?php

/**
 * Unit tests for EmbeddingProbe: the model it names and the custom config it hands on.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization;

use OCA\OpenRegister\Search\Service\Vectorization\EmbeddingProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A probe reads the settings screen's keys and falls back where they are missing.
 */
#[CoversClass(EmbeddingProbe::class)]
final class EmbeddingProbeTest extends TestCase {

	public function testAFullConfigIsHandedOnUnderTheGeneratorKeys(): void {
		$probe = new EmbeddingProbe(
			provider: 'fireworks',
			config: ['model' => 'thenlper/gte-base', 'apiKey' => 'fw-key', 'baseUrl' => 'https://fw.test/v1', 'url' => 'https://ignored.test'],
			testText: 'Hallo wereld.'
		);

		$this->assertSame('thenlper/gte-base', $probe->model());
		$this->assertSame('Hallo wereld.', $probe->testText);
		$this->assertSame(
			['provider' => 'fireworks', 'model' => 'thenlper/gte-base', 'apiKey' => 'fw-key', 'baseUrl' => 'https://fw.test/v1'],
			$probe->customConfig()
		);
	}//end testAFullConfigIsHandedOnUnderTheGeneratorKeys()

	public function testUrlStandsInForAMissingBaseUrl(): void {
		$probe = new EmbeddingProbe(provider: 'ollama', config: ['model' => 'nomic-embed-text', 'url' => 'http://ollama.test:11434']);

		$this->assertSame('http://ollama.test:11434', $probe->customConfig()['baseUrl']);
		$this->assertNull($probe->customConfig()['apiKey']);
	}//end testUrlStandsInForAMissingBaseUrl()

	public function testAnEmptyConfigNamesNoModelAndPassesNulls(): void {
		$probe = new EmbeddingProbe(provider: 'openai', config: []);

		$this->assertSame('unknown', $probe->model());
		$this->assertSame('Test.', $probe->testText);
		$this->assertSame(
			['provider' => 'openai', 'model' => null, 'apiKey' => null, 'baseUrl' => null],
			$probe->customConfig()
		);
	}//end testAnEmptyConfigNamesNoModelAndPassesNulls()
}//end class
