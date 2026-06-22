<?php

declare(strict_types=1);

namespace OCA\Crate\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getOwnerUserId()
 * @method void setOwnerUserId(string $ownerUserId)
 * @method string getSharedWithUserId()
 * @method void setSharedWithUserId(string $sharedWithUserId)
 * @method string getShareableType()
 * @method void setShareableType(string $shareableType)
 * @method int getShareableId()
 * @method void setShareableId(int $shareableId)
 * @method string getShareableCategory()
 * @method void setShareableCategory(string $shareableCategory)
 * @method string|null getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class CrateShare extends Entity implements \JsonSerializable
{
    public const TYPE_ALBUM    = 'album';
    public const TYPE_PLAYLIST = 'playlist';
    public const TYPE_LIBRARY  = 'library';
    public const TYPE_CATEGORY = 'category';

    public const ALL_TYPES = [self::TYPE_ALBUM, self::TYPE_PLAYLIST, self::TYPE_LIBRARY, self::TYPE_CATEGORY];

    protected string $ownerUserId = '';
    protected string $sharedWithUserId = '';
    protected string $shareableType = '';
    protected int $shareableId = 0;
    protected string $shareableCategory = '';
    protected ?string $createdAt = null;

    public function __construct()
    {
        $this->addType('shareableId', 'integer');
    }

    public function jsonSerialize(): array
    {
        return [
            'id'                => $this->id,
            'ownerUserId'       => $this->ownerUserId,
            'sharedWithUserId'  => $this->sharedWithUserId,
            'shareableType'     => $this->shareableType,
            'shareableId'       => $this->shareableId,
            'shareableCategory' => $this->shareableCategory,
            'createdAt'         => $this->createdAt,
        ];
    }
}
