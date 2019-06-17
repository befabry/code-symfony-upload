<?php

namespace App\Controller;

use App\API\ArticleReferenceUploadApiModel;
use App\Entity\Article;
use App\Entity\ArticleReference;
use App\Service\UploaderHelper;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\HttpFoundation\File\File as FileObject;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Class ArticleReferenceAdminController
 * @package App\Controller
 */
class ArticleReferenceAdminController extends BaseController
{

    /**
     * @param Article $article
     * @param Request $request
     * @param UploaderHelper $uploaderHelper
     * @param EntityManagerInterface $entityManager
     * @param ValidatorInterface $validator
     * @param SerializerInterface $serializer
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *
     * @Route("/admin/article/{id}/reference",
     *     name="admin_article_add_reference",
     *     methods={"POST"}
     * )
     *
     * @IsGranted("MANAGE", subject="article")
     */
    public function uploadArticleReference(Article $article, Request $request, UploaderHelper $uploaderHelper, EntityManagerInterface $entityManager, ValidatorInterface $validator, SerializerInterface $serializer)
    {
        if($request->headers->get('Content-Type') === 'application/json'){
            /** @var ArticleReferenceUploadApiModel $uploadApiModel */
            $uploadApiModel = $serializer->deserialize(
                $request->getContent(),
                ArticleReferenceUploadApiModel::class,
                'json'
            );

            $violations = $validator->validate($uploadApiModel);
            if($violations->count() > 0){
                return $this->json($violations, Response::HTTP_BAD_REQUEST);
            }

            $tmpPath = sys_get_temp_dir().'/sf_upload'.uniqid();
            file_put_contents($tmpPath, $uploadApiModel->getDecodedData());

            $uploadedFile = new FileObject($tmpPath);
            $originalName = $uploadApiModel->filename;
        } else {
            /** @var UploadedFile $uploadedFile */
            $uploadedFile = $request->files->get('reference');
            $originalName = $uploadedFile->getClientOriginalName();
        }

        //mimeTypes, see MimeTypeExtensionGuesser.php
        $violations = $validator->validate(
            $uploadedFile,
            [
                new NotBlank([
                    'message' => 'Please select a file to upload',
                ]),
                new File([
                    'maxSize' => '5M',
                    'mimeTypes' => [
                        'application/msword',
                        'application/pdf',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'images/*',
                        'text/plain',
                    ],
                ]),
            ]

        );

        if ($violations->count() > 0) {
//            /** @var ConstraintViolation $violation */
//            $violation = $violations[0];
//            $this->addFlash('error', $violation->getMessage());
//
//            return $this->redirectToRoute('admin_article_edit', [
//                'id' => $article->getId(),
//            ]);
            return $this->json($violations, 400);
        }

        $filename = $uploaderHelper->uploadArticleReference($uploadedFile);

        $articleReference = new ArticleReference($article);
        $articleReference->setFilename($filename);
        $articleReference->setOriginalFilename($originalName ?? $filename);
        $articleReference->setMimeType($uploadedFile->getMimeType() ?? 'application/octet-stream');

        //Remove the temporary file created if JSON
        if(is_file($uploadedFile->getPathname())){
            unlink($uploadedFile->getPathname());
        }

        $entityManager->persist($articleReference);
        $entityManager->flush();

        return $this->json(
            $articleReference,
            Response::HTTP_CREATED,
            [],
            [
                'groups' => ['main']
            ]
        );
    }

    /**
     * @param ArticleReference $reference
     * @param S3Client $s3Client
     * @param string $s3BucketName
     * @return RedirectResponse
     *
     * @Route(
     *     "/admin/article/references/{id}/download",
     *     name="admin_article_download_reference",
     *     methods={"GET"}
     *     )
     */
    public function downloadArticleReference(ArticleReference $reference, S3Client $s3Client, string $s3BucketName)
    {
        //IsGranted manually because we do not have access to it directly
        $article = $reference->getArticle();
        $this->denyAccessUnlessGranted('MANAGE', $article);

        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $reference->getOriginalFilename()
        );

        //Creating a presigned URL
        $cmd = $s3Client->getCommand('GetObject', [
            'Bucket' => $s3BucketName,
            'Key' => $reference->getFilePath(),
            'ResponseContentType' => $reference->getMimeType(),
            'ResponseContentDisposition' => $disposition,

        ]);
        $request = $s3Client->createPresignedRequest($cmd, '+5 minutes');

        // Get the actual presigned-url
        $presignedUrl = (string)$request->getUri();

        return new RedirectResponse($presignedUrl);
    }

//    Before S3
//    public function downloadArticleReference(ArticleReference $reference, UploaderHelper $uploaderHelper)
//    {
//        //IsGranted manually because we do not have access to it directly
//        $article = $reference->getArticle();
//        $this->denyAccessUnlessGranted('MANAGE', $article);
//
//        //Avoids heating memory
//        $response = new StreamedResponse(function () use ($reference, $uploaderHelper) {
//            //anything we write in this stream will be echoed out
//            $outputStream = fopen('php://output', 'wb');
//            $fileStream = $uploaderHelper->readStream($reference->getFilePath());
//
//            stream_copy_to_stream($fileStream, $outputStream);
//        });
//
//        $response->headers->set('Content-Type', $reference->getMimeType());
//
//        //force the download of the file
//        $disposition = HeaderUtils::makeDisposition(
//            HeaderUtils::DISPOSITION_ATTACHMENT,
//            $reference->getOriginalFilename()
//        );
//        $response->headers->set('Content-Disposition', $disposition);
//
//        return $response;
//    }

    /**
     * @param Article $article
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *
     * @Route(
     *     "admin/article/{id}/references",
     *     methods={"GET"},
     *     name="admin_article_references_list"
     * )
     * @IsGranted("MANAGE", subject="article")
     */
    public function getArticleReference(Article $article)
    {
        return $this->json(
            $article->getArticleReferences(),
            Response::HTTP_OK,
            [],
            [
                'groups' => ['main'],
            ]);
    }

    /**
     * @param Article $article
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *
     * @Route(
     *     "admin/article/{id}/references/reorder",
     *     methods={"POST"},
     *     name="admin_article_references_reorder"
     * )
     * @IsGranted("MANAGE", subject="article")
     */
    public function reorderArticleReference(Article $article, Request $request, EntityManagerInterface $entityManager)
    {
        $orderedIds = json_decode($request->getContent(), true);

        if($orderedIds === false){
            return $this->json(['detail' => 'Invalid body'], Response::HTTP_BAD_REQUEST);
        }

        //from (position)=>(id) to (id)=>(position)
        $orderedIds = array_flip($orderedIds);
        foreach ($article->getArticleReferences() as $reference){
            $reference->setPosition($orderedIds[$reference->getId()]);
        }

        $entityManager->flush();

        return $this->json(
            $article->getArticleReferences(),
            200,
            [],
            [
                'groups' => ['main'],
            ]);
    }

    /**
     * @param ArticleReference $reference
     * @param UploaderHelper $uploaderHelper
     * @param EntityManagerInterface $entityManager
     *
     * @Route(
     *     "/admin/article/references/{id}",
     *     name="admin_article_reference_delete",
     *     methods={"DELETE"}
     * )
     *
     * @return Response
     * @throws \League\Flysystem\FileNotFoundException
     */
    public function deleteArticleReference(ArticleReference $reference, UploaderHelper $uploaderHelper, EntityManagerInterface $entityManager)
    {
        //IsGranted manually because we do not have access to it directly
        $article = $reference->getArticle();
        $this->denyAccessUnlessGranted('MANAGE', $article);

        $entityManager->remove($reference);
        $entityManager->flush();

        $uploaderHelper->deleteFile($reference->getFilePath());

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param ArticleReference $reference
     * @param UploaderHelper $uploaderHelper
     * @param EntityManagerInterface $entityManager
     * @param SerializerInterface $serializer
     * @param Request $request
     * @param ValidatorInterface $validator
     *
     * @Route(
     *     "/admin/article/references/{id}",
     *     name="admin_article_reference_update",
     *     methods={"PUT"}
     * )
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function updateArticleReference(ArticleReference $reference, UploaderHelper $uploaderHelper, EntityManagerInterface $entityManager, SerializerInterface $serializer, Request $request, ValidatorInterface $validator)
    {
        //IsGranted manually because we do not have access to it directly
        $article = $reference->getArticle();
        $this->denyAccessUnlessGranted('MANAGE', $article);

        $serializer->deserialize(
           $request->getContent(),
           ArticleReference::class,
            'json',
            [
                'object_to_populate' => $reference,
                'groups' => ['input'],
            ]
        );

        $violations = $validator->validate($reference);
        if($violations->count() > 0){
            return $this->json($violations, 400);
        }

        $entityManager->persist($reference);
        $entityManager->flush();

        return $this->json(
            $reference,
            Response::HTTP_OK,
            [],
            [
                'groups' => ['main']
            ]
        );
    }
}
