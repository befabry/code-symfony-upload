<?php

namespace App\Controller;

use App\Entity\Article;
use App\Entity\ArticleReference;
use App\Service\UploaderHelper;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
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
     * @Route("/admin/article/{id}/reference",
     *     name="admin_article_add_reference",
     *     methods={"POST"}
     * )
     * @IsGranted("MANAGE", subject="article")
     */
    public function uploadArticleReference(Article $article, Request $request, UploaderHelper $uploaderHelper, EntityManagerInterface $entityManager, ValidatorInterface $validator)
    {
        /** @var UploadedFile $uploadedFile */
        $uploadedFile = $request->files->get('reference');
        dump($uploadedFile);

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
        $articleReference->setOriginalFilename($uploadedFile->getClientOriginalName() ?? $filename);
        $articleReference->setMimeType($uploadedFile->getMimeType() ?? 'application/octet-stream');

        $entityManager->persist($articleReference);
        $entityManager->flush();

        return $this->json(
            $articleReference,
            201,
            [],
            [
                'groups' => ['main']
            ]
        );
    }

    /**
     * @Route(
     *     "/admin/article/references/{id}/download",
     *     name="admin_article_download_reference",
     *     methods={"GET"}
     *     )
     *
     * @param ArticleReference $reference
     * @param UploaderHelper $uploaderHelper
     * @return StreamedResponse
     */
    public function downloadArticleReference(ArticleReference $reference, UploaderHelper $uploaderHelper)
    {
        //IsGranted manually because we do not have access to it directly
        $article = $reference->getArticle();
        $this->denyAccessUnlessGranted('MANAGE', $article);

        //Avoids heating memory
        $response = new StreamedResponse(function () use ($reference, $uploaderHelper) {
            //anything we write in this stream will be echoed out
            $outputStream = fopen('php://output', 'wb');
            $fileStream = $uploaderHelper->readStream($reference->getFilePath(), false);

            stream_copy_to_stream($fileStream, $outputStream);
        });

        $response->headers->set('Content-Type', $reference->getMimeType());

        //force the download of the file
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $reference->getOriginalFilename()
        );
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    /**
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

        $uploaderHelper->deleteFile($reference->getFilePath(), false);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param ArticleReference $reference
     * @param UploaderHelper $uploaderHelper
     * @param EntityManagerInterface $entityManager
     * @param SerializerInterface $serializer
     * @param Request $request
     *
     * @Route(
     *     "/admin/article/references/{id}",
     *     name="admin_article_reference_update",
     *     methods={"PUT"}
     * )
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function updateArticleReference(ArticleReference $reference, UploaderHelper $uploaderHelper, EntityManagerInterface $entityManager, SerializerInterface $serializer, Request $request)
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

        $entityManager->persist($reference);
        $entityManager->flush();

        return $this->json(
            $reference,
            200,
            [],
            [
                'groups' => ['main']
            ]
        );
    }
}
