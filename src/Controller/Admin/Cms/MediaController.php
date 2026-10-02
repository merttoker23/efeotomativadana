<?php

namespace App\Controller\Admin\Cms;

use App\Module\Audit\AuditAction;
use App\Module\Audit\AuditLogger;
use App\Module\Cms\CmsMediaStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/cms/media', name: 'admin_cms_media_')]
#[IsGranted('ROLE_ADMIN')]
final class MediaController extends AbstractController
{
    /**
     * `GET` renders the form, `POST` stores one file.
     *
     * The upload is the only route in this application where a staff member hands the server a
     * file, so it is the only one that gets an audit row as well as the storage layer's own
     * byte-level checks: a stored path is recorded so "where did this image come from" is
     * answerable after the fact, and the original file name is deliberately *not* recorded,
     * because a client-supplied name is both untrustworthy and the one field on this form that
     * could carry a path traversal or a hostile extension into a log.
     */
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, CmsMediaStorage $storage, AuditLogger $audit): Response
    {
        $path = null;
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('cms_media_upload', $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
            try {
                $file = $request->files->get('image');
                if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) { throw new \InvalidArgumentException('Choose an image.'); }
                $path = $storage->store($file);
                $audit->record(AuditAction::MediaUploaded, $path, [
                    'bytes' => $file->getSize(),
                    'media_type' => $file->getClientMimeType(),
                ]);
            } catch (\InvalidArgumentException $exception) { $error = $exception->getMessage(); }
        }
        return $this->render('admin/cms/media.html.twig', [
            'path' => $path,
            'error' => $error,
            // The section forms read their image picker from the same library, so what an operator
            // sees here is exactly what the next hero or banner field will offer.
            'files' => $storage->library(200),
        ], new Response(status: null === $error ? 200 : 422));
    }
}
