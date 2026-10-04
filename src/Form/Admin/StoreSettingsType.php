<?php

namespace App\Form\Admin;

use App\Module\Settings\StoreSettingsData;
use App\Module\Settings\Ga4MeasurementId;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
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
            ->add('storefrontNotice', ColorType::class, ['label' => 'Duyuru', 'trim' => false, 'empty_data' => ''])
            ->add('storefrontNavy', ColorType::class, ['label' => 'Lacivert', 'trim' => false, 'empty_data' => ''])
            ->add('storefrontNavyLight', ColorType::class, ['label' => 'Açık lacivert', 'trim' => false, 'empty_data' => ''])
            ->add('storefrontYellow', ColorType::class, ['label' => 'Sarı', 'trim' => false, 'empty_data' => ''])
            ->add('storefrontBody', ColorType::class, ['label' => 'Sayfa arka planı', 'trim' => false, 'empty_data' => ''])
            ->add('storefrontCard', ColorType::class, ['label' => 'Kart arka planı', 'trim' => false, 'empty_data' => ''])
            ->add('storefrontInk', ColorType::class, ['label' => 'Metin', 'trim' => false, 'empty_data' => ''])
            ->add('storefrontMuted', ColorType::class, ['label' => 'İkincil metin', 'trim' => false, 'empty_data' => ''])
            ->add('storefrontLine', ColorType::class, ['label' => 'Çizgi', 'trim' => false, 'empty_data' => ''])
            ->add('save', SubmitType::class, ['label' => 'Ayarları kaydet']);

        $builder->add('ga4MeasurementId', TextareaType::class, [
            'label' => 'GA4 Measurement ID',
            'required' => false,
            'empty_data' => '',
            'help' => 'G-XXXXXXXXXX veya Google standart gtag kodunu yapıştırın. Yalnız Measurement ID kaydedilir. Boş bırakıldığında takip kapatılır.',
            'attr' => ['rows' => 3, 'placeholder' => 'G-XXXXXXXXXX'],
        ]);
        $builder->get('ga4MeasurementId')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): string => $value ?? '',
            static fn (?string $value): ?string => Ga4MeasurementId::normalize($value),
        ));
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
