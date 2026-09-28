<?php

declare(strict_types=1);

namespace App\Form\Customer;

use App\Module\Returns\ReturnRequestData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The return request form.
 *
 * The lines are added one by one rather than through a `CollectionType`, and that is deliberate.
 * A collection shares one set of entry options across every entry, but each order line has its own
 * ceiling — three units of one product may be free while none of another are — so the options have
 * to be resolved per line. Naming the fields `line_0`, `line_1`, … and pointing each at
 * `lines[N]` gives each one its own form, and `allow_add` is structurally impossible because there
 * is no prototype to add from: a client cannot introduce a line the order does not have.
 */
final class ReturnRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('customerReason', TextareaType::class, [
            'label' => 'İade sebebiniz',
            'required' => true,
            'empty_data' => '',
            'attr' => ['rows' => 3, 'maxlength' => 1000],
        ]);

        /** @var list<int> $maxima */
        $maxima = $options['maxima'];
        foreach ($maxima as $index => $max) {
            $builder->add('line_'.$index, ReturnLineType::class, [
                'label' => false,
                'property_path' => sprintf('lines[%d]', $index),
                'max_quantity' => $max,
                // Empty for a line with nothing left to return, so the field is not submitted back
                // as a zero the customer could be told off about.
                'required' => false,
            ]);
        }

        $builder->add('submit', SubmitType::class, [
            'label' => 'İade talebini gönder',
            'attr' => ['class' => 'account-primary-button'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReturnRequestData::class,
            'csrf_token_id' => 'customer_return_request',
            // One ceiling per order line, in the order's own line order.
            'maxima' => [],
        ]);
        $resolver->setAllowedTypes('maxima', 'array');
        $resolver->setAllowedTypes('maxima', 'int[]');
    }

    public function getBlockPrefix(): string
    {
        return 'customer_return_request';
    }
}
