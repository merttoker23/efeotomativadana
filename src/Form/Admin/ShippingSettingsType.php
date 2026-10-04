<?php

namespace App\Form\Admin;

use App\Module\Settings\ShippingSettingsData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ShippingSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('shippingProvider', TextType::class, ['label' => 'Kargo sağlayıcısı', 'required' => false, 'empty_data' => '']);
        foreach (['shippingFee' => 'Kargo ücreti (TL)', 'freeShippingThreshold' => 'Ücretsiz kargo alt limiti (TL)'] as $field => $label) {
            $builder->add($field, MoneyType::class, ['label' => $label, 'currency' => false, 'input' => 'integer', 'divisor' => 100, 'scale' => 2, 'html5' => true, 'attr' => ['min' => '0', 'step' => '0.01']]);
        }
        $builder->add('save', SubmitType::class, ['label' => 'Ayarları kaydet']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ShippingSettingsData::class, 'csrf_protection' => true, 'csrf_token_id' => 'shipping_settings', 'allow_extra_fields' => false]);
    }
}
