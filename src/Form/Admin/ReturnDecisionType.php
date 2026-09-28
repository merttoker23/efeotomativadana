<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Form\Admin\ReturnDecisionData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The approve / reject form.
 *
 * `required` is switched on for the rejection, because a customer whose return is declined is
 * entitled to know why and an empty note would be rendered as a blank line on the screen they are
 * already unhappy about. The same form type serves both actions with different `csrf_token_id`s,
 * so a token minted for "approve" cannot be replayed as "reject".
 */
final class ReturnDecisionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $required = (bool) $options['note_required'];
        $builder->add('staffNote', TextareaType::class, [
            'label' => $options['note_label'],
            'required' => $required,
            // A cleared textarea posts null; without this a cleared note is a 500 rather than the
            // validation failure the operator needs to see.
            'empty_data' => '',
            'attr' => ['rows' => 3, 'maxlength' => 1000],
            'constraints' => [
                new Assert\Length(max: 1000),
                new Assert\NotBlank(message: 'Müşteriye gösterilecek bir açıklama yazmalısınız.', groups: ['reject']),
            ],
        ]);
        $builder->add('submit', SubmitType::class, [
            'label' => $options['submit_label'],
            'attr' => ['class' => 'button-row'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReturnDecisionData::class,
            'csrf_token_id' => 'return_decision',
            'note_required' => false,
            'note_label' => 'Not',
            'submit_label' => 'Onayla',
            'validation_groups' => ['Default'],
        ]);
        $resolver->setAllowedTypes('note_required', 'bool');
        $resolver->setAllowedTypes('note_label', 'string');
        $resolver->setAllowedTypes('submit_label', 'string');
    }

    public function getBlockPrefix(): string
    {
        return 'return_decision';
    }
}
