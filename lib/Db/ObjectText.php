<?php

declare(strict_types=1);

/*
 * ObjectText entity stores flattened text extracted from OpenRegister objects.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author  Conduction Development Team
 * @license AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ObjectText
 *
 * Represents the text blob generated from an OpenRegister object.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method int getObjectId()
 * @method void setObjectId(int $objectId)
 * @method string getRegister()
 * @method void setRegister(string $register)
 * @method string getSchema()
 * @method void setSchema(string $schema)
 * @method string getTextBlob()
 * @method void setTextBlob(string $textBlob)
 * @method int getTextLength()
 * @method void setTextLength(int $textLength)
 * @method array|null getPropertyMap()
 * @method void setPropertyMap(?array $propertyMap)
 * @method string getExtractionStatus()
 * @method void setExtractionStatus(string $extractionStatus)
 * @method bool getChunked()
 * @method void setChunked(bool $chunked)
 * @method int getChunkCount()
 * @method void setChunkCount(int $chunkCount)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class ObjectText extends Entity implements JsonSerializable
{

    protected ?string $uuid = null;

    protected ?int $objectId = null;

    protected ?string $register = null;

    protected ?string $schema = null;

    protected ?string $textBlob = null;

    protected int $textLength = 0;

    protected ?array $propertyMap = null;

    protected string $extractionStatus = 'completed';

    protected bool $chunked = false;

    protected int $chunkCount = 0;

    protected ?string $owner = null;

    protected ?string $organisation = null;

    protected ?DateTime $createdAt = null;

    protected ?DateTime $updatedAt = null;


    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('objectId', 'integer');
        $this->addType('register', 'string');
        $this->addType('schema', 'string');
        $this->addType('textBlob', 'string');
        $this->addType('textLength', 'integer');
        $this->addType('propertyMap', 'json');
        $this->addType('extractionStatus', 'string');
        $this->addType('chunked', 'boolean');
        $this->addType('chunkCount', 'integer');
        $this->addType('owner', 'string');
        $this->addType('organisation', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');

    }//end __construct()


    /**
     * JSON serialization.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'           => $this->id,
            'uuid'         => $this->uuid,
            'objectId'     => $this->objectId,
            'register'     => $this->register,
            'schema'       => $this->schema,
            'textLength'   => $this->textLength,
            'chunked'      => $this->chunked,
            'chunkCount'   => $this->chunkCount,
            'owner'        => $this->owner,
            'organisation' => $this->organisation,
            'createdAt'    => $this->createdAt?->format(DateTime::ATOM),
            'updatedAt'    => $this->updatedAt?->format(DateTime::ATOM),
        ];

    }//end jsonSerialize()


}//end class
