<?php


namespace App\Service;


use Gedmo\Sluggable\Util\Urlizer;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\FilesystemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Asset\Context\RequestStackContext;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class UploaderHelper
{
    const ARTICLE_IMAGE = 'article_image';

    /**
     * @var RequestStackContext
     */
    private $requestStackContext;
    /**
     * @var FilesystemInterface
     */
    private $filesystem;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var string
     */
    private $publicAssetsBaseUrl;

    public function __construct(FilesystemInterface $publicUploadFilesystem, RequestStackContext $requestStackContext, LoggerInterface $logger, string $uploadedAssetsBaseUrl)
    {
        $this->requestStackContext = $requestStackContext;
        $this->filesystem = $publicUploadFilesystem;
        $this->logger = $logger;
        $this->publicAssetsBaseUrl = $uploadedAssetsBaseUrl;
    }

    public function uploadedArticleImage(File $file, ?string $existingFilename): string
    {
        if($file instanceof UploadedFile){
            $originalFileName = $file->getClientOriginalName();
        } else {
            $originalFileName = $file->getFilename();
        }

        $newFilename = Urlizer::urlize(pathinfo($originalFileName, PATHINFO_FILENAME)).'-'.uniqid().'.'.$file->guessExtension();

        $stream = fopen($file->getPathname(), 'r');
        //write use too much memory by loading the file. writeStream use a stream.
        $result = $this->filesystem->writeStream(
          self::ARTICLE_IMAGE.'/'.$newFilename,
          $stream
        );

        if($result === false){
            throw new \Exception(sprintf('Could not write uploaded file "%s"', self::ARTICLE_IMAGE.'/'.$newFilename));
        }

        if(is_resource($stream)){
            fclose($stream);
        }

        if($existingFilename){
            try{
                $result = $this->filesystem->delete(self::ARTICLE_IMAGE.'/'.$existingFilename);
                if($result === false){
                    throw new \Exception(sprintf('Could not write delete old uploaded file "%s"', self::ARTICLE_IMAGE.'/'.$newFilename));
                }
            } catch (FileNotFoundException $e){
                $this->logger->alert(sprintf('Old uploaded file "%s" was missing when trying to delete', $existingFilename));
            }

        }

        return $newFilename;
    }

    public function getPublicPath(string $path): string
    {
        return $this->requestStackContext->getBasePath().$this->publicAssetsBaseUrl.'/'. $path;
    }
}