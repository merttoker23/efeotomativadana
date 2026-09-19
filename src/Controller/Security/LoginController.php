<?php

namespace App\Controller\Security;

use App\Entity\Customer\CustomerUser;
use App\Shared\StorefrontPageContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class LoginController extends AbstractController
{
    #[Route('/giris', name: 'customer_login', methods: ['GET', 'POST'])]
    public function customerLogin(
        AuthenticationUtils $authenticationUtils,
        StorefrontPageContext $pageContext,
    ): Response {
        if ($this->getUser() instanceof CustomerUser) {
            return $this->redirectToRoute('customer_account_dashboard');
        }

        return $this->render('security/customer/login.html.twig', $pageContext->withLayout([
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]));
    }

    #[Route('/cikis', name: 'customer_logout', methods: ['POST'])]
    public function customerLogout(): never
    {
        throw new \LogicException('This route is intercepted by the security firewall.');
    }

    #[Route('/admin/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/admin/logout', name: 'admin_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('This route is intercepted by the security firewall.');
    }
}
