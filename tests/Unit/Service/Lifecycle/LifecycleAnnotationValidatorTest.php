<?php

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator;
use PHPUnit\Framework\TestCase;

class LifecycleAnnotationValidatorTest extends TestCase {
	private LifecycleAnnotationValidator $v;

	protected function setUp(): void {
		$this->v = new LifecycleAnnotationValidator();
	}

	public function testNoAnnotationIsValid(): void {
		$this->assertSame([], $this->v->validate(['properties' => []]));
	}

	public function testMissingFieldIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => ['initial' => 'draft', 'transitions' => ['x' => ['from' => ['draft'], 'to' => 'open']]],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft','open']]],
		]);
		$this->assertNotEmpty($errors);
		$this->assertSame('lifecycle-missing-key', $errors[0]['code']);
	}

	public function testFieldNotInPropertiesIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => ['field' => 'status', 'initial' => 'draft', 'transitions' => ['x' => ['from' => ['draft'], 'to' => 'open']]],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft','open']]],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-field-missing', $codes);
	}

	public function testFieldNotStringIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => ['field' => 'count', 'initial' => '0', 'transitions' => ['x' => ['from' => ['0'], 'to' => '1']]],
			'properties' => ['count' => ['type' => 'integer']],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-field-not-string', $codes);
	}

	public function testInitialNotInEnumIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => ['field' => 'lifecycle', 'initial' => 'unknown', 'transitions' => ['x' => ['from' => ['draft'], 'to' => 'open']]],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft','open']]],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-initial-not-in-enum', $codes);
	}

	public function testFinalNotInEnumIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'draft',
				'final' => ['nonexistent'],
				'transitions' => ['x' => ['from' => ['draft'], 'to' => 'open']],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft','open']]],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-final-not-in-enum', $codes);
	}

	public function testTransitionFromNotInEnumIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => ['field' => 'lifecycle', 'initial' => 'draft', 'transitions' => ['x' => ['from' => ['unknown'], 'to' => 'open']]],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft','open']]],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-from-not-in-enum', $codes);
	}

	public function testTransitionToNotInEnumIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => ['field' => 'lifecycle', 'initial' => 'draft', 'transitions' => ['x' => ['from' => ['draft'], 'to' => 'unknown']]],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft','open']]],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-to-not-in-enum', $codes);
	}

	public function testRequiresMustBeNonEmptyString(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => ['field' => 'lifecycle', 'initial' => 'draft', 'transitions' => ['x' => ['from' => ['draft'], 'to' => 'open', 'requires' => '']]],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft','open']]],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-requires-malformed', $codes);
	}

	public function testValidAnnotationProducesNoErrors(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'draft',
				'final' => ['closed'],
				'transitions' => [
					'open' => ['from' => ['draft'], 'to' => 'opened', 'requires' => 'app.guard'],
					'close' => ['from' => ['opened'], 'to' => 'closed'],
				],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft','opened','closed']]],
		]);
		$this->assertSame([], $errors);
	}

	public function testPropertyAliasIsAcceptedAsField(): void {
		// `property` (procest migration shape) is accepted as an alias for `field`.
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'property' => 'lifecycle',
				'initial' => 'concept',
				'transitions' => ['indienen' => ['from' => 'concept', 'to' => 'in_parafering']],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['concept','in_parafering']]],
		]);
		$this->assertSame([], $errors);
	}

	public function testStringFromIsAccepted(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'concept',
				'transitions' => ['indienen' => ['from' => 'concept', 'to' => 'in_parafering']],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['concept','in_parafering']]],
		]);
		$this->assertSame([], $errors);
	}

	public function testTransitionAuthorizationGroupListIsAccepted(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'concept',
				'transitions' => [
					'completeren' => [
						'from' => 'in_parafering',
						'to' => 'geparafeerd',
						'authorization' => ['vergunningverleners', ['role' => 'handler']],
					],
				],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['concept','in_parafering','geparafeerd']]],
		]);
		$this->assertSame([], $errors);
	}

	public function testEmptyTransitionAuthorizationIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'concept',
				'transitions' => [
					'completeren' => ['from' => 'in_parafering', 'to' => 'geparafeerd', 'authorization' => []],
				],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['concept','in_parafering','geparafeerd']]],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-authorization-malformed', $codes);
	}

	public function testMalformedTransitionAuthorizationEntryIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'concept',
				'transitions' => [
					'completeren' => ['from' => 'in_parafering', 'to' => 'geparafeerd', 'authorization' => [123]],
				],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['concept','in_parafering','geparafeerd']]],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-authorization-entry-malformed', $codes);
	}

	// --- Graph mode (fk-graph-lifecycle-transitions) ---------------------

	/**
	 * A well-formed graph block with object-form `initial` passes validation
	 * even though the lifecycle field is a `$ref` with no enum.
	 */
	public function testValidGraphAnnotationPasses(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'initial' => ['from' => 'caseType', 'field' => 'initialStatus'],
				'graph' => [
					'schema' => 'statustype',
					'parentField' => 'caseType',
					'parentFrom' => 'caseType',
					'orderField' => 'order',
					'finalField' => 'isFinal',
					'allowedMoves' => 'forward',
				],
			],
			'properties' => ['status' => ['type' => 'string', 'format' => 'uuid']],
		]);
		$this->assertSame([], $errors);
	}

	/**
	 * Graph mode relaxes the enum requirement: a $ref field without an enum
	 * is accepted (would be rejected in static mode).
	 */
	public function testGraphFieldWithoutEnumIsAccepted(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'graph' => [
					'schema' => 'statustype',
					'parentField' => 'caseType',
					'parentFrom' => 'caseType',
					'orderField' => 'order',
					'finalField' => 'isFinal',
					'allowedMoves' => 'any',
				],
			],
			'properties' => ['status' => ['type' => 'object']],
		]);
		$this->assertSame([], $errors);
	}

	public function testInvalidAllowedMovesIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'graph' => [
					'schema' => 'statustype',
					'parentField' => 'caseType',
					'parentFrom' => 'caseType',
					'orderField' => 'order',
					'finalField' => 'isFinal',
					'allowedMoves' => 'sideways',
				],
			],
			'properties' => ['status' => ['type' => 'string']],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-graph-allowedmoves-invalid', $codes);
	}

	public function testMissingGraphKeyIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'graph' => [
					'schema' => 'statustype',
					// parentField missing.
					'parentFrom' => 'caseType',
					'orderField' => 'order',
					'finalField' => 'isFinal',
					'allowedMoves' => 'forward',
				],
			],
			'properties' => ['status' => ['type' => 'string']],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-graph-missing-key', $codes);
	}

	public function testMalformedObjectInitialIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'initial' => ['from' => 'caseType'],
				// `field` key missing from the object-form initial.
				'graph' => [
					'schema' => 'statustype',
					'parentField' => 'caseType',
					'parentFrom' => 'caseType',
					'orderField' => 'order',
					'finalField' => 'isFinal',
					'allowedMoves' => 'forward',
				],
			],
			'properties' => ['status' => ['type' => 'string']],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-initial-malformed', $codes);
	}

	public function testGraphMissingFieldIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'graph' => [
					'schema' => 'statustype',
					'parentField' => 'caseType',
					'parentFrom' => 'caseType',
					'orderField' => 'order',
					'finalField' => 'isFinal',
					'allowedMoves' => 'forward',
				],
			],
			'properties' => ['status' => ['type' => 'string']],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-missing-key', $codes);
	}

	// --- Transition `condition` (lifecycle-declarative-conditions) -------

	public function testValidRuleObjectConditionProducesNoErrors(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'draft',
				'transitions' => [
					'open' => [
						'from' => ['draft'],
						'to' => 'opened',
						'condition' => ['!!' => ['var' => 'object.motivering']],
					],
				],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft', 'opened']]],
		]);
		$this->assertSame([], $errors);
	}

	/**
	 * 🔴 THE MOST IMPORTANT CASE HERE.
	 *
	 * `"@self.settlementMode == 'reimbursable'"` is the `actions[].condition`
	 * dialect parsed by {@see \OCA\OpenRegister\Service\Lifecycle\LifecycleActionExecutor::evaluateCondition()}.
	 * `FlowExpression::isValid()` returns TRUE for it because a scalar is
	 * always a valid JSONLogic literal — so without validateTransitionCondition()'s
	 * explicit `is_array()` guard, this string would store cleanly on a
	 * transition's `condition` key and then evaluate truthy at runtime,
	 * AUTHORISING every transition it was written to block. This test must
	 * see `lifecycle-condition-malformed`, not an empty error list.
	 */
	public function testScalarConditionIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'draft',
				'transitions' => [
					'settle' => [
						'from' => ['draft'],
						'to' => 'opened',
						'condition' => "@self.settlementMode == 'reimbursable'",
					],
				],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft', 'opened']]],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-condition-malformed', $codes);
	}

	public function testEmptyArrayConditionIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'draft',
				'transitions' => [
					'open' => ['from' => ['draft'], 'to' => 'opened', 'condition' => []],
				],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft', 'opened']]],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-condition-malformed', $codes);
	}

	public function testConditionWithUnknownOperatorIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'draft',
				'transitions' => [
					'open' => [
						'from' => ['draft'],
						'to' => 'opened',
						'condition' => ['notARealJsonLogicOperator' => ['var' => 'object.x']],
					],
				],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft', 'opened']]],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-condition-malformed', $codes);
	}

	// --- Transition `message` (lifecycle-declarative-conditions) ---------

	public function testValidStringMessageProducesNoErrors(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'draft',
				'transitions' => [
					'open' => ['from' => ['draft'], 'to' => 'opened', 'message' => 'Not allowed yet.'],
				],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft', 'opened']]],
		]);
		$this->assertSame([], $errors);
	}

	public function testValidLocaleMapMessageProducesNoErrors(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'draft',
				'transitions' => [
					'open' => [
						'from' => ['draft'],
						'to' => 'opened',
						'message' => ['nl' => 'Nog niet toegestaan.', 'en' => 'Not allowed yet.'],
					],
				],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft', 'opened']]],
		]);
		$this->assertSame([], $errors);
	}

	public function testLocaleMapMessageWithDeclaredDefaultLocaleProducesNoErrors(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'draft',
				'transitions' => [
					'open' => [
						'from' => ['draft'],
						'to' => 'opened',
						'message' => [
							'nl' => 'Nog niet toegestaan.',
							'en' => 'Not allowed yet.',
							'defaultLocale' => 'nl',
						],
					],
				],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft', 'opened']]],
		]);
		$this->assertSame([], $errors);
	}

	public function testNumericMessageIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'draft',
				'transitions' => [
					'open' => ['from' => ['draft'], 'to' => 'opened', 'message' => 42],
				],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft', 'opened']]],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-message-malformed', $codes);
	}

	public function testEmptyStringMessageIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'draft',
				'transitions' => [
					'open' => ['from' => ['draft'], 'to' => 'opened', 'message' => ''],
				],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft', 'opened']]],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-message-malformed', $codes);
	}

	public function testEmptyMapMessageIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'draft',
				'transitions' => [
					'open' => ['from' => ['draft'], 'to' => 'opened', 'message' => []],
				],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft', 'opened']]],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-message-malformed', $codes);
	}

	public function testMapWithOnlyEmptyStringLocaleValuesIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'draft',
				'transitions' => [
					'open' => ['from' => ['draft'], 'to' => 'opened', 'message' => ['nl' => '', 'en' => '']],
				],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft', 'opened']]],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-message-malformed', $codes);
	}

	public function testMessageDefaultLocaleNamingUndeclaredLocaleIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'draft',
				'transitions' => [
					'open' => [
						'from' => ['draft'],
						'to' => 'opened',
						'message' => ['nl' => 'Nog niet toegestaan.', 'defaultLocale' => 'en'],
					],
				],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft', 'opened']]],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-message-malformed', $codes);
	}

	// --- Graph-mode `condition` refusal (fk-graph-lifecycle-transitions) -

	public function testGraphConditionIsRejected(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'graph' => [
					'schema' => 'statustype',
					'parentField' => 'caseType',
					'parentFrom' => 'caseType',
					'orderField' => 'order',
					'finalField' => 'isFinal',
					'allowedMoves' => 'forward',
					'condition' => ['!!' => ['var' => 'object.motivering']],
				],
			],
			'properties' => ['status' => ['type' => 'string']],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-condition-graph-unsupported', $codes);
	}

	/**
	 * A graph block that does not declare `condition` validates exactly as
	 * before this change (no regression from adding the graph-condition
	 * refusal).
	 */
	public function testGraphWithoutConditionValidatesAsBefore(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'graph' => [
					'schema' => 'statustype',
					'parentField' => 'caseType',
					'parentFrom' => 'caseType',
					'orderField' => 'order',
					'finalField' => 'isFinal',
					'allowedMoves' => 'forward',
				],
			],
			'properties' => ['status' => ['type' => 'string']],
		]);
		$this->assertSame([], $errors);
	}

	/**
	 * The graph-condition refusal is scoped to `graph.condition` only. A
	 * `transitions` block that happens to sit alongside a condition-free
	 * `graph` block (graph mode short-circuits before `transitions` is ever
	 * read) must not trip `lifecycle-condition-graph-unsupported`.
	 */
	public function testConditionedStaticTransitionBesideConditionFreeGraphRaisesNoGraphError(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'transitions' => [
					'settle' => [
						'from' => ['draft'],
						'to' => 'opened',
						'condition' => ['!!' => ['var' => 'object.motivering']],
					],
				],
				'graph' => [
					'schema' => 'statustype',
					'parentField' => 'caseType',
					'parentFrom' => 'caseType',
					'orderField' => 'order',
					'finalField' => 'isFinal',
					'allowedMoves' => 'forward',
				],
			],
			'properties' => ['status' => ['type' => 'string']],
		]);
		$codes = array_column($errors, 'code');
		$this->assertNotContains('lifecycle-condition-graph-unsupported', $codes);
	}

	// --- Regression guard --------------------------------------------------

	public function testTransitionWithoutConditionOrMessageProducesNoErrors(): void {
		$errors = $this->v->validate([
			'x-openregister-lifecycle' => [
				'field' => 'lifecycle',
				'initial' => 'draft',
				'transitions' => [
					'open' => ['from' => ['draft'], 'to' => 'opened'],
				],
			],
			'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft', 'opened']]],
		]);
		$this->assertSame([], $errors);
	}
}
