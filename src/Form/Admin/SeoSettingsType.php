<?php

namespace App\Form\Admin;

use App\Module\Settings\SeoSettingsData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SeoSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('seoIndexingEnabled', CheckboxType::class, ['label' => 'Arama motoru indeksleme', 'required' => false, 'help' => 'Kapalı olduğunda mağaza sayfalarının indekslenmesi engellenir.'])
            ->add('seoDefaultDescription', TextType::class, ['label' => 'Varsayılan SEO açıklaması', 'required' => false, 'empty_data' => '', 'help' => 'Kendi açıklaması olmayan sayfalarda kullanılır.'])
            ->add('save', SubmitType::class, ['label' => 'Ayarları kaydet']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SeoSettingsData::class, 'csrf_protection' => true, 'csrf_token_id' => 'seo_settings', 'allow_extra_fields' => false]);
    }
}
