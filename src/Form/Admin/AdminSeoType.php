<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Seo\SeoOverride;
use App\Module\Seo\SeoOverrideData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AdminSeoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('metaTitle', TextType::class, [
                'required' => false,
                'label' => 'Arama başlığı',
                'help' => sprintf('İsteğe bağlı. En fazla %d karakter. Sayfanın başlığını kullanmak için boş bırakın.', SeoOverride::TITLE_LIMIT),
                'empty_data' => '',
            ])
            ->add('metaDescription', TextareaType::class, [
                'required' => false,
                'label' => 'Arama açıklaması',
                'help' => sprintf('İsteğe bağlı. En fazla %d karakter. Sayfanın açıklamasını kullanmak için boş bırakın.', SeoOverride::DESCRIPTION_LIMIT),
                'empty_data' => '',
            ])
            ->add('noIndex', CheckboxType::class, [
                'required' => false,
                'label' => 'Bu sayfayı arama sonuçlarından gizle',
                'help' => 'Sayfa adresinden erişilebilir olmaya devam eder; arama sonuçlarında görünmez.',
            ])
            ->add('save', SubmitType::class, ['label' => 'SEO ayarlarını kaydet']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SeoOverrideData::class,
            'csrf_token_id' => 'admin_seo',
        ]);
    }
}
