<?php

declare(strict_types=1);

namespace OCA\Crate\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getArtist()
 * @method void setArtist(string $artist)
 * @method string getFormat()
 * @method void setFormat(string $format)
 * @method int|null getYear()
 * @method void setYear(?int $year)
 * @method string|null getBarcode()
 * @method void setBarcode(?string $barcode)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getDiscogsId()
 * @method void setDiscogsId(?string $discogsId)
 * @method string|null getArtworkPath()
 * @method void setArtworkPath(?string $artworkPath)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class MediaItem extends Entity {
	protected string $userId = '';
	protected string $title = '';
	protected string $artist = '';
	protected string $format = '';
	protected ?int $year = null;
	protected ?string $barcode = null;
	protected ?string $notes = null;
	protected string $status = 'owned';
	protected ?string $discogsId = null;
	protected ?string $artworkPath = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('year', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id'          => $this->id,
			'userId'      => $this->userId,
			'title'       => $this->title,
			'artist'      => $this->artist,
			'format'      => $this->format,
			'year'        => $this->year,
			'barcode'     => $this->barcode,
			'notes'       => $this->notes,
			'status'      => $this->status,
			'discogsId'   => $this->discogsId,
			'artworkPath' => $this->artworkPath,
			'createdAt'   => $this->createdAt?->format('c'),
			'updatedAt'   => $this->updatedAt?->format('c'),
		];
	}
}
