<?php

namespace App\Controller\Security;

use App\Form\Customer\PasswordResetRequestType;
use App\Form\Customer\PasswordResetType;
use App\Module\Customer\PasswordResetData;
use App\Module\Customer\PasswordResetMailer;
use App\Module\Customer\PasswordResetManager;
use App\Module\Customer\PasswordResetRequestData;
use App\Repository\Customer\CustomerUserRepository;
use App\Repository\Customer\PasswordResetTokenRepository;
use App\Shared\StorefrontPageContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetController extends AbstractController
{
    #[Route('/parolami-unuttum', name: 'customer_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request, StorefrontPageContext $pageContext, CustomerUserRepository $customers, PasswordResetManager $manager, PasswordResetMailer $mailer): Response
    {
        $data = new PasswordResetRequestData();
        $form = $this->createForm(PasswordResetRequestType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $customer = $customers->findOneByEmail($data->email);
            if (null !== $customer && $customer->isActive()) {
                $mailer->send($customer, $manager->issue($customer));
            }
            $this->addFlash('success', 'E-posta adresi kayıtlıysa sıfırlama bağlantısı gönderildi.');
            return $this->redirectToRoute('customer_password_request');
        }

        return $this->render('security/customer/password_request.html.twig', $pageContext->withLayout([
            'password_request_form' => $form,
        ]), new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/parola-sifirla/{token}', name: 'customer_password_reset', requirements: ['token' => '[^/]+'], methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request, PasswordResetTokenRepository $tokens, UserPasswordHasherInterface $hasher, EntityManagerInterface $entityManager, StorefrontPageContext $pageContext): Response
    {
        $resetToken = $tokens->findByRawToken($token);
        $now = new \DateTimeImmutable();
        if (null === $resetToken || !$resetToken->isUsableAt($now)) {
            return $this->render('security/customer/password_reset_invalid.html.twig', $pageContext->withLayout(), new Response(status: Response::HTTP_GONE));
        }
        $data = new PasswordResetData();
        $form = $this->createForm(PasswordResetType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $customer = $resetToken->customer();
            $customer->setPassword($hasher->hashPassword($customer, $data->newPassword));
            $resetToken->consume($now);
            $entityManager->flush();
            $this->addFlash('success', 'Parolanız sıfırlandı. Şimdi giriş yapabilirsiniz.');
            return $this->redirectToRoute('customer_login');
        }
        return $this->render('security/customer/password_reset.html.twig', $pageContext->withLayout(['password_reset_form' => $form]), new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
