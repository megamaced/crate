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
 * @method string getPermission()
 * @method void setPermission(string $permission)
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

    /** Read-only share: recipient can view the shared items but not change them. */
    public const PERMISSION_READ = 'read';
    /**
     * Read/write share: recipient can add and edit items within the shared
     * scope. They cannot delete items, nor manage the share itself.
     */
    public const PERMISSION_READWRITE = 'readwrite';

    public const ALL_PERMISSIONS = [self::PERMISSION_READ, self::PERMISSION_READWRITE];

    /**
     * Sentinel for shareable_category when the share's type doesn't carry a
     * category (album / playlist / library). NC's migration validator rejects
     * NOT NULL columns with an empty-string default, so we use '-' instead.
     */
    public const CATEGORY_NONE = '-';

    protected string $ownerUserId = '';
    protected string $sharedWithUserId = '';
    protected string $shareableType = '';
    protected int $shareableId = 0;
    protected string $shareableCategory = self::CATEGORY_NONE;
    protected string $permission = self::PERMISSION_READ;
    protected ?string $createdAt = null;

    public function __construct()
    {
        $this->addType('shareableId', 'integer');
    }

    /** True if the recipient may add/edit items within this share's scope. */
    public function canWrite(): bool
    {
        return $this->permission === self::PERMISSION_READWRITE;
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
            'permission'        => $this->permission,
            'canWrite'          => $this->canWrite(),
            'createdAt'         => $this->createdAt,
        ];
    }
}
