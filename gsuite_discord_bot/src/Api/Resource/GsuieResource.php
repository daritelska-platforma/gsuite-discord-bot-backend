<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Annotation\ApiProperty;

/**
 * @ApiResource(
 *     collectionOperations={"post"},
 *     itemOperations={}
 * )
 */
final class GsuieResource
{

    /**
     * @ApiProperty(identifier=true)
     */
    private $id;

    /**
     * @var string
     */
    private $title;

    /**
     * @var string
     */
    private $start;

    /**
     * @var string
     */
    private $duration;

    /**
     * @var array
     */
    private $participants;

    /**
     * @var string
     */
    private $description;

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed $id
     * @return GsuieResource
     */
    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @param string $title
     * @return GsuieResource
     */
    public function setTitle(string $title): GsuieResource
    {
        $this->title = $title;
        return $this;
    }

    /**
     * @return string
     */
    public function getStart(): string
    {
        return $this->start;
    }

    /**
     * @param string $start
     * @return GsuieResource
     */
    public function setStart(string $start): GsuieResource
    {
        $this->start = $start;
        return $this;
    }

    /**
     * @return string
     */
    public function getDuration(): string
    {
        return $this->duration;
    }

    /**
     * @param string $duration
     * @return GsuieResource
     */
    public function setDuration(string $duration): GsuieResource
    {
        $this->duration = $duration;
        return $this;
    }

    /**
     * @return array
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    /**
     * @param array $participants
     * @return GsuieResource
     */
    public function setParticipants(array $participants): GsuieResource
    {
        $this->participants = $participants;
        return $this;
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @param string $description
     * @return GsuieResource
     */
    public function setDescription(string $description): GsuieResource
    {
        $this->description = $description;
        return $this;
    }



}