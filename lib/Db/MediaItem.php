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
 * @method string|null getLabel()
 * @method void setLabel(?string $label)
 * @method string|null getCountry()
 * @method void setCountry(?string $country)
 * @method string|null getGenres()
 * @method void setGenres(?string $genres)
 * @method string|null getTracklist()
 * @method void setTracklist(?string $tracklist)
 * @method string|null getPressingNotes()
 * @method void setPressingNotes(?string $pressingNotes)
 * @method string|null getDiscogsArtistId()
 * @method void setDiscogsArtistId(?string $discogsArtistId)
 * @method string|null getArtistBio()
 * @method void setArtistBio(?string $artistBio)
 * @method string|null getArtistMembers()
 * @method void setArtistMembers(?string $artistMembers)
 * @method string|null getOriginalTitle()
 * @method void setOriginalTitle(?string $originalTitle)
 * @method string|null getOriginalArtist()
 * @method void setOriginalArtist(?string $originalArtist)
 * @method int|null getOriginalYear()
 * @method void setOriginalYear(?int $originalYear)
 * @method string|null getOriginalArtworkPath()
 * @method void setOriginalArtworkPath(?string $originalArtworkPath)
 * @method string|null getOriginalLabel()
 * @method void setOriginalLabel(?string $originalLabel)
 * @method string|null getOriginalCountry()
 * @method void setOriginalCountry(?string $originalCountry)
 * @method float|null getMarketValue()
 * @method void setMarketValue(?float $marketValue)
 * @method float|null getMarketValueLoose()
 * @method void setMarketValueLoose(?float $marketValueLoose)
 * @method float|null getMarketValueNew()
 * @method void setMarketValueNew(?float $marketValueNew)
 * @method string|null getMarketValueCurrency()
 * @method void setMarketValueCurrency(?string $marketValueCurrency)
 * @method string|null getMarketValueFetchedAt()
 * @method void setMarketValueFetchedAt(?string $marketValueFetchedAt)
 * @method string getCategory()
 * @method void setCategory(string $category)
 * @method string|null getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class MediaItem extends Entity implements \JsonSerializable
{
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
    protected ?string $label = null;
    protected ?string $country = null;
    protected ?string $genres = null;
    protected ?string $tracklist = null;
    protected ?string $pressingNotes = null;
    protected ?string $discogsArtistId = null;
    protected ?string $artistBio = null;
    protected ?string $artistMembers = null;
    protected ?string $originalTitle = null;
    protected ?string $originalArtist = null;
    protected ?int $originalYear = null;
    protected ?string $originalArtworkPath = null;
    protected ?string $originalLabel = null;
    protected ?string $originalCountry = null;
    protected ?float $marketValue = null;
    protected ?float $marketValueLoose = null;
    protected ?float $marketValueNew = null;
    protected ?string $marketValueCurrency = null;
    protected ?string $marketValueFetchedAt = null;
    protected string $category = 'music';
    protected ?string $createdAt = null;
    protected ?string $updatedAt = null;

    public function __construct()
    {
        $this->addType('year', 'integer');
        $this->addType('originalYear', 'integer');
        $this->addType('marketValue', 'float');
        $this->addType('marketValueLoose', 'float');
        $this->addType('marketValueNew', 'float');
    }

    public function jsonSerialize(): array
    {
        return [
            'id'              => $this->id,
            'userId'          => $this->userId,
            'title'           => $this->title,
            'artist'          => $this->artist,
            'format'          => $this->format,
            'year'            => $this->year,
            'barcode'         => $this->barcode,
            'notes'           => $this->notes,
            'status'          => $this->status,
            'discogsId'       => $this->discogsId,
            'artworkPath'     => $this->artworkPath,
            'label'           => $this->label,
            'country'         => $this->country,
            'genres'          => $this->genres,
            'tracklist'       => $this->tracklist !== null
                ? json_decode($this->tracklist, true)
                : null,
            'pressingNotes'   => $this->pressingNotes,
            'discogsArtistId' => $this->discogsArtistId,
            'artistBio'       => $this->artistBio,
            'artistMembers'   => $this->artistMembers !== null
                ? json_decode($this->artistMembers, true)
                : null,
            'marketValue'          => $this->marketValue,
            'marketValueLoose'     => $this->marketValueLoose,
            'marketValueNew'       => $this->marketValueNew,
            'marketValueCurrency'  => $this->marketValueCurrency,
            'marketValueFetchedAt' => $this->marketValueFetchedAt,
            'category'        => $this->category,
            'createdAt'       => $this->createdAt,
            'updatedAt'       => $this->updatedAt,
        ];
    }
}
