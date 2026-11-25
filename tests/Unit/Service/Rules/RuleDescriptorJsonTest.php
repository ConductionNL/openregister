<?php

/**
 * RuleDescriptorJson: the inventory entry of one rule.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rules\RuleDescriptor;
use OCA\OpenRegister\Service\Rules\RuleDescriptorJson;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rules\RuleDescriptorJson
 * @uses   \OCA\OpenRegister\Service\Rules\RuleDescriptor
 * @uses   \OCA\OpenRegister\Service\Rules\RuleVocabulary
 */
class RuleDescriptorJsonTest extends TestCase {

	/**
	 * A rule's descriptor fields.
	 *
	 * @param string $kind The rule kind.
	 *
	 * @return array<string, mixed>
	 */
	private function fields(string $kind): array {
		return [
			'kind' => $kind,
			'schemaSlug' => 'permit',
			'key' => 'fee',
			'label' => 'Fee',
			'source' => 'x-openregister-calculations',
			'enabled' => true,
			'actions' => [RuleVocabulary::ACTION_SET_VALUE],
			'condition' => null,
			'maxObjects' => 500,
			'scope' => 'schema',
		];
	}

	public function testSerializeRenamesSchemaSlugAndAddsIdAndOrder(): void {
		$entry = (new RuleDescriptorJson())->serialize($this->fields(RuleVocabulary::KIND_CALCULATION));

		$this->assertSame([
			'id' => 'calculation:permit:fee',
			'kind' => 'calculation',
			'schema' => 'permit',
			'key' => 'fee',
			'label' => 'Fee',
			'source' => 'x-openregister-calculations',
			'enabled' => true,
			'actions' => ['setValue'],
			'condition' => null,
			'maxObjects' => 500,
			'scope' => 'schema',
			'order' => 1,
		], $entry);
		$this->assertArrayNotHasKey('schemaSlug', $entry);
	}

	public function testTheIdIsTheDescriptorsId(): void {
		$entry = (new RuleDescriptorJson())->serialize($this->fields(RuleVocabulary::KIND_FLOW));

		$this->assertSame(
			RuleDescriptor::idFor(kind: 'flow', schemaSlug: 'permit', key: 'fee'),
			$entry['id']
		);
	}

	public function testTheOrderFollowsTheKind(): void {
		$json = new RuleDescriptorJson();

		$this->assertSame(5, $json->serialize($this->fields(RuleVocabulary::KIND_FLOW))['order']);
		$this->assertSame(4, $json->serialize($this->fields(RuleVocabulary::KIND_ADMINISTERED_VALIDATION))['order']);
		$this->assertSame(99, $json->serialize($this->fields('unknownKind'))['order']);
	}
}
