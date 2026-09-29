<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Module\Catalog\AdminCatalogData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AdminBrandType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class)->add('slug', TextType::class, ['help' => 'Changing a published slug keeps the old address working: it redirects permanently to this one.'])->add('published', CheckboxType::class, ['required' => false])->add('save', SubmitType::class, ['label' => 'Save brand']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AdminCatalogData::class, 'csrf_token_id' => 'admin_brand']);
    }
}
