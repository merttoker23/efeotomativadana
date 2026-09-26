<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PaymentCancelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // A cancellation is an audited money decision, so the reason is mandatory rather than
        // optional context an operator may skip. The constraints live on the data class.
        $builder->add('reason', TextareaType::class, ['label' => 'İptal nedeni']);
        $builder->add('cancel', SubmitType::class, ['label' => 'Tahsil edilmemiş ödemeyi iptal et']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PaymentCancelData::class,
            'csrf_token_id' => 'payment_cancel',
        ]);
    }
}
