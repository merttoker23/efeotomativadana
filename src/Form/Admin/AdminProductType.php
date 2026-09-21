<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Module\Catalog\AdminProductData;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AdminProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sku', TextType::class, ['label' => 'SKU'])
            ->add('name', TextType::class, ['label' => 'Name'])
            ->add('slug', TextType::class, ['label' => 'Public slug', 'help' => 'Published slugs are locked to preserve public URLs. Use lowercase letters, numbers and hyphens.'])
            ->add('description', TextareaType::class, ['required' => false, 'label' => 'Description'])
            ->add('published', CheckboxType::class, ['required' => false, 'label' => 'Published'])
            ->add('brand', EntityType::class, ['class' => Brand::class, 'choice_label' => 'name', 'required' => false, 'placeholder' => 'No brand'])
            ->add('categories', EntityType::class, ['class' => Category::class, 'choice_label' => 'name', 'multiple' => true, 'required' => false])
            ->add('manufacturerCode', TextType::class, ['required' => false, 'label' => 'Manufacturer code'])
            ->add('oemCodes', TextareaType::class, ['required' => false, 'label' => 'OEM codes', 'help' => 'One code per line.'])
            ->add('referenceCodes', TextareaType::class, ['required' => false, 'label' => 'Reference codes', 'help' => 'One code per line.'])
            ->add('imagePaths', TextareaType::class, ['required' => false, 'label' => 'Images', 'help' => 'One mapped asset path per line; optional alt text after |.'])
            ->add('baseMinorAmount', IntegerType::class, ['label' => 'Base price (minor units)'])
            ->add('currency', TextType::class, ['label' => 'ISO currency'])
            ->add('taxCategory', TextType::class, ['label' => 'Tax category'])
            ->add('taxRateBasisPoints', IntegerType::class, ['label' => 'Tax rate (basis points)'])
            ->add('saleMinorAmount', IntegerType::class, ['required' => false, 'label' => 'Sale price (minor units)'])
            ->add('saleStartsAt', DateTimeType::class, ['required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable', 'label' => 'Sale starts'])
            ->add('saleEndsAt', DateTimeType::class, ['required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable', 'label' => 'Sale ends'])
            ->add('quantity', IntegerType::class, ['label' => 'Stock quantity'])
            ->add('availableForSale', CheckboxType::class, ['required' => false, 'label' => 'Available for sale'])
            ->add('inventoryVersion', HiddenType::class, ['mapped' => false, 'data' => $options['inventory_version']])
            ->add('save', SubmitType::class, ['label' => 'Save product']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AdminProductData::class, 'csrf_token_id' => 'admin_product']);
        $resolver->setDefined('inventory_version')->setAllowedTypes('inventory_version', ['null', 'string']);
    }
}
