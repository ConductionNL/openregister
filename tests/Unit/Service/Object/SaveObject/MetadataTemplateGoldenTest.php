<?php

/**
 * Golden tests for MetadataHydrationHandler's `{{ ... }}` metadata templates.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Object\SaveObject\TemplateSource;

/**
 * Pins the rendered text of templates with plain fields, filters, fallbacks and relations.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\TemplateSource
 */
class MetadataTemplateGoldenTest extends TestCase {

	private const UUID = '0b9d2f5e-6c1a-4f43-9b8e-3d2f1a0c9e77';

	/**
	 * Templates and what they render to.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function templates(): array {
		return [
			'no placeholder' => ['Plain title', null],
			'one field' => ['{{ name }}', 'Ada'],
			'nested path and text' => ['Case {{ case.number }} for {{name}}', 'Case 42-A for Ada'],
			'missing field removed, spaces collapsed' => ['{{ name }}   {{ missing }}  !', 'Ada !'],
			'only missing' => ['{{ missing }} {{ blank }}', null],
			'value is trimmed' => ['[{{ padded }}]', '[x]'],
			'integer value' => ['n={{ count }}', 'n=7'],
			'object value drops' => ['{{ case }} {{ name }}', 'Ada'],
			'repeated placeholder' => ['{{ name }}-{{ name }}', 'Ada-Ada'],
			'ifFilled filled' => ['{{ name | ifFilled: has name, no name }}', 'has name'],
			'ifFilled empty' => ['{{ blank | ifFilled: yes, no }}', 'no'],
			'map' => ['{{ status | map: o=Open, c=Closed }}', 'Closed'],
			'map miss keeps the value' => ['{{ status | map: o=Open }}', 'c'],
			'fallback chain' => ['{{ missing | blank | name }}', 'Ada'],
			'relation by name' => ['{{ owner }}', 'Grace'],
			'text with newline' => ["{{ name }}\n\t{{ case.number }}", 'Ada 42-A'],
		];
	}//end templates()

	/**
	 * Render a template.
	 *
	 * @param string      $template The template.
	 * @param string|null $expected The rendered text.
	 *
	 * @return void
	 */
	#[DataProvider('templates')]
	public function testTemplate(string $template, ?string $expected): void {
		$cache = $this->createMock(CacheHandlerInterface::class);
		$cache->method('getMultipleObjectNames')->willReturn([self::UUID => 'Grace']);
		$handler = new MetadataHydrationHandler($this->createMock(LoggerInterface::class), $cache);

		$this->assertSame($expected, $handler->renderTemplate(template: $template, source: new TemplateSource(data: $this->data(), schemaProperties: $this->properties())));
	}//end testTemplate()

	public function testAnUnresolvableRelationFallsBackToItsUuid(): void {
		$cache = $this->createMock(CacheHandlerInterface::class);
		$cache->method('getMultipleObjectNames')->willThrowException(new \Exception('cache down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$handler = new MetadataHydrationHandler($logger, $cache);

		$this->assertSame('by ' . self::UUID, $handler->renderTemplate(template: 'by {{ owner }}', source: new TemplateSource(data: $this->data(), schemaProperties: $this->properties())));
	}//end testAnUnresolvableRelationFallsBackToItsUuid()

	/**
	 * The object data.
	 *
	 * @return array The data.
	 */
	private function data(): array {
		return [
			'name' => 'Ada',
			'case' => ['number' => '42-A'],
			'blank' => '   ',
			'padded' => '  x  ',
			'count' => 7,
			'status' => 'c',
			'owner' => self::UUID,
		];
	}//end data()

	/**
	 * Schema properties with one relation.
	 *
	 * @return array The properties.
	 */
	private function properties(): array {
		return ['owner' => ['type' => 'string', 'format' => 'uuid', '$ref' => '#/components/schemas/person'], 'name' => ['type' => 'string']];
	}//end properties()
}//end class
