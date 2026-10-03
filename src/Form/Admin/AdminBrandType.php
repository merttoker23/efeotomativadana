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
        $builder->add('name', TextType::class, ['label' => 'Ad'])->add('slug', TextType::class, ['label' => 'URL kısa adı (slug)', 'help' => 'Yayındaki URL kısa adı değişirse eski adres kalıcı olarak yeni adrese yönlendirilir.'])->add('published', CheckboxType::class, ['required' => false, 'label' => 'Yayında'])->add('save', SubmitType::class, ['label' => 'Markayı kaydet']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AdminCatalogData::class, 'csrf_token_id' => 'admin_brand']);
    }
}
