<?php

namespace App\Form\Admin;

use App\Module\Settings\GeneralStoreSettingsData;
use App\Module\Settings\Ga4MeasurementId;
use App\Module\Settings\TurkishGeography;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
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
            ->add('contactEmail', EmailType::class, ['label' => 'İletişim e-posta adresi', 'required' => false])
            ->add('phone', TextType::class, [
                'label' => 'Mağaza telefonu',
                'required' => false,
                'empty_data' => '',
                'help' => 'Boş bırakılabilir. Örnek: +90 322 123 45 67. 0, +90 veya alan kodu yazılmadan da kabul edilir.',
                'attr' => ['inputmode' => 'tel', 'placeholder' => '+90 322 123 45 67'],
            ])
            ->add('country', ChoiceType::class, [
                'label' => 'Ülke',
                'choices' => ['Türkiye' => 'Türkiye'],
            ])
            ->add('city', ChoiceType::class, [
                'label' => 'İl',
                'required' => false,
                'placeholder' => 'İl seçin',
                'choices' => TurkishGeography::provinces(),
                'choice_label' => static fn (string $choice): string => $choice,
                'empty_data' => '',
            ])
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
            ->add('storefrontNotice', ColorType::class, ['label' => 'Duyuru', 'trim' => false, 'empty_data' => ''])
            ->add('storefrontNavy', ColorType::class, ['label' => 'Lacivert', 'trim' => false, 'empty_data' => ''])
            ->add('storefrontNavyLight', ColorType::class, ['label' => 'Açık lacivert', 'trim' => false, 'empty_data' => ''])
            ->add('storefrontYellow', ColorType::class, ['label' => 'Sarı', 'trim' => false, 'empty_data' => ''])
            ->add('storefrontBody', ColorType::class, ['label' => 'Sayfa arka planı', 'trim' => false, 'empty_data' => ''])
            ->add('storefrontCard', ColorType::class, ['label' => 'Kart arka planı', 'trim' => false, 'empty_data' => ''])
            ->add('storefrontInk', ColorType::class, ['label' => 'Metin', 'trim' => false, 'empty_data' => ''])
            ->add('storefrontMuted', ColorType::class, ['label' => 'İkincil metin', 'trim' => false, 'empty_data' => ''])
            ->add('storefrontLine', ColorType::class, ['label' => 'Çizgi', 'trim' => false, 'empty_data' => '']);

        // Yalnızca kırpar: normalizasyon StoreConfiguration'da, hata mesajıyla birlikte yapılır.
        // Burada geçersiz bir girişi sessizce düzeltmek, hata mesajının kaybolduğu bir form
        // bırakırdı.
        $builder->get('phone')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): string => trim($value ?? ''),
            static fn (?string $value): string => trim($value ?? ''),
        ));

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
        $builder->add('save', SubmitType::class, ['label' => 'Ayarları kaydet']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GeneralStoreSettingsData::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'store_settings',
            'allow_extra_fields' => false,
        ]);
    }
}
