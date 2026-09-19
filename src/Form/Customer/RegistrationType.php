<?php

namespace App\Form\Customer;

use App\Module\Customer\RegistrationData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class RegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, ['label' => 'Ad'])
            ->add('lastName', TextType::class, ['label' => 'Soyad'])
            ->add('email', EmailType::class, ['label' => 'E-posta adresi'])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => ['label' => 'Parola', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Parola tekrarı', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'Parolalar eşleşmiyor.',
            ])
            ->add('register', SubmitType::class, ['label' => 'Hesap oluştur']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrationData::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'customer_registration',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'customer_registration';
    }
}
