<?php


namespace App\API;


use Symfony\Component\Validator\Constraints as Assert;

class ArticleReferenceUploadApiModel
{
    /**
     * @Assert\NotBlank()
     */
    public $filename;

    /**
     * @Assert\NotBlank()
     */
    private $data;

    private $decodedData;


    /**
     * @param string|null $data
     * @return ArticleReferenceUploadApiModel
     */
    public function setData(?string $data): self
    {
        $this->data = $data;
        $this->decodedData = base64_decode($this->data);

        return $this;
    }

    /**
     * @return string|null
     */
    public function getDecodedData(): ?string
    {
        return $this->decodedData;
    }


}