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
            ->add('name', TextType::class, ['label' => 'Ad'])
            ->add('slug', TextType::class, ['label' => 'URL kısa adı (slug)', 'help' => 'Yayındaki URL kısa adı değişirse eski adres yeni adrese yönlendirilir. Küçük harf, rakam ve tire kullanın.'])
            ->add('description', TextareaType::class, ['required' => false, 'label' => 'Açıklama'])
            ->add('published', CheckboxType::class, ['required' => false, 'label' => 'Yayında'])
            ->add('brand', EntityType::class, ['label' => 'Marka', 'class' => Brand::class, 'choice_label' => 'name', 'required' => false, 'placeholder' => 'Marka seçilmedi'])
            ->add('categories', EntityType::class, ['label' => 'Kategoriler', 'class' => Category::class, 'choice_label' => 'name', 'multiple' => true, 'required' => false])
            ->add('manufacturerCode', TextType::class, ['required' => false, 'label' => 'Üretici kodu'])
            ->add('oemCodes', TextareaType::class, ['required' => false, 'label' => 'OEM kodları', 'help' => 'Her satıra bir kod yazın.'])
            ->add('referenceCodes', TextareaType::class, ['required' => false, 'label' => 'Referans kodları', 'help' => 'Her satıra bir kod yazın.'])
            ->add('imagePaths', TextareaType::class, ['required' => false, 'label' => 'Görseller', 'help' => 'Her satıra bir görsel yolu yazın; | işaretinden sonra alternatif metin ekleyebilirsiniz.'])
            ->add('baseMinorAmount', IntegerType::class, ['label' => 'Normal fiyat (alt birim)', 'help' => 'TRY için kuruş girin: 100 = 1 TL.'])
            ->add('currency', TextType::class, ['label' => 'Para birimi (ISO kodu)'])
            ->add('taxCategory', TextType::class, ['label' => 'Vergi kategorisi'])
            ->add('taxRateBasisPoints', IntegerType::class, ['label' => 'Vergi oranı (baz puan; %1 = 100)'])
            ->add('saleMinorAmount', IntegerType::class, ['required' => false, 'label' => 'İndirimli fiyat (alt birim)'])
            ->add('saleStartsAt', DateTimeType::class, ['required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable', 'label' => 'İndirim başlangıcı'])
            ->add('saleEndsAt', DateTimeType::class, ['required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable', 'label' => 'İndirim bitişi'])
            ->add('quantity', IntegerType::class, ['label' => 'Stok adedi'])
            ->add('availableForSale', CheckboxType::class, ['required' => false, 'label' => 'Satışa açık'])
            ->add('inventoryVersion', HiddenType::class, ['mapped' => false, 'data' => $options['inventory_version']])
            ->add('save', SubmitType::class, ['label' => 'Ürünü kaydet']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AdminProductData::class, 'csrf_token_id' => 'admin_product']);
        $resolver->setDefined('inventory_version')->setAllowedTypes('inventory_version', ['null', 'string']);
    }
}
