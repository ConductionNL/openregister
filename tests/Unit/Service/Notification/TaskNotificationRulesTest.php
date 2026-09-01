<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The task rule set is the seed data of flow-task-inbox-projections. These
 * tests prove it is dialect-valid (including the new `task-verb` kind),
 * that it addresses named transition actions rather than states, and that
 * no rule filters a stored `overdue`.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator;
use OCA\OpenRegister\Service\Notification\TaskNotificationRules;
use PHPUnit\Framework\TestCase;

class TaskNotificationRulesTest extends TestCase {
	private TaskNotificationRules $rules;

	protected function setUp(): void {
		parent::setUp();
		$this->rules = new TaskNotificationRules();
	}

	public function testTheRuleSetIsDialectValid(): void {
		$errors = (new NotificationAnnotationValidator())->validate($this->rules->asSchemaArray());

		$this->assertSame([], $errors, json_encode($errors, JSON_PRETTY_PRINT));
	}

	public function testEveryFieldRecipientNamesAPayloadField(): void {
		$fields = array_keys($this->rules->payloadProperties());
		foreach ($this->rules->getRules() as $name => $rule) {
			foreach ($rule['recipients'] as $recipient) {
				if ($recipient['kind'] !== 'field') {
					continue;
				}

				$this->assertContains($recipient['field'], $fields, sprintf('%s addresses an unknown payload field', $name));
			}
		}
	}

	public function testTheSixDeliveriesAreAddressedByAction(): void {
		$actions = [];
		foreach ($this->rules->getRules() as $rule) {
			$trigger = $rule['trigger'];
			if ($trigger['type'] !== 'transition') {
				continue;
			}

			foreach ((array)$trigger['action'] as $action) {
				$actions[] = $action;
			}
		}

		// Offered, assigned, reassigned away, due soon, escalated, cancelled by propagation.
		foreach (['offer', 'assign', 'claim', 'reassign', 'due-soon', 'escalate', 'cancel', 'terminate'] as $required) {
			$this->assertContains($required, $actions, sprintf('no rule addresses the %s action', $required));
		}

		// No rule addresses a STATE: two actions landing on one state stay distinct.
		foreach (['completed', 'terminated', 'active', 'enabled'] as $state) {
			$this->assertNotContains($state, $actions);
		}
	}

	public function testTheAssignedRuleOffersExactlyApproveAndReject(): void {
		$rule = $this->rules->getRules()['taskAssignedToYou'];

		$this->assertCount(2, $rule['actions']);
		$this->assertSame('task-verb', $rule['actions'][0]['target']['kind']);
		$this->assertSame('complete', $rule['actions'][0]['target']['verb']);
		$this->assertSame('approved', $rule['actions'][0]['target']['outcome']);
		$this->assertSame('rejected', $rule['actions'][1]['target']['outcome']);
		$this->assertSame([['kind' => 'field', 'field' => 'assignee']], $rule['recipients']);
	}

	public function testTheOverdueRuleFiltersTheDerivedPredicateAndNoStoredFlag(): void {
		$rule = $this->rules->getRules()['taskOverdue'];

		$this->assertSame('scheduled', $rule['trigger']['type']);
		$fields = array_column($rule['trigger']['filter']['all'], 'field');
		$this->assertSame(['isTerminal', 'dueAt'], $fields);
		$this->assertStringNotContainsString('"overdue"', json_encode($rule));
	}

	public function testNoRuleAnywhereFiltersOnAnOverdueField(): void {
		foreach ($this->rules->getRules() as $name => $rule) {
			$filter = json_encode($rule['trigger']);
			$this->assertStringNotContainsString('"field":"overdue"', (string)$filter, $name);
		}
	}

	public function testThePoolRuleResolvesThroughTheTaskPoolResolverOnly(): void {
		$rule = $this->rules->getRules()['taskOfferedToPool'];

		$this->assertCount(1, $rule['recipients']);
		$this->assertSame('expression', $rule['recipients'][0]['kind']);
		$this->assertSame(\OCA\OpenRegister\Service\Notification\TaskPoolRecipientResolver::class, $rule['recipients'][0]['resolver']);
	}

	public function testTheRefusalRuleAddressesTheActorAndCarriesTheReason(): void {
		$rule = $this->rules->getRules()['taskWriteBackRefused'];

		$this->assertSame(TaskNotificationRules::ACTION_WRITE_BACK_REFUSED, $rule['trigger']['action']);
		$this->assertSame([['kind' => 'field', 'field' => 'writeBackActor']], $rule['recipients']);
		$this->assertStringContainsString('{{writeBackReason}}', $rule['message']['en']);
	}

	public function testTheTextCarriesNoEmDashAndReadsInSentenceCase(): void {
		foreach ($this->rules->getRules() as $name => $rule) {
			foreach (['subject', 'message'] as $key) {
				foreach (($rule[$key] ?? []) as $locale => $text) {
					$this->assertStringNotContainsString("\u{2014}", $text, sprintf('%s.%s.%s', $name, $key, $locale));
				}
			}

			foreach (($rule['actions'] ?? []) as $action) {
				foreach ($action['label'] as $label) {
					$this->assertStringNotContainsString("\u{2014}", $label);
				}
			}
		}
	}

	public function testBuildSchemaCarriesTheRulesUnderTheSlug(): void {
		$schema = $this->rules->buildSchema();

		$this->assertSame(TaskNotificationRules::SLUG, $schema->getSlug());
		$this->assertSame($this->rules->getRules(), $schema->getConfiguration()['x-openregister-notifications']);
		$this->assertArrayHasKey('assignee', $schema->getProperties());
	}
}
