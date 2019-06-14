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
    const ARTICLE_REFERENCE = 'article_reference';

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
    /**
     * @var FilesystemInterface
     */
    private $privateFilesystem;

    public function __construct(FilesystemInterface $publicUploadFilesystem, FilesystemInterface $privateUploadFilesystem, RequestStackContext $requestStackContext, LoggerInterface $logger, string $uploadedAssetsBaseUrl)
    {
        $this->requestStackContext = $requestStackContext;
        $this->filesystem = $publicUploadFilesystem;
        $this->logger = $logger;
        $this->publicAssetsBaseUrl = $uploadedAssetsBaseUrl;
        $this->privateFilesystem = $privateUploadFilesystem;
    }

    public function uploadedArticleImage(File $file, ?string $existingFilename): string
    {
        $newFilename = $this->uploadFile($file, self::ARTICLE_IMAGE, true);

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

    public function uploadArticleReference(File $file): string
    {
       return $this->uploadFile($file, self::ARTICLE_REFERENCE, false);
    }

    public function getPublicPath(string $path): string
    {
        return $this->requestStackContext->getBasePath().$this->publicAssetsBaseUrl.'/'. $path;
    }

    /**
     * @param string $path
     * @param bool $isPublic
     * @return resource
     * @throws FileNotFoundException
     */
    public function readStream(string $path, bool $isPublic)
    {
        $filesystem = $isPublic ? $this->filesystem : $this->privateFilesystem;

        $resource = $filesystem->readStream($path);

        if($resource === false){
            throw new \Exception(sprintf('Error opening stream for "%s"', $path));
        }

        return $resource;
    }

    /**
     * @param string $path
     * @param bool $isPublic
     * @throws FileNotFoundException
     */
    public function deleteFile(string $path, bool $isPublic)
    {
        $filesystem = $isPublic ? $this->filesystem : $this->privateFilesystem;

        $result = $filesystem->delete($path);

        if($result === false){
            throw new \Exception(sprintf('Error deleting "%s"', $path));
        }

//        return $resource;
    }

    private function uploadFile(File $file, string $directory, bool $isPublic): string
    {
        if($file instanceof UploadedFile){
            $originalFileName = $file->getClientOriginalName();
        } else {
            $originalFileName = $file->getFilename();
        }

        $newFilename = Urlizer::urlize(pathinfo($originalFileName, PATHINFO_FILENAME)).'-'.uniqid().'.'.$file->guessExtension();

        $filesystem = $isPublic ? $this->filesystem : $this->privateFilesystem;

        $stream = fopen($file->getPathname(), 'r');
        //write use too much memory by loading the file. writeStream use a stream.
        $result = $filesystem->writeStream(
            $directory.'/'.$newFilename,
            $stream
        );

        if($result === false){
            throw new \Exception(sprintf('Could not write uploaded file "%s"', self::ARTICLE_IMAGE.'/'.$newFilename));
        }

        if(is_resource($stream)){
            fclose($stream);
        }

        return $newFilename;
    }
}