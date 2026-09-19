<?php
namespace App\Form\Customer;
use App\Module\Customer\AddressData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
final class AddressType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('label', TextType::class, ['label' => 'Adres başlığı'])->add('recipientName', TextType::class, ['label' => 'Ad soyad'])->add('phone', TextType::class, ['label' => 'Telefon'])->add('addressLine1', TextType::class, ['label' => 'Adres'])->add('addressLine2', TextType::class, ['label' => 'Adres devamı', 'required' => false])->add('district', TextType::class, ['label' => 'İlçe'])->add('city', TextType::class, ['label' => 'İl'])->add('postalCode', TextType::class, ['label' => 'Posta kodu', 'required' => false])->add('defaultAddress', CheckboxType::class, ['label' => 'Varsayılan adres', 'required' => false])->add('save', SubmitType::class, ['label' => 'Adresi kaydet', 'attr' => ['class' => 'account-primary-button']]);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => AddressData::class, 'csrf_token_id' => 'customer_address']); }
    public function getBlockPrefix(): string { return 'customer_address'; }
}
