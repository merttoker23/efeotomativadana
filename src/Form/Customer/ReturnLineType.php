<?php

declare(strict_types=1);

namespace App\Form\Customer;

use App\Module\Returns\ReturnLineData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One order line's share of a return.
 *
 * The quantity's `max` is set by the controller to what is still free for that line, so the ceiling
 * is visible in the control rather than only in a message after submission.
 */
final class ReturnLineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $max = (int) ($options['max_quantity'] ?? 0);

        $builder->add('quantity', IntegerType::class, [
            'label' => 'Adet',
            'required' => false,
            'empty_data' => '0',
            'attr' => ['min' => 0, 'max' => $max, 'inputmode' => 'numeric'],
            'constraints' => [new Assert\Range(min: 0, max: $max)],
        ]);
        $builder->add('reason', TextareaType::class, [
            'label' => 'Bu ürün için sebep',
            'required' => false,
            // An untouched textarea posts an empty string, but a *cleared* one posts null, and a
            // null cannot be written into a typed `string`. Normalised here so an emptied field is
            // an ordinary validation failure rather than a 500.
            'empty_data' => '',
            'attr' => ['rows' => 2, 'maxlength' => 500],
            'constraints' => [new Assert\Length(max: 500)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReturnLineData::class,
            'max_quantity' => 0,
        ]);
        $resolver->setAllowedTypes('max_quantity', 'int');
    }

    public function getBlockPrefix(): string
    {
        return 'customer_return_line';
    }
}
