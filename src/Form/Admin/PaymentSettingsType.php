<?php

namespace App\Form\Admin;

use App\Module\Settings\PaymentSettingsData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PaymentSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('paymentProvider', ChoiceType::class, ['label' => 'Aktif ödeme sağlayıcısı', 'choices' => ['PayTR' => 'paytr'], 'placeholder' => 'Ödeme sağlayıcısı kapalı', 'required' => false])
            ->add('merchantId', TextType::class, ['label' => 'Merchant ID', 'required' => false, 'empty_data' => '', 'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'off']])
            ->add('merchantKey', PasswordType::class, ['label' => 'Merchant Key', 'required' => false, 'empty_data' => '', 'always_empty' => true, 'trim' => true, 'help' => 'Boş bırakırsanız tanımlı anahtar korunur.', 'attr' => ['autocomplete' => 'new-password']])
            ->add('merchantSalt', PasswordType::class, ['label' => 'Merchant Salt', 'required' => false, 'empty_data' => '', 'always_empty' => true, 'trim' => true, 'help' => 'Boş bırakırsanız tanımlı salt korunur.', 'attr' => ['autocomplete' => 'new-password']])
            ->add('testMode', ChoiceType::class, ['label' => 'PayTR çalışma modu', 'choices' => ['Test — gerçek tahsilat yapılmaz' => true, 'Canlı — gerçek tahsilat yapılır' => false], 'expanded' => true])
            ->add('confirmLiveMode', CheckboxType::class, ['label' => 'Canlı modda gerçek tahsilat yapılacağını onaylıyorum.', 'required' => false, 'help' => 'Canlı modda her kayıtta açık onay gerekir.'])
            ->add('save', SubmitType::class, ['label' => 'Ayarları kaydet']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PaymentSettingsData::class, 'csrf_protection' => true, 'csrf_token_id' => 'payment_settings', 'allow_extra_fields' => false]);
    }
}
