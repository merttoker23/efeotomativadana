<?php
namespace App\Form\Customer;
use App\Module\Customer\PasswordChangeData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
final class PasswordChangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('currentPassword', PasswordType::class, ['label' => 'Mevcut parola', 'attr' => ['autocomplete' => 'current-password']])->add('newPassword', RepeatedType::class, ['type' => PasswordType::class, 'first_options' => ['label' => 'Yeni parola', 'attr' => ['autocomplete' => 'new-password']], 'second_options' => ['label' => 'Yeni parola tekrarı', 'attr' => ['autocomplete' => 'new-password']], 'invalid_message' => 'Parolalar eşleşmiyor.'])->add('save', SubmitType::class, ['label' => 'Parolayı değiştir', 'attr' => ['class' => 'account-primary-button']]);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => PasswordChangeData::class, 'csrf_token_id' => 'customer_password_change']); }
    public function getBlockPrefix(): string { return 'customer_password_change'; }
}
