<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for LifecycleAnnotationValidator::validate().
 *
 * Each case is an annotation (with optional property overrides) on a schema whose
 * `status` property enumerates draft, open, closed and archived. The full error
 * list, order included, is compared with fixtures/lifecycle-validate-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class LifecycleAnnotationValidatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/lifecycle-validate-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$open = ['from' => ['draft'], 'to' => 'open'];
		$base = ['field' => 'status', 'initial' => 'draft', 'transitions' => ['open' => $open]];
		$cases = [
			'no annotation' => ['schema' => ['properties' => []]],
			'valid minimal' => ['annotation' => $base],
			'property alias' => ['annotation' => ['property' => 'status', 'initial' => 'draft', 'transitions' => ['open' => $open]]],
			'field wins over property' => ['annotation' => $base + ['property' => 'nope']],
			'missing every key' => ['annotation' => []],
			'missing initial and transitions' => ['annotation' => ['field' => 'status']],
			'field not declared' => ['annotation' => ['field' => 'state'] + $base],
			'field declared null' => ['annotation' => $base, 'properties' => ['status' => null]],
			'no properties at all' => ['annotation' => $base, 'noProperties' => true],
			'field not a string' => ['annotation' => $base, 'properties' => ['status' => ['type' => 'integer', 'enum' => ['draft', 'open']]]],
			'field without enum' => ['annotation' => $base, 'properties' => ['status' => ['type' => 'string']]],
			'field with empty enum and wrong type' => ['annotation' => $base, 'properties' => ['status' => ['type' => 'number', 'enum' => []]]],
			'field enum not a list' => ['annotation' => $base, 'properties' => ['status' => ['type' => 'string', 'enum' => 'draft']]],
			'initial not in enum' => ['annotation' => ['initial' => 'new'] + $base],
			'final in enum' => ['annotation' => $base + ['final' => ['closed', 'archived']]],
			'final not in enum' => ['annotation' => $base + ['final' => ['closed', 'gone', 'lost']]],
			'final as string' => ['annotation' => $base + ['final' => 'closed']],
			'final reference form' => ['annotation' => $base + ['final' => ['from' => 'endings', 'field' => 'code']]],
			'final reference form incomplete' => ['annotation' => $base + ['final' => ['from' => 'endings']]],
			'final numeric values' => ['annotation' => $base + ['final' => [1, 2]]],
			'transitions empty' => ['annotation' => ['transitions' => []] + $base],
			'transitions not a map' => ['annotation' => ['transitions' => 'open'] + $base],
			'transitions empty after initial error' => ['annotation' => ['initial' => 'x', 'transitions' => []] + $base],
			'transition not an object' => ['annotation' => ['transitions' => ['open' => 'yes', 7 => null]] + $base],
			'from as string' => ['annotation' => ['transitions' => ['open' => ['from' => 'draft', 'to' => 'open']]] + $base],
			'from empty string' => ['annotation' => ['transitions' => ['open' => ['from' => '', 'to' => 'open']]] + $base],
			'from missing' => ['annotation' => ['transitions' => ['open' => ['to' => 'open']]] + $base],
			'from empty list' => ['annotation' => ['transitions' => ['open' => ['from' => [], 'to' => 'open']]] + $base],
			'from not in enum' => ['annotation' => ['transitions' => ['open' => ['from' => ['draft', 'new', 'old'], 'to' => 'open']]] + $base],
			'to missing' => ['annotation' => ['transitions' => ['open' => ['from' => ['draft']]]] + $base],
			'to empty' => ['annotation' => ['transitions' => ['open' => ['from' => ['draft'], 'to' => '']]] + $base],
			'to not a string' => ['annotation' => ['transitions' => ['open' => ['from' => ['draft'], 'to' => ['open']]]] + $base],
			'to not in enum' => ['annotation' => ['transitions' => ['open' => ['from' => ['draft'], 'to' => 'opened']]] + $base],
			'requires valid' => ['annotation' => ['transitions' => ['open' => $open + ['requires' => 'app.tag']]] + $base],
			'requires empty' => ['annotation' => ['transitions' => ['open' => $open + ['requires' => '']]] + $base],
			'requires not a string' => ['annotation' => ['transitions' => ['open' => $open + ['requires' => 5]]] + $base],
			'requires null' => ['annotation' => ['transitions' => ['open' => $open + ['requires' => null]]] + $base],
			'authorization valid' => ['annotation' => ['transitions' => ['open' => $open + ['authorization' => ['admin', ['role' => 'chair']]]]] + $base],
			'authorization empty' => ['annotation' => ['transitions' => ['open' => $open + ['authorization' => []]]] + $base],
			'authorization bad entry' => ['annotation' => ['transitions' => ['open' => $open + ['authorization' => [5, ['x' => 1]]]]] + $base],
			'authorization scalar' => ['annotation' => ['transitions' => ['open' => $open + ['authorization' => 'admin']]] + $base],
			'condition valid' => ['annotation' => ['transitions' => ['open' => $open + ['condition' => ['==' => [['var' => 'a'], 1]]]]] + $base],
			'condition scalar' => ['annotation' => ['transitions' => ['open' => $open + ['condition' => true]]] + $base],
			'condition empty' => ['annotation' => ['transitions' => ['open' => $open + ['condition' => []]]] + $base],
			'auto when valid' => ['annotation' => ['transitions' => ['open' => $open + ['autoWhen' => ['==' => [['var' => 'a'], 1]], 'executionMode' => 'sync']]] + $base],
			'auto when scalar' => ['annotation' => ['transitions' => ['open' => $open + ['autoWhen' => 'always']]] + $base],
			'execution mode unknown' => ['annotation' => ['transitions' => ['open' => $open + ['executionMode' => 'later']]] + $base],
			'auto with required inputs' => [
				'annotation' => ['transitions' => ['open' => $open + ['autoWhen' => ['==' => [1, 1]], 'inputs' => [['name' => 'reason', 'required' => true]]]]] + $base,
			],
			'message string' => ['annotation' => ['transitions' => ['open' => $open + ['message' => 'Not yet']]] + $base],
			'message empty' => ['annotation' => ['transitions' => ['open' => $open + ['message' => '']]] + $base],
			'message map' => ['annotation' => ['transitions' => ['open' => $open + ['message' => ['nl' => 'Nog niet', 'en' => 'Not yet']]]] + $base],
			'message map bad' => ['annotation' => ['transitions' => ['open' => $open + ['message' => ['nl' => '', 'xx-bad!' => 5]]]] + $base],
			'message map default locale' => ['annotation' => ['transitions' => ['open' => $open + ['message' => ['default' => 'fr', 'nl' => 'Nog niet']]]] + $base],
			'message number' => ['annotation' => ['transitions' => ['open' => $open + ['message' => 5]]] + $base],
			'many transition problems' => [
				'annotation' => [
					'transitions' => [
						'open' => ['from' => ['new'], 'to' => 'gone', 'requires' => '', 'authorization' => [], 'condition' => 1, 'message' => ''],
						'close' => ['from' => 'open', 'to' => 'closed'],
						'broken' => 'x',
					],
				] + $base + ['final' => ['nope']],
			],
			'states block' => [
				'annotation' => $base + ['states' => ['open' => ['hidden' => ['secret'], 'frozen' => ['title'], 'required' => ['owner']], 'gone' => []]],
			],
			'states with inputs on hidden fields' => [
				'annotation' => ['transitions' => ['open' => $open + ['inputs' => [['name' => 'secret']]]]] + $base + ['states' => ['open' => ['hidden' => ['secret']]]],
			],
			'states malformed' => ['annotation' => $base + ['states' => 'all']],
			'provider mode' => ['annotation' => ['field' => 'status', 'provider' => 'app.lifecycle']],
			'provider empty' => ['annotation' => ['field' => 'status', 'provider' => ' ']],
			'provider with transitions' => ['annotation' => ['provider' => 'app.lifecycle'] + $base],
			'provider with graph and no field' => ['annotation' => ['provider' => 'app.lifecycle', 'graph' => ['nodes' => []]]],
			'provider with undeclared field' => ['annotation' => ['provider' => 'app.lifecycle', 'field' => 'state']],
			'provider with states' => ['annotation' => ['provider' => 'app.lifecycle', 'field' => 'status', 'states' => ['open' => ['hidden' => ['x']]]]],
			'graph mode' => ['annotation' => ['field' => 'status', 'graph' => ['from' => 'steps', 'field' => 'code']]],
			'graph mode malformed' => ['annotation' => ['field' => 'status', 'graph' => ['x' => 1], 'transitions' => []]],
			'graph empty falls back to static' => ['annotation' => $base + ['graph' => []]],
			'graph not an array falls back to static' => ['annotation' => $base + ['graph' => 'steps']],
			'numeric action' => ['annotation' => ['transitions' => [3 => ['from' => ['nope'], 'to' => 'gone']]] + $base],
		];

		$out = [];
		foreach ($cases as $name => $case) {
			$out[$name] = [$case];
		}

		return $out;
	}//end cases()

	/**
	 * @param array<string, mixed> $case
	 */
	#[DataProvider('cases')]
	public function testValidateMatchesGolden(array $case): void {
		$name = $this->dataName();
		$schema = ($case['schema'] ?? null);
		if ($schema === null) {
			$properties = [
				'status' => ['type' => 'string', 'enum' => ['draft', 'open', 'closed', 'archived']],
				'secret' => ['type' => 'string'],
				'title' => ['type' => 'string'],
				'owner' => ['type' => 'string'],
			];
			$properties = array_replace($properties, $case['properties'] ?? []);
			$schema = ['x-openregister-lifecycle' => $case['annotation'], 'properties' => $properties];
			if (($case['noProperties'] ?? false) === true) {
				unset($schema['properties']);
			}
		}

		try {
			$actual = (new LifecycleAnnotationValidator())->validate($schema);
		} catch (\Throwable $e) {
			$actual = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		$actual = json_decode(json_encode($actual), true);

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testValidateMatchesGolden()
}//end class
