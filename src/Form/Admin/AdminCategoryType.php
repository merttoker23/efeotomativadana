<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Catalog\Category;
use App\Module\Catalog\AdminCatalogData;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AdminCategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class)->add('slug', TextType::class, ['help' => 'Published slugs are locked to preserve public URLs.'])->add('published', CheckboxType::class, ['required' => false])->add('parent', EntityType::class, ['class' => Category::class, 'choice_label' => 'name', 'required' => false, 'placeholder' => 'No parent'])->add('save', SubmitType::class, ['label' => 'Save category']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AdminCatalogData::class, 'csrf_token_id' => 'admin_category']);
    }
}
