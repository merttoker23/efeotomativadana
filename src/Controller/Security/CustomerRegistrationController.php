<?php

namespace App\Controller\Security;

use App\Entity\Customer\CustomerUser;
use App\Form\Customer\RegistrationType;
use App\Module\Customer\RegistrationData;
use App\Repository\Customer\CustomerUserRepository;
use App\Shared\StorefrontPageContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerRegistrationController extends AbstractController
{
    #[Route('/kayit', name: 'customer_registration', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        CustomerUserRepository $customers,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        StorefrontPageContext $pageContext,
    ): Response {
        if ($this->getUser() instanceof CustomerUser) {
            return $this->redirectToRoute('customer_account_dashboard');
        }

        $data = new RegistrationData();
        $form = $this->createForm(RegistrationType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = CustomerUser::normalizeEmail($data->email);
            if (null !== $customers->findOneByEmail($email)) {
                $form->get('email')->addError(new FormError('Bu e-posta adresiyle kayıtlı bir hesap zaten var.'));
            } else {
                $customer = new CustomerUser($email, $data->firstName, $data->lastName);
                $customer->setPassword($passwordHasher->hashPassword($customer, $data->plainPassword));
                $entityManager->persist($customer);
                $entityManager->flush();

                $this->addFlash('success', 'Hesabınız oluşturuldu. Şimdi giriş yapabilirsiniz.');

                return $this->redirectToRoute('customer_login');
            }
        }

        return $this->render('security/customer/register.html.twig', $pageContext->withLayout([
            'registration_form' => $form,
        ]), new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
