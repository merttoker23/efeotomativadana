<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Module\Loyalty\RewardAdjustmentData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class RewardAdjustmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('points', IntegerType::class, ['label' => 'Puan değişikliği', 'help' => 'Puan eklemek için pozitif, düşmek için negatif sayı girin.'])
            ->add('reason', TextareaType::class, ['label' => 'Gerekçe', 'empty_data' => ''])
            ->add('requestKey', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RewardAdjustmentData::class, 'csrf_token_id' => 'reward_adjustment']);
    }
}
