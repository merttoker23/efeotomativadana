<?php

namespace App\Form\Admin;

use App\Module\Settings\CookieSettingsData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CookieSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('script', TextareaType::class, [
                'label' => 'Çerez script kodu',
                'required' => false,
                'empty_data' => '',
                'attr' => ['rows' => 12, 'maxlength' => 50000, 'spellcheck' => 'false'],
                'help' => 'Çerez yönetimi sağlayıcınızın script kodunu yapıştırın. Kod mağaza sayfalarında çalıştırılır. Kaldırmak için alanı boş bırakıp kaydedin.',
            ])
            ->add('save', SubmitType::class, ['label' => 'Ayarları kaydet']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CookieSettingsData::class, 'csrf_protection' => true, 'csrf_token_id' => 'cookie_settings', 'allow_extra_fields' => false]);
    }
}
