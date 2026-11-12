<?php

/**
 * Access golden test for StateFieldRuleResolver: who a group-scoped field rule
 * applies to (and who it spares) for an anonymous caller, a signed-in
 * non-member and a member; the field and message shapes; conditions; the
 * block switches; and the per-request memo.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
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

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRules;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Pins StateFieldRuleResolver outcomes before its units are split.
 */
class StateFieldRuleResolverAccessGoldenTest extends TestCase {

	/**
	 * How often the group manager was asked for the user's groups.
	 *
	 * @var int
	 */
	private int $groupLookups = 0;

	/**
	 * A resolver for an anonymous caller (null) or a user in the given groups.
	 *
	 * @param array<int, string>|null $groups The user's groups, or null for anonymous.
	 *
	 * @return StateFieldRuleResolver
	 */
	private function resolver(?array $groups): StateFieldRuleResolver {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($groups !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('u1');
		}

		$session->method('getUser')->willReturn($user);

		$this->groupLookups = 0;
		$manager            = $this->createMock(IGroupManager::class);
		$manager->method('getUserGroupIds')->willReturnCallback(
			function () use ($groups): array {
				$this->groupLookups++;
				return ($groups ?? []);
			}
		);

		return new StateFieldRuleResolver($session, $manager, new ConditionDialect(new CalculationEvaluator(new PlaceholderResolver($session))));
	}//end resolver()

	/**
	 * The rules as plain data.
	 *
	 * @param StateFieldRules $rules The rules.
	 *
	 * @return array<string, mixed>
	 */
	private function plain(StateFieldRules $rules): array {
		return [
			'state' => $rules->getState(),
			'hidden' => $rules->getHidden(),
			'readOnly' => $rules->getReadOnly(),
			'required' => $rules->getRequired(),
			'messages' => $this->messages(rules: $rules),
		];
	}//end plain()

	/**
	 * The message of each ruled field that has one.
	 *
	 * @param StateFieldRules $rules The rules.
	 *
	 * @return array<string, string>
	 */
	private function messages(StateFieldRules $rules): array {
		$messages = [];
		foreach (array_merge($rules->getHidden(), $rules->getReadOnly(), $rules->getRequired()) as $field) {
			$message = $rules->messageFor(property: $field);
			if ($message !== null) {
				$messages[$field] = $message;
			}
		}

		return $messages;
	}//end messages()

	/**
	 * An annotation with one state holding the given field rules.
	 *
	 * @param array<string, mixed> $fields The state's `fields`.
	 * @param array<string, mixed> $block  More state-block keys.
	 *
	 * @return array<string, mixed>
	 */
	private function annotation(array $fields, array $block = []): array {
		return ['field' => 'status', 'states' => ['open' => array_merge(['fields' => $fields], $block)]];
	}//end annotation()

	/**
	 * The hidden fields each caller gets for one hidden rule with the given groups.
	 *
	 * @param mixed $groups The rule's `groups`.
	 *
	 * @return array<string, array<int, string>> Hidden fields for anonymous, outsider and member.
	 */
	private function hiddenFor(mixed $groups): array {
		$annotation = $this->annotation(fields: ['hidden' => [['fields' => ['secret'], 'groups' => $groups]]]);
		$out        = [];
		foreach (['anonymous' => null, 'outsider' => ['other'], 'member' => ['handlers']] as $who => $userGroups) {
			$out[$who] = $this->resolver(groups: $userGroups)->resolveFromAnnotation(annotation: $annotation, data: ['status' => 'open'])->getHidden();
		}

		return $out;
	}//end hiddenFor()

	public function testWhoAGroupScopedRuleAppliesTo(): void {
		$all  = ['anonymous' => ['secret'], 'outsider' => ['secret'], 'member' => ['secret']];
		$none = ['anonymous' => [], 'outsider' => [], 'member' => []];

		$this->assertSame($all, $this->hiddenFor(groups: null));
		$this->assertSame($all, $this->hiddenFor(groups: []));
		$this->assertSame($all, $this->hiddenFor(groups: 5));
		$this->assertSame($all, $this->hiddenFor(groups: 'public'));
		$this->assertSame($all, $this->hiddenFor(groups: [7, 'public']));
		$this->assertSame(['anonymous' => [], 'outsider' => ['secret'], 'member' => ['secret']], $this->hiddenFor(groups: ['authenticated']));
		$this->assertSame(['anonymous' => [], 'outsider' => [], 'member' => ['secret']], $this->hiddenFor(groups: 'handlers'));
		$this->assertSame(['anonymous' => [], 'outsider' => [], 'member' => ['secret']], $this->hiddenFor(groups: [5, 'handlers']));
		$this->assertSame($none, $this->hiddenFor(groups: ['nobody', 3]));
	}//end testWhoAGroupScopedRuleAppliesTo()

	public function testFieldShapesMessagesAndConditions(): void {
		$annotation = $this->annotation(
			fields: [
				'hidden' => 'not-a-list',
				'readOnly' => [
					['field' => 'a', 'message' => 'first'],
					['fields' => 'a', 'message' => 'second'],
					['fields' => ['b', '', 3, 'c'], 'message' => ''],
					['fields' => 5],
					'not-an-entry',
					['fields' => ['d'], 'when' => ['>' => [['var' => 'object.n'], 10]], 'message' => 'big'],
					['fields' => ['e'], 'condition' => ['>' => [['var' => 'object.n'], 0]], 'message' => 7],
				],
				'required' => [['fields' => ['b'], 'message' => 'needed']],
				'other' => [['fields' => ['z']]],
			]
		);

		$this->assertSame(
			[
				'state' => 'open',
				'hidden' => [],
				'readOnly' => ['a', 'b', 'c', 'e'],
				'required' => ['b'],
				'messages' => ['a' => 'first', 'b' => 'needed'],
			],
			$this->plain($this->resolver(groups: ['x'])->resolveFromAnnotation(annotation: $annotation, data: ['status' => 'open', 'n' => 5]))
		);
	}//end testFieldShapesMessagesAndConditions()

	public function testBlockSwitches(): void {
		$resolver = $this->resolver(groups: ['x']);
		$rule     = ['readOnly' => [['fields' => ['a']]]];
		$empty    = static fn (?string $state): array => ['state' => $state, 'hidden' => [], 'readOnly' => [], 'required' => [], 'messages' => []];
		$open     = $this->annotation(fields: $rule);
		$resolve  = fn (array $annotation, array $data): array => $this->plain($resolver->resolveFromAnnotation(annotation: $annotation, data: $data));

		$this->assertSame($empty(null), $resolve($open, []));
		$this->assertSame($empty(null), $resolve($open, ['status' => '']));
		$this->assertSame($empty('gone'), $resolve($open, ['status' => 'gone']));
		$this->assertSame(
			$empty('open'),
			$resolve($this->annotation(fields: $rule, block: ['enabled' => false]), ['status' => 'open'])
		);
		$this->assertSame(
			['a'],
			$resolver->resolveFromAnnotation(annotation: $this->annotation(fields: $rule, block: ['enabled' => 0]), data: ['status' => 'open'])->getReadOnly()
		);
		$this->assertSame(
			$empty('open'),
			$this->plain(
				$resolver->resolveFromAnnotation(
					annotation: $this->annotation(fields: $rule, block: ['condition' => ['>' => [['var' => 'object.n'], 10]]]),
					data: ['status' => 'open', 'n' => 1]
				)
			)
		);
		$this->assertSame(
			['a'],
			$resolver->resolveFromAnnotation(
				annotation: $this->annotation(fields: $rule, block: ['condition' => ['>' => [['var' => 'object.n'], 10]]]),
				data: ['status' => 'open', 'n' => 11]
			)->getReadOnly()
		);
		$this->assertSame($empty('open'), $resolve(['field' => 'status', 'states' => ['open' => ['fields' => 'x']]], ['status' => 'open']));
		$this->assertSame($empty('open'), $resolve(['property' => 'status', 'states' => 'x'], ['status' => 'open']));
		$this->assertSame($empty('open'), $resolve(['property' => 'status', 'states' => ['open' => []]], ['status' => 'open']));
		$this->assertSame(['a'], $resolver->resolveFromAnnotation(annotation: $open, data: [], state: 'open')->getReadOnly());
	}//end testBlockSwitches()

	public function testResolveMemoisesOnlyUnconditionalStates(): void {
		$resolver = $this->resolver(groups: ['x']);
		$schema   = new Schema();
		$schema->setId(3);
		$schema->setConfiguration(
			[
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'states' => [
						'open' => ['fields' => ['readOnly' => [['fields' => ['a']]]]],
						'busy' => ['fields' => ['readOnly' => [['fields' => ['b'], 'when' => ['>' => [['var' => 'object.n'], 1]]]]]],
					],
				],
			]
		);

		$first  = $resolver->resolve(schema: $schema, data: ['status' => 'open']);
		$second = $resolver->resolve(schema: $schema, data: ['status' => 'open', 'n' => 9]);
		$this->assertSame($first, $second);
		// The memo key reads the groups on every call; the rules read them once.
		$this->assertSame(3, $this->groupLookups);

		$busyLow  = $resolver->resolve(schema: $schema, data: ['status' => 'busy', 'n' => 0]);
		$busyHigh = $resolver->resolve(schema: $schema, data: ['status' => 'busy', 'n' => 5]);
		$this->assertSame([[], ['b']], [$busyLow->getReadOnly(), $busyHigh->getReadOnly()]);
		$this->assertNotSame($busyLow, $resolver->resolve(schema: $schema, data: ['status' => 'busy', 'n' => 0]));

		$this->assertSame(['b'], $resolver->resolve(schema: $schema, data: ['status' => 'open', 'n' => 5], state: 'busy')->getReadOnly());
		$this->assertSame([], $resolver->resolve(schema: new Schema(), data: ['status' => 'open'])->getReadOnly());
		$this->assertNull($resolver->resolve(schema: $schema, data: [])->getState());
	}//end testResolveMemoisesOnlyUnconditionalStates()

	public function testAnonymousMemoKeyAndConditionalDetection(): void {
		$resolver = $this->resolver(groups: null);
		$schema   = new Schema();
		$schema->setId(4);
		$schema->setConfiguration(
			[
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'states' => [
						'open' => ['fields' => ['hidden' => 'x', 'readOnly' => ['y', ['fields' => ['a'], 'groups' => ['authenticated']]]]],
						'gated' => ['condition' => ['>' => [['var' => 'object.n'], 1]], 'fields' => ['hidden' => [['fields' => ['h']]]]],
					],
				],
			]
		);

		$this->assertSame($resolver->resolve(schema: $schema, data: ['status' => 'open']), $resolver->resolve(schema: $schema, data: ['status' => 'open']));
		$this->assertSame([], $resolver->resolve(schema: $schema, data: ['status' => 'open'])->getReadOnly());
		$this->assertSame(0, $this->groupLookups);
		$this->assertSame([[], ['h']], [
			$resolver->resolve(schema: $schema, data: ['status' => 'gated', 'n' => 0])->getHidden(),
			$resolver->resolve(schema: $schema, data: ['status' => 'gated', 'n' => 2])->getHidden(),
		]);
	}//end testAnonymousMemoKeyAndConditionalDetection()
}//end class
