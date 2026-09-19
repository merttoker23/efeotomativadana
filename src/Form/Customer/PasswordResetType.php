<?php
namespace App\Form\Customer;
use App\Module\Customer\PasswordResetData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
final class PasswordResetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('newPassword', RepeatedType::class, ['type' => PasswordType::class, 'first_options' => ['label' => 'Yeni parola', 'attr' => ['autocomplete' => 'new-password']], 'second_options' => ['label' => 'Yeni parola tekrarı', 'attr' => ['autocomplete' => 'new-password']], 'invalid_message' => 'Parolalar eşleşmiyor.'])->add('reset', SubmitType::class, ['label' => 'Parolayı sıfırla']);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => PasswordResetData::class, 'csrf_token_id' => 'customer_password_reset']); }
    public function getBlockPrefix(): string { return 'password_reset'; }
}
