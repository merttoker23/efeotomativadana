<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Module\Order\OrderState;
use App\Module\Order\OrderTransitionData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class OrderTransitionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($options['allowed_states'] as $state) {
            $choices[ucfirst($state->value)] = $state;
        }
        $builder->add('nextState', ChoiceType::class, ['choices' => $choices, 'choice_value' => 'value', 'placeholder' => 'Choose status'])
            ->add('reason', TextareaType::class, ['label' => 'Audit reason'])
            ->add('version', HiddenType::class)
            ->add('save', SubmitType::class, ['label' => 'Update order status']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OrderTransitionData::class, 'csrf_token_id' => 'order_transition', 'allowed_states' => []]);
        $resolver->setAllowedTypes('allowed_states', 'array');
    }
}
