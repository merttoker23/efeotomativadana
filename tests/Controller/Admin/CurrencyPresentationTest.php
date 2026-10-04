<?php

namespace App\Tests\Controller\Admin;

use App\Form\Admin\AdminProductType;
use App\Form\Admin\ReturnRefundType;
use App\Form\Admin\ReturnRefundData;
use App\Module\Catalog\AdminProductData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class CurrencyPresentationTest extends KernelTestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('currencyForms')]
    public function testCurrencyDisplaysLiraAndSubmitsIsoCurrency(string $type): void
    {
        self::bootKernel();
        $data = AdminProductType::class === $type ? new AdminProductData() : new ReturnRefundData();
        $options = ['csrf_protection' => false];
        if (AdminProductType::class === $type) {
            $options['inventory_version'] = null;
        }
        $form = self::getContainer()->get(FormFactoryInterface::class)->create($type, $data, $options);
        self::assertSame('TL', $form->createView()['currency']->vars['value']);
        $form->get('currency')->submit('TL');
        self::assertSame('TRY', $form->get('currency')->getData());
    }

    public static function currencyForms(): iterable
    {
        yield 'product' => [AdminProductType::class];
        yield 'refund' => [ReturnRefundType::class];
    }
}
