<?php

/**
 * Golden test for the Schema configuration validators: what setConfiguration()
 * keeps, drops and throws for detailed linkRoles, partyKinds, linkedTypes,
 * mailObjectTemplate, calendarProvider, allowedTags and draftLifetimeDays
 * values, and what getLinkRoles() and getPartyKinds() read from a stored
 * configuration that was never validated. Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Schema\ConfigurationValueRules;
use OCA\OpenRegister\Db\Schema\LinkedTypesRule;
use OCA\OpenRegister\Db\Schema\VocabularyEntries;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Calendar\ObjectDateDeclaration
 */
class SchemaConfigValidatorsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__.'/fixtures/schema-config-validators-golden.json';

	public function testConfigValidatorsMatchGolden(): void {
		$actual = [];
		foreach ($this->configurations() as $name => $configuration) {
			$actual['save '.$name] = $this->outcome($configuration);
		}

		foreach ($this->stored() as $name => $configuration) {
			$schema = new Schema();
			(new ReflectionProperty(Schema::class, 'configuration'))->setValue($schema, $configuration);
			$actual['read '.$name] = ['linkRoles' => $schema->getLinkRoles(), 'partyKinds' => $schema->getPartyKinds()];
		}

		foreach ($this->direct() as $name => [$method, $value]) {
			$actual['direct '.$name] = $this->directOutcome($method, $value);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $outcome) {
			$this->assertSame(json_encode($outcome), json_encode($actual[$name]), $name);
		}
	}

	private function outcome(array $configuration): array {
		$schema = new Schema();
		$schema->setProperties(['title' => ['type' => 'string']]);
		$outcome = [];
		try {
			$schema->setConfiguration($configuration);
			$outcome['kept'] = $schema->getConfiguration();
		} catch (Throwable $e) {
			$outcome['threw'] = get_class($e).': '.$e->getMessage();
		}

		$outcome['dropped'] = $schema->consumeDroppedAnnotationKeys();

		return $outcome;
	}

	/**
	 * What one private validator answers or throws, called directly: setConfiguration() swallows the message.
	 *
	 * @param string $method The validator.
	 * @param mixed  $value  The value it is given.
	 *
	 * @return array<string, mixed>
	 */
	private function directOutcome(string $method, mixed $value): array {
		$rules = [
			'validateLinkRolesValue'          => new VocabularyEntries(),
			'validatePartyKindsValue'         => new VocabularyEntries(),
			'validateLinkedTypesValue'        => new LinkedTypesRule(),
			'validateMailObjectTemplateValue' => new ConfigurationValueRules(),
			'validateCalendarProviderConfig'  => new ConfigurationValueRules(),
		];
		try {
			return ['returned' => $rules[$method]->$method($value)];
		} catch (Throwable $e) {
			return ['threw' => get_class($e).': '.$e->getMessage()];
		}
	}

	/**
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	private function direct(): array {
		$cases = [];
		foreach (['validateLinkRolesValue', 'validatePartyKindsValue'] as $method) {
			$values = ['text' => 'x', 'map' => ['a' => 'x'], 'no key' => ['a', ['label' => 'x']], 'twice' => ['a', ' a '], 'ok' => ['a', 'b']];
			foreach ($values as $name => $value) {
				$cases[$method.' '.$name] = [$method, $value];
			}
		}

		return $cases + [
			'linkedTypes text'        => ['validateLinkedTypesValue', 'mail'],
			'linkedTypes number'      => ['validateLinkedTypesValue', ['mail', 3]],
			'linkedTypes unknown'     => ['validateLinkedTypesValue', ['mail', 'pigeon']],
			'linkedTypes null'        => ['validateLinkedTypesValue', null],
			'mail text'               => ['validateMailObjectTemplateValue', 'x'],
			'mail empty key'          => ['validateMailObjectTemplateValue', ['' => 1]],
			'mail int key'            => ['validateMailObjectTemplateValue', [3 => 'x']],
			'mail nested'             => ['validateMailObjectTemplateValue', ['title' => []]],
			'mail null'               => ['validateMailObjectTemplateValue', null],
			'calendar no start'       => ['validateCalendarProviderConfig', ['enabled' => true]],
			'calendar no title'       => ['validateCalendarProviderConfig', ['enabled' => true, 'dtstart' => 's']],
			'calendar off'            => ['validateCalendarProviderConfig', ['enabled' => 0, 'dtstart' => '']],
			'calendar ok'             => ['validateCalendarProviderConfig', ['enabled' => 1, 'dtstart' => 's', 'titleTemplate' => 't']],
		];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function configurations(): array {
		$roles = [
			'mixed'           => [
				'owner',
				['key' => ' lead ', 'label' => ' Lead ', 'description' => ' Leads it '],
				['key' => 'x', 'label' => '', 'description' => '  '],
			],
			'label defaults'  => [['key' => 'k']],
			'numeric key'     => [5, ['key' => 7]],
			'duplicate'       => ['a', ['key' => 'a', 'label' => 'A']],
			'duplicate trim'  => ['a', ['key' => ' a ']],
			'empty key'       => [['label' => 'nameless']],
			'blank string'    => ['  '],
			'long key'        => [str_repeat('k', 65)],
			'max key'         => [str_repeat('k', 64)],
			'bad then dup'    => ['a', 'a', ['label' => 'x']],
			'dup then bad'    => ['a', ['label' => 'x'], 'a'],
			'entry number'    => [['key' => 'a'], 3.5],
			'entry null'      => [null],
			'map not list'    => ['a' => 'owner'],
			'text'            => 'owner',
			'empty list'      => [],
		];
		$cases = [];
		foreach ($roles as $name => $value) {
			$cases['linkRoles '.$name]  = ['linkRoles' => $value];
			$cases['partyKinds '.$name] = ['partyKinds' => $value];
		}

		return array_merge($cases, $this->partyKindCases(), $this->otherCases());
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function partyKindCases(): array {
		return [
			'partyKinds lists'       => [
				'partyKinds' => [['key' => 'person', 'roles' => [' owner ', 'owner', '', 'lead', 3], 'identityShapes' => ['naturalPerson', ' naturalPerson']]],
			],
			'partyKinds empty lists' => ['partyKinds' => [['key' => 'org', 'roles' => [], 'identityShapes' => 'organization']]],
			'partyKinds string kind' => ['partyKinds' => ['person', ['key' => 'org', 'label' => 'Organisation', 'roles' => ['x']]]],
			'both vocabularies'      => ['linkRoles' => ['owner'], 'partyKinds' => [['key' => 'person', 'roles' => ['owner']]]],
		];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function otherCases(): array {
		return [
			'linkedTypes valid'        => ['linkedTypes' => ['mail', 'notes', 'deck']],
			'linkedTypes invalid'      => ['linkedTypes' => ['mail', 'carrier-pigeon']],
			'linkedTypes number'       => ['linkedTypes' => ['mail', 7]],
			'linkedTypes map'          => ['linkedTypes' => ['a' => 'talk']],
			'linkedTypes empty'        => ['linkedTypes' => []],
			'mail template'            => ['mailObjectTemplate' => ['title' => '{{subject}}', 'count' => 3, 'flag' => true, 'none' => 1.5]],
			'mail template list'       => ['mailObjectTemplate' => ['{{subject}}']],
			'mail template empty key'  => ['mailObjectTemplate' => ['' => 'x']],
			'mail template nested'     => ['mailObjectTemplate' => ['title' => ['a']]],
			'mail template null value' => ['mailObjectTemplate' => ['title' => null]],
			'mail template text'       => ['mailObjectTemplate' => 'x'],
			'calendar off'             => ['calendarProvider' => ['enabled' => false]],
			'calendar on no title'     => ['calendarProvider' => ['enabled' => true, 'dtstart' => 'start']],
			'calendar on empty start'  => ['calendarProvider' => ['enabled' => true, 'dtstart' => '', 'titleTemplate' => 't']],
			'calendar on'              => ['calendarProvider' => ['enabled' => true, 'dtstart' => 'start', 'titleTemplate' => '{{title}}']],
			'calendar enabled text'    => ['calendarProvider' => ['enabled' => '0', 'dtstart' => '']],
			'calendar not array'       => ['calendarProvider' => 'on'],
			'allowedTags'              => ['allowedTags' => ['a', 'b']],
			'allowedTags number'       => ['allowedTags' => ['a', 1]],
			'draft lifetime'           => ['draftLifetimeDays' => '14'],
			'draft lifetime zero text' => ['draftLifetimeDays' => '014'],
			'draft lifetime false'     => ['draftLifetimeDays' => false],
			'draft lifetime float'     => ['draftLifetimeDays' => 3.0],
			'draft lifetime null'      => ['draftLifetimeDays' => null, 'allowFiles' => true],
			'annotation unknown'       => ['x-openregister-nope' => 1, 'x-openregister-lifecycle' => ['states' => []], 'plainUnknown' => 2],
			'string fields'            => ['objectNameField' => '', 'objectSlugField' => 'slug', 'objectImageField' => null],
			'string field number'      => ['objectNameField' => 3],
			'bool field text'          => ['allowFiles' => 'yes', 'autoShare' => true],
			'pass through'             => ['unique' => ['title'], 'jsonld' => ['@context' => 'x']],
		];
	}

	/**
	 * Stored configurations, as a database row might hold them, never validated.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		return [
			'none'         => null,
			'not arrays'   => ['linkRoles' => 'owner', 'partyKinds' => 5],
			'messy'        => [
				'linkRoles'  => ['owner', ['key' => ''], 4, ['key' => 'lead', 'label' => '', 'description' => 'd'], 'owner'],
				'partyKinds' => ['person', null, ['key' => 'org', 'roles' => ['a', 'a', ' '], 'identityShapes' => 'x'], ['label' => 'nameless']],
			],
			'map'          => ['linkRoles' => ['a' => 'owner', 'b' => ['key' => 'b']], 'partyKinds' => ['k' => ['key' => 'k']]],
		];
	}
}
