<?php

/**
 * FlowLink entity for linking NC Flow (workflowengine) operations to
 * OpenRegister objects.
 *
 * Tier-2 schema: carries register_id, schema_id, operation_class,
 * operation_name, entity_type, enabled so the link row alone can
 * hydrate the sidebar tab + picker UX without per-operation roundtrips
 * to NC Flow. Replaces the Tier-1 `FlowProvider`'s `[or:{uuid}]`
 * name-marker convention with a proper persistence layer that survives
 * operation renames.
 *
 * Admin-gated write surface: NC Flow operations are configured by
 * admins in NC Workflow Settings; only admins can create FlowLink
 * rows. Everyone can READ the linked rows so non-admins see the
 * automations bound to an OR object.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class FlowLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method int getOperationId()
 * @method void setOperationId(int $operationId)
 * @method string|null getOperationClass()
 * @method void setOperationClass(?string $operationClass)
 * @method string|null getOperationName()
 * @method void setOperationName(?string $operationName)
 * @method string|null getEntityType()
 * @method void setEntityType(?string $entityType)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method string getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class FlowLink extends Entity implements JsonSerializable {

	/**
	 * The object uuid.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The register id.
	 *
	 * @var integer|null
	 */
	protected ?int $registerId = null;

	/**
	 * The schema id.
	 *
	 * @var integer|null
	 */
	protected ?int $schemaId = null;

	/**
	 * The Flow operation id (primary key in `oc_flow_operations`).
	 *
	 * @var integer|null
	 */
	protected ?int $operationId = null;

	/**
	 * The IOperation FQCN that handles this rule.
	 *
	 * @var string|null
	 */
	protected ?string $operationClass = null;

	/**
	 * The operation display name (cached at link time).
	 *
	 * @var string|null
	 */
	protected ?string $operationName = null;

	/**
	 * The entity class the operation is bound to
	 * (e.g. `OCA\WorkflowEngine\Entity\File`).
	 *
	 * @var string|null
	 */
	protected ?string $entityType = null;

	/**
	 * Whether the operation is currently enabled in NC Flow.
	 *
	 * @var boolean|null
	 */
	protected ?bool $enabled = true;

	/**
	 * The linked by uid.
	 *
	 * @var string|null
	 */
	protected ?string $linkedBy = null;

	/**
	 * The linked at timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $linkedAt = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'registerId', type: 'integer');
		$this->addType(fieldName: 'schemaId', type: 'integer');
		$this->addType(fieldName: 'operationId', type: 'integer');
		$this->addType(fieldName: 'operationClass', type: 'string');
		$this->addType(fieldName: 'operationName', type: 'string');
		$this->addType(fieldName: 'entityType', type: 'string');
		$this->addType(fieldName: 'enabled', type: 'boolean');
		$this->addType(fieldName: 'linkedBy', type: 'string');
		$this->addType(fieldName: 'linkedAt', type: 'datetime');
	}//end __construct()

	/**
	 * JSON serialization.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'objectUuid' => $this->objectUuid,
			'registerId' => $this->registerId,
			'schemaId' => $this->schemaId,
			'operationId' => $this->operationId,
			'operationClass' => $this->operationClass,
			'operationName' => $this->operationName,
			'entityType' => $this->entityType,
			'enabled' => (bool)$this->enabled,
			'linkedBy' => $this->linkedBy,
			'linkedAt' => $this->linkedAt?->format(DateTime::ATOM),
		];
	}//end jsonSerialize()
}//end class
