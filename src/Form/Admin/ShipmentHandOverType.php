<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ShipmentHandOverType extends AbstractType
{
    public const string BLOCK_NAME = 'shipment_handover';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('trackingNumber', TextType::class, [
            'label' => 'Takip numarası (isteğe bağlı)',
            'required' => false,
        ]);
        $builder->add('handOver', SubmitType::class, ['label' => 'Teslimata hazır']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ShipmentHandOverData::class,
            'csrf_token_id' => self::BLOCK_NAME,
        ]);
    }

    /**
     * The form's own name, which the class name alone would spell `shipment_hand_over`.
     *
     * It has to match the CSRF token id, because the controller reads the submitted token out of
     * `$request->request->all('<this name>')`; a mismatch would make the two disagree and the check
     * would never see the token it minted.
     */
    public function getBlockPrefix(): string
    {
        return self::BLOCK_NAME;
    }
}
