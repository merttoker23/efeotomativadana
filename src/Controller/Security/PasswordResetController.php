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
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetController extends AbstractController
{
    /**
     * Asking for a reset is limited per address.
     *
     * The controller already refuses to say whether an address is registered, so this endpoint
     * is not a way to enumerate accounts — it is a way to make this store send mail. Five
     * requests an hour per address is enough for somebody who has genuinely forgotten their
     * password several times in a row, and it caps the damage a script can do both to this
     * store's mail reputation and to whatever address it is aiming at.
     */
    #[Route('/parolami-unuttum', name: 'customer_password_request', methods: ['GET', 'POST'])]
    #[RateLimit('password_reset_request', methods: ['POST'])]
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

    /**
     * Redeeming a token is limited per address, keyed on the address alone.
     *
     * The default key would include the request path — and the path contains the token, so
     * every guess would get its own budget and the limiter would limit nothing. The token
     * itself is 32 random bytes and single-use, so this is not the control that makes guessing
     * infeasible; it is the control that stops one address grinding through tokens regardless.
     */
    #[Route('/parola-sifirla/{token}', name: 'customer_password_reset', requirements: ['token' => '[^/]+'], methods: ['GET', 'POST'])]
    #[RateLimit('password_reset_exchange', key: "request.getClientIp() ?? 'unknown'", methods: ['POST'])]
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
