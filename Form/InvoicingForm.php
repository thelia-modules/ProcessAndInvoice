<?php


namespace ProcessAndInvoice\Form;


use ProcessAndInvoice\ProcessAndInvoice;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Thelia\Form\BaseForm;

class InvoicingForm extends BaseForm
{
    protected function buildForm(): void
    {
        $this->formBuilder
            ->add('order_id',
                CollectionType::class,
                [
                    'entry_type'   => TextType::class,
                    'allow_add'    => true,
                    'allow_delete' => true,
                ]
            )
            ->add('status_paid', CheckboxType::class)
            ->add('status_processing', CheckboxType::class)
            ->add('status_sent', CheckboxType::class)
            ->add(
                'order_day',
                DateTimeType::class,
                [
                    "label" => $this->translator->trans("Day", [], ProcessAndInvoice::DOMAIN_NAME),
                    "label_attr" => ["for" => "order_day"],
                    "required" => false,
                    "input"  => "datetime",
                    "widget" => "single_text",
                ]
            );
    }
}