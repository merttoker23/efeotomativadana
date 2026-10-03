<?php

namespace App\Form\Admin;

use App\Module\Settings\StoreSettingsData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class StoreSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('storeName', TextType::class, ['label' => 'Mağaza adı'])
            ->add('currency', TextType::class, ['label' => 'Para birimi (ISO 4217)'])
            ->add('defaultLocale', TextType::class, ['label' => 'Mağazanın varsayılan dili'])
            ->add('defaultTaxRate', IntegerType::class, ['label' => 'Varsayılan vergi oranı (%)'])
            ->add('b2bEnabled', CheckboxType::class, [
                'label' => 'B2B entegrasyonunu etkinleştir',
                'required' => false,
            ])
            ->add('b2bProvider', TextType::class, [
                'label' => 'B2B sağlayıcısı',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('loyaltyEnabled', CheckboxType::class, [
                'label' => 'Ödül sistemini etkinleştir',
                'required' => false,
            ])
            ->add('loyaltyEarnPercentage', IntegerType::class, ['label' => 'Puan kazanım yüzdesi'])
            ->add('paymentProvider', TextType::class, [
                'label' => 'Ödeme sağlayıcısı',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('shippingProvider', TextType::class, [
                'label' => 'Kargo sağlayıcısı',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('seoIndexingEnabled', CheckboxType::class, [
                'label' => 'Mağazanın arama motorlarında görünmesine izin ver',
                'required' => false,
                // Turning this off is how a store that is not launched yet stays out of results
                // without a code change. It also closes the sitemap in robots.txt.
                'help' => 'Kapalı olduğunda arama motorlarının mağaza sayfalarını indekslemesi engellenir.',
            ])
            ->add('seoDefaultDescription', TextType::class, [
                'label' => 'Varsayılan arama açıklaması',
                'required' => false,
                'empty_data' => '',
                'help' => 'Kendi açıklaması olmayan sayfalarda kullanılır. İsteğe bağlıdır.',
            ])
            ->add('save', SubmitType::class, ['label' => 'Ayarları kaydet']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StoreSettingsData::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'store_settings',
            'allow_extra_fields' => false,
        ]);
    }
}
