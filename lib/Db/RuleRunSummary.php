<?php

/**
 * RuleRunSummary entity: what one rule last did, kept past the prune.
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
 * One summary row per rule.
 *
 * The detail log is pruned; this is not. It is what lets the inventory keep
 * answering "last run" and "last error" for a rule whose rows are gone, which
 * is the whole of D-3, and it is what makes "this rule has not fired in ninety
 * days" answerable without reading the log at all.
 *
 * @method string getRuleId()
 * @method void setRuleId(string $ruleId)
 * @method string getSchemaSlug()
 * @method void setSchemaSlug(string $schemaSlug)
 * @method DateTime|null getLastRun()
 * @method void setLastRun(?DateTime $lastRun)
 * @method string|null getLastVerdict()
 * @method void setLastVerdict(?string $lastVerdict)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method DateTime|null getLastErrorAt()
 * @method void setLastErrorAt(?DateTime $lastErrorAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class RuleRunSummary extends Entity implements JsonSerializable {

	/**
	 * The derived rule id this summary belongs to.
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
	 * When the rule was last evaluated, whatever the verdict.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $lastRun = null;

	/**
	 * The verdict of that last evaluation.
	 *
	 * @var string|null
	 */
	protected ?string $lastVerdict = null;

	/**
	 * The message of the last evaluation that errored.
	 *
	 * @var string|null
	 */
	protected ?string $lastError = null;

	/**
	 * When that last error happened.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $lastErrorAt = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'ruleId', type: 'string');
		$this->addType(fieldName: 'schemaSlug', type: 'string');
		$this->addType(fieldName: 'lastRun', type: 'datetime');
		$this->addType(fieldName: 'lastVerdict', type: 'string');
		$this->addType(fieldName: 'lastError', type: 'string');
		$this->addType(fieldName: 'lastErrorAt', type: 'datetime');

	}//end __construct()

	/**
	 * The summary as the inventory embeds it.
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'ruleId' => $this->ruleId,
			'schema' => $this->schemaSlug,
			'lastRun' => $this->lastRun?->format(DateTime::ATOM),
			'lastVerdict' => $this->lastVerdict,
			'lastError' => $this->lastError,
			'lastErrorAt' => $this->lastErrorAt?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
