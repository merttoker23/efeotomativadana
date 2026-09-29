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
                'label' => 'Search title',
                'help' => sprintf('Optional. Up to %d characters. Leave empty to use the page’s own title.', SeoOverride::TITLE_LIMIT),
                'empty_data' => '',
            ])
            ->add('metaDescription', TextareaType::class, [
                'required' => false,
                'label' => 'Search description',
                'help' => sprintf('Optional. Up to %d characters. Leave empty to use the page’s own text.', SeoOverride::DESCRIPTION_LIMIT),
                'empty_data' => '',
            ])
            ->add('noIndex', CheckboxType::class, [
                'required' => false,
                'label' => 'Keep this page out of search engines',
                'help' => 'The page stays reachable by its address. It is only kept out of results.',
            ])
            ->add('save', SubmitType::class, ['label' => 'Save SEO overrides']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SeoOverrideData::class,
            'csrf_token_id' => 'admin_seo',
        ]);
    }
}
