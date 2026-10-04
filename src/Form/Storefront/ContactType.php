<?php

namespace App\Form\Storefront;

use App\Module\Contact\ContactData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, ['label' => 'Ad', 'empty_data' => '', 'attr' => ['autocomplete' => 'given-name', 'maxlength' => 100]])
            ->add('lastName', TextType::class, ['label' => 'Soyad', 'empty_data' => '', 'attr' => ['autocomplete' => 'family-name', 'maxlength' => 100]])
            ->add('email', EmailType::class, ['label' => 'E-posta', 'empty_data' => '', 'attr' => ['autocomplete' => 'email', 'maxlength' => 254]])
            ->add('phone', TelType::class, ['label' => 'Telefon', 'empty_data' => '', 'attr' => ['autocomplete' => 'tel', 'maxlength' => 40]])
            ->add('message', TextareaType::class, ['label' => 'Mesaj', 'empty_data' => '', 'attr' => ['rows' => 6, 'maxlength' => 5000]])
            ->add('send', SubmitType::class, ['label' => 'Gönder']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ContactData::class, 'csrf_protection' => true, 'csrf_token_id' => 'storefront_contact', 'allow_extra_fields' => false]);
    }
}
