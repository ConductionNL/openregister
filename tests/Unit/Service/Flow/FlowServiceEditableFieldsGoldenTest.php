<?php

/**
 * Characterisation tests for the client-editable field allowlist of FlowService.
 *
 * Pins exactly which keys of an incoming payload reach a flow and how they are
 * cast: string fields (null stored, anything else stringified), array fields
 * (null becomes an empty array), `enabled` cast to bool, the inheritable
 * settings, the `$comment` normalisation (and `comment` winning over it), and
 * that keys outside the allowlist — owner, organisation, uuid, timestamps —
 * and keys absent from the payload are never touched.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\FlowService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Golden tests for applyEditableFields().
 */
class FlowServiceEditableFieldsGoldenTest extends TestCase {

	/**
	 * Apply a payload to a flow through the private allowlist.
	 *
	 * @param Flow                 $flow The flow.
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return void
	 */
	private function apply(Flow $flow, array $data): void {
		$service = (new ReflectionClass(FlowService::class))->newInstanceWithoutConstructor();
		$method  = new ReflectionMethod(FlowService::class, 'applyEditableFields');
		$method->invoke($service, $flow, $data);
	}//end apply()

	/**
	 * A flow with every guarded and editable field set to a known value.
	 *
	 * @return Flow
	 */
	private function storedFlow(): Flow {
		$flow = new Flow();
		$flow->setUuid('uuid-1');
		$flow->setOwner('owner');
		$flow->setOrganisation('org');
		$flow->setCreated(new DateTime('2026-01-01T00:00:00+00:00'));
		$flow->setName('kept');
		$flow->setDescription('kept');
		$flow->setNodes([['id' => 'kept']]);
		$flow->setEnabled(true);

		return $flow;
	}//end storedFlow()

	/**
	 * Every allowlisted key is applied with its cast; nothing else moves.
	 *
	 * @return void
	 */
	public function testEveryAllowlistedKeyIsAppliedWithItsCast(): void {
		$flow = $this->storedFlow();
		$this->apply(
			$flow,
			[
				'name'            => 12,
				'description'     => null,
				'trigger'         => 'object.created',
				'triggerRegister' => 3,
				'triggerSchema'   => '4',
				'cron'            => '* * * * *',
				'executionMode'   => 'async',
				'notes'           => true,
				'comment'         => 'why',
				'$comment'        => 'ignored',
				'applicationSlug' => 'app',
				'nodes'           => null,
				'edges'           => ['e1' => 'x'],
				'limits'          => 'scalar',
				'enabled'         => 0,
				'retentionDays'   => 0,
				'auditEnabled'    => null,
				'owner'           => 'attacker',
				'organisation'    => 'other-org',
				'uuid'            => 'uuid-2',
				'created'         => '2030-01-01',
			]
		);

		$this->assertSame('12', $flow->getName());
		$this->assertNull($flow->getDescription());
		$this->assertSame('object.created', $flow->getTrigger());
		$this->assertSame('3', $flow->getTriggerRegister());
		$this->assertSame('4', $flow->getTriggerSchema());
		$this->assertSame('* * * * *', $flow->getCron());
		$this->assertSame('async', $flow->getExecutionMode());
		$this->assertSame('1', $flow->getNotes());
		$this->assertSame('why', $flow->getComment(), 'the explicit field wins over $comment');
		$this->assertSame('app', $flow->getApplicationSlug());
		$this->assertSame([], $flow->getNodes());
		$this->assertSame(['e1' => 'x'], $flow->getEdges());
		$this->assertSame(['scalar'], $flow->getLimits());
		$this->assertFalse($flow->getEnabled());
		$this->assertSame(1, $flow->getRetentionDays());
		$this->assertNull($flow->getAuditEnabled());

		$this->assertSame('owner', $flow->getOwner());
		$this->assertSame('org', $flow->getOrganisation());
		$this->assertSame('uuid-1', $flow->getUuid());
		$this->assertSame('2026-01-01T00:00:00+00:00', $flow->getCreated()->format('c'));
	}//end testEveryAllowlistedKeyIsAppliedWithItsCast()

	/**
	 * Keys absent from the payload are left alone; `$comment` alone becomes the comment.
	 *
	 * @return void
	 */
	public function testAbsentKeysAreLeftAloneAndDollarCommentIsNormalised(): void {
		$flow = $this->storedFlow();
		$this->apply($flow, ['$comment' => 'from the file', 'enabled' => 'yes']);

		$this->assertSame('from the file', $flow->getComment());
		$this->assertSame('kept', $flow->getName());
		$this->assertSame('kept', $flow->getDescription());
		$this->assertSame([['id' => 'kept']], $flow->getNodes());
		$this->assertTrue($flow->getEnabled());
	}//end testAbsentKeysAreLeftAloneAndDollarCommentIsNormalised()

	/**
	 * A null `$comment` with no `comment` stores a null comment.
	 *
	 * @return void
	 */
	public function testANullDollarCommentStoresNull(): void {
		$flow = $this->storedFlow();
		$flow->setComment('old');
		$this->apply($flow, ['$comment' => null]);

		$this->assertNull($flow->getComment());
	}//end testANullDollarCommentStoresNull()
}//end class
