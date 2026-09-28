<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A single CSRF-protected button for a shipment action that takes no input.
 *
 * Each action gets its own type rather than one shared form, because each has its own CSRF token
 * id: a token minted for "mark in transit" then replayed as "cancel" is a forged cross-action
 * request, and sharing one id would let it through.
 */
final class ShipmentActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('submit', SubmitType::class, [
            'label' => $options['action_label'],
            'attr' => ['class' => $options['button_class']],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'button_class' => 'admin-primary']);
        $resolver->setRequired(['action_label', 'csrf_token_id']);
        $resolver->setAllowedTypes('action_label', 'string');
    }
}
