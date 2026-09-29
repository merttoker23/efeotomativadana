<?php

namespace App\Form\Admin;

use App\Module\Settings\StoreSettingsData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class StoreSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('storeName', TextType::class, ['label' => 'Store name'])
            ->add('currency', TextType::class, ['label' => 'Currency (ISO 4217)'])
            ->add('defaultLocale', TextType::class, ['label' => 'Default locale'])
            ->add('defaultTaxRate', IntegerType::class, ['label' => 'Default tax rate (%)'])
            ->add('b2bEnabled', CheckboxType::class, [
                'label' => 'Enable B2B integration',
                'required' => false,
            ])
            ->add('b2bProvider', TextType::class, [
                'label' => 'B2B provider key',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('loyaltyEnabled', CheckboxType::class, [
                'label' => 'Enable loyalty rewards',
                'required' => false,
            ])
            ->add('loyaltyEarnPercentage', IntegerType::class, ['label' => 'Loyalty earn percentage'])
            ->add('paymentProvider', TextType::class, [
                'label' => 'Payment provider key',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('shippingProvider', TextType::class, [
                'label' => 'Shipping provider key',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('seoIndexingEnabled', CheckboxType::class, [
                'label' => 'Allow search engines to index this store',
                'required' => false,
                // Turning this off is how a store that is not launched yet stays out of results
                // without a code change. It also closes the sitemap in robots.txt.
                'help' => 'When off, robots.txt forbids every address and no page is advertised as indexable.',
            ])
            ->add('seoDefaultDescription', TextType::class, [
                'label' => 'Default search description',
                'required' => false,
                'empty_data' => '',
                'help' => 'Optional. Used for pages that have no description of their own.',
            ])
            ->add('save', SubmitType::class, ['label' => 'Save settings']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StoreSettingsData::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'store_settings',
            'allow_extra_fields' => false,
        ]);
    }
}
