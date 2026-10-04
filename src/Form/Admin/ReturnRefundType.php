<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Files an already-issued refund against a return.
 *
 * The amount is an integer of minor units rather than a decimal string: this store never does
 * money arithmetic in a float, and a form that accepts `450.00` is a form that will eventually
 * accept `450.005`.
 */
final class ReturnRefundType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('amountMinor', IntegerType::class, [
            'label' => 'İade tutarı (kuruş)',
            'required' => true,
            'empty_data' => '',
            'attr' => ['min' => 1, 'inputmode' => 'numeric'],
            'constraints' => [new Assert\Positive(message: 'İade tutarı sıfırdan büyük olmalıdır.')],
        ]);
        $builder->add('currency', TextType::class, [
            'label' => 'Para birimi',
            'required' => true,
            'empty_data' => '',
            'attr' => ['maxlength' => 3],
            'constraints' => [new Assert\Length(min: 3, max: 3, exactMessage: 'Para birimi üç harfli olmalıdır.')],
        ]);
        $builder->add('refundReference', TextType::class, [
            'label' => 'Sağlayıcı referansı',
            'required' => true,
            'empty_data' => '',
            'attr' => ['maxlength' => 120],
            'constraints' => [new Assert\NotBlank(message: 'İade sağlayıcı referansı zorunludur.')],
        ]);
        $builder->get('currency')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): ?string => 'TRY' === $value ? 'TL' : $value,
            static fn (?string $value): ?string => 'TL' === $value ? 'TRY' : $value,
        ));
        $builder->add('submit', SubmitType::class, [
            'label' => 'İade kaydını tamamla',
            'attr' => ['class' => 'button-row'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReturnRefundData::class,
            'csrf_token_id' => 'return_refund',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'return_refund';
    }
}
