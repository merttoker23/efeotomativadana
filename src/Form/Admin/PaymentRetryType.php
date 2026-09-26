<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A single CSRF-protected button. Restarting a payment is a money action even though it
 * captures nothing, so it gets the same protection as any other administrator mutation.
 */
final class PaymentRetryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('retry', SubmitType::class, [
            'label' => 'Ödemeyi yeniden başlat',
            'attr' => ['class' => 'admin-primary'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_token_id' => 'payment_retry',
        ]);
    }
}
