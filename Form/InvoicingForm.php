<?php


namespace ProcessAndInvoice\Form;


use ProcessAndInvoice\ProcessAndInvoice;
use Thelia\Form\BaseForm;

class InvoicingForm extends BaseForm
{
    protected function buildForm()
    {
        $this->formBuilder
            ->add('order_id', 'collection', array(
                'type'         => "text",
                'allow_add'    => true,
                'allow_delete' => true,
            ))
            ->add('status_paid', 'checkbox')
            ->add('status_processing', 'checkbox')
            ->add('status_sent', 'checkbox')
            ->add(
                'order_day',
                "date",
                [
                    "label" => $this->translator->trans("Day", [], ProcessAndInvoice::DOMAIN_NAME),
                    "label_attr" => ["for" => "order_day"],
                    "required" => false,
                    "input"  => "datetime",
                    "widget" => "single_text",
                    "format" => "dd/MM/yyyy"
                ]
            );
    }

    /**
     * @return string the name of you form. This name must be unique
     */
    public function getName()
    {
        return 'multi_order_pdf_invoicing_form';
    }
}