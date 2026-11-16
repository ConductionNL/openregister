<?php

/**
 * Unit tests for InstanceAppConfigReader: an app's configuration for an instance set, without secrets.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Import\InstanceAppConfigReader;
use OCA\OpenRegister\Service\Import\InstanceSecretFilter;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(InstanceAppConfigReader::class)]
#[UsesClass(InstanceSecretFilter::class)]
final class InstanceAppConfigReaderTest extends TestCase {

	/**
	 * A reader over the given values, with the given keys kept as sensitive.
	 *
	 * @param array<string, mixed> $values    Stored values.
	 * @param list<string>         $sensitive Keys Nextcloud keeps as sensitive.
	 * @param list<string>         $failing   Keys whose sensitivity cannot be read.
	 *
	 * @return InstanceAppConfigReader
	 */
	private function reader(array $values, array $sensitive=[], array $failing=[]): InstanceAppConfigReader {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())->method('getAllValues')->with('openregister')->willReturn($values);
		$appConfig->method('isSensitive')->willReturnCallback(
			static function (string $app, string $key, ?bool $lazy) use ($sensitive, $failing): bool {
				if (in_array($key, $failing, true) === true) {
					throw new RuntimeException('unknown key');
				}

				return $app === 'openregister' && $lazy === null && in_array($key, $sensitive, true) === true;
			}
		);

		return new InstanceAppConfigReader($appConfig, new InstanceSecretFilter());
	}

	public function testPlainValuesAreKept(): void {
		[$values, $excluded] = $this->reader(['enableAudit' => 'yes', 'pageSize' => 30])->read('openregister');

		$this->assertSame(['enableAudit' => 'yes', 'pageSize' => 30], $values);
		$this->assertSame([], $excluded);
	}

	public function testASensitiveKeyIsLeftOutAndRecorded(): void {
		[$values, $excluded] = $this->reader(['solrUrl' => 'http://solr', 'solrAuth' => 'user:pw'], ['solrAuth'])->read('openregister');

		$this->assertSame(['solrUrl' => 'http://solr'], $values);
		$this->assertSame(['configuration.solrAuth'], $excluded);
	}

	public function testASecretNamedKeyIsLeftOutEvenWhenNotSensitive(): void {
		[$values, $excluded] = $this->reader(['openai_api_key' => 'sk-1', 'title' => 'x'])->read('openregister');

		$this->assertSame(['title' => 'x'], $values);
		$this->assertSame(['configuration.openai_api_key'], $excluded);
	}

	public function testAnUnreadableSensitivityCountsAsSensitive(): void {
		[$values, $excluded] = $this->reader(['odd' => 'value'], [], ['odd'])->read('openregister');

		$this->assertSame([], $values);
		$this->assertSame(['configuration.odd'], $excluded);
	}

	public function testSecretsInsideAJsonObjectValueAreLeftOut(): void {
		$stored = '{"provider":"openai","apiKey":"sk-2","model":"gpt"}';

		[$values, $excluded] = $this->reader(['llm' => $stored])->read('openregister');

		$this->assertSame(['llm' => '{"provider":"openai","model":"gpt"}'], $values);
		$this->assertSame(['configuration.llm.apiKey'], $excluded);
	}

	public function testAJsonValueWithoutSecretsIsKeptAsStored(): void {
		$stored = '{ "a": 1,  "b": "c/d" }';

		[$values] = $this->reader(['shape' => $stored, 'broken' => '{not json'])->read('openregister');

		$this->assertSame(['shape' => $stored, 'broken' => '{not json'], $values);
	}
}
