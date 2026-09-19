<?php

namespace App\Controller\Storefront\Account;

use App\Entity\Customer\CustomerUser;
use App\Form\Customer\PasswordChangeType;
use App\Form\Customer\ProfileType;
use App\Module\Customer\PasswordChangeData;
use App\Module\Customer\ProfileData;
use App\Shared\StorefrontPageContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CUSTOMER')]
final class AccountController extends AbstractController
{
    #[Route('/hesabim', name: 'customer_account_dashboard', methods: ['GET'])]
    public function dashboard(StorefrontPageContext $pageContext): Response
    {
        $customer = $this->getUser();
        if (!$customer instanceof CustomerUser) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('storefront/account/dashboard.html.twig', $pageContext->withLayout([
            'customer' => $customer,
        ]));
    }

    #[Route('/hesabim/profil', name: 'customer_account_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request, StorefrontPageContext $pageContext, EntityManagerInterface $entityManager): Response
    {
        $customer = $this->customer();
        $data = new ProfileData($customer);
        $form = $this->createForm(ProfileType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $customer->setProfile($data->firstName, $data->lastName, $data->phone);
            $entityManager->flush();
            $this->addFlash('success', 'Profiliniz güncellendi.');
            return $this->redirectToRoute('customer_account_dashboard');
        }
        return $this->render('storefront/account/form.html.twig', $pageContext->withLayout(['heading' => 'Profil bilgileri', 'form' => $form]), new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/hesabim/parola', name: 'customer_account_password', methods: ['GET', 'POST'])]
    public function password(Request $request, StorefrontPageContext $pageContext, EntityManagerInterface $entityManager, UserPasswordHasherInterface $hasher): Response
    {
        $customer = $this->customer();
        $data = new PasswordChangeData();
        $form = $this->createForm(PasswordChangeType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$hasher->isPasswordValid($customer, $data->currentPassword)) {
                $form->get('currentPassword')->addError(new FormError('Mevcut parola hatalı.'));
            } else {
                $customer->setPassword($hasher->hashPassword($customer, $data->newPassword));
                $entityManager->flush();
                $this->addFlash('success', 'Parolanız değiştirildi.');
                return $this->redirectToRoute('customer_account_dashboard');
            }
        }
        return $this->render('storefront/account/form.html.twig', $pageContext->withLayout(['heading' => 'Parola değiştir', 'form' => $form]), new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    private function customer(): CustomerUser
    {
        $customer = $this->getUser();
        if (!$customer instanceof CustomerUser) { throw $this->createAccessDeniedException(); }
        return $customer;
    }
}
