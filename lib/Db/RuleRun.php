<?php

/**
 * RuleRun entity: one rule evaluation, with the operand that decided it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One row of the rule run log.
 *
 * The row is the functional administrator's artefact, not the engineer's: it
 * says which rule, on which object, reached which verdict, and when the verdict
 * was not `fired`, which operand decided and what that operand read. The full
 * per-node trace of a flow run stays in the flow run log, which already records
 * what each node received and returned.
 *
 * @method string getRuleId()
 * @method void setRuleId(string $ruleId)
 * @method string getSchemaSlug()
 * @method void setSchemaSlug(string $schemaSlug)
 * @method string|null getRegisterSlug()
 * @method void setRegisterSlug(?string $registerSlug)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string getVerdict()
 * @method void setVerdict(string $verdict)
 * @method string|null getOperand()
 * @method void setOperand(?string $operand)
 * @method string|null getOperandValue()
 * @method void setOperandValue(?string $operandValue)
 * @method string|null getMessage()
 * @method void setMessage(?string $message)
 * @method string|null getActor()
 * @method void setActor(?string $actor)
 * @method DateTime|null getCreated()
 * @method void setCreated(DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class RuleRun extends Entity implements JsonSerializable {

	/**
	 * The derived rule id this evaluation belongs to.
	 *
	 * @var string|null
	 */
	protected ?string $ruleId = null;

	/**
	 * The slug of the schema the rule is declared on.
	 *
	 * @var string|null
	 */
	protected ?string $schemaSlug = null;

	/**
	 * The slug of the register the object lives in, when one is known.
	 *
	 * @var string|null
	 */
	protected ?string $registerSlug = null;

	/**
	 * The object the rule was evaluated against, when there was one.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The verdict, one of the RuleVocabulary VERDICT_ constants.
	 *
	 * @var string|null
	 */
	protected ?string $verdict = null;

	/**
	 * The first operand that decided against the rule.
	 *
	 * @var string|null
	 */
	protected ?string $operand = null;

	/**
	 * The value that operand read, rendered and bounded.
	 *
	 * @var string|null
	 */
	protected ?string $operandValue = null;

	/**
	 * The engine's own sentence about this evaluation.
	 *
	 * @var string|null
	 */
	protected ?string $message = null;

	/**
	 * The uid whose write the evaluation ran under, when there was a session.
	 *
	 * @var string|null
	 */
	protected ?string $actor = null;

	/**
	 * When the evaluation happened.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'ruleId', type: 'string');
		$this->addType(fieldName: 'schemaSlug', type: 'string');
		$this->addType(fieldName: 'registerSlug', type: 'string');
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'verdict', type: 'string');
		$this->addType(fieldName: 'operand', type: 'string');
		$this->addType(fieldName: 'operandValue', type: 'string');
		$this->addType(fieldName: 'message', type: 'string');
		$this->addType(fieldName: 'actor', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * The row as the run log API returns it.
	 *
	 * @return array<string, mixed> The run.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'ruleId' => $this->ruleId,
			'schema' => $this->schemaSlug,
			'register' => $this->registerSlug,
			'objectUuid' => $this->objectUuid,
			'verdict' => $this->verdict,
			'operand' => $this->operand,
			'operandValue' => $this->operandValue,
			'message' => $this->message,
			'actor' => $this->actor,
			'created' => $this->created?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
