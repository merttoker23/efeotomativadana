<?php

namespace App\Controller\Admin\Cms;

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
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, CmsMediaStorage $storage): Response
    {
        $path = null;
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('cms_media_upload', $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
            try {
                $file = $request->files->get('image');
                if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) { throw new \InvalidArgumentException('Choose an image.'); }
                $path = $storage->store($file);
            } catch (\InvalidArgumentException $exception) { $error = $exception->getMessage(); }
        }
        return $this->render('admin/cms/media.html.twig', ['path' => $path, 'error' => $error], new Response(status: null === $error ? 200 : 422));
    }
}
