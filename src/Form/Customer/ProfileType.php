<?php
namespace App\Form\Customer;
use App\Module\Customer\ProfileData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('firstName', TextType::class, ['label' => 'Ad'])->add('lastName', TextType::class, ['label' => 'Soyad'])->add('phone', TextType::class, ['label' => 'Telefon', 'required' => false])->add('save', SubmitType::class, ['label' => 'Profili kaydet', 'attr' => ['class' => 'account-primary-button']]);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => ProfileData::class, 'csrf_token_id' => 'customer_profile']); }
    public function getBlockPrefix(): string { return 'customer_profile'; }
}
