<?php


namespace ProcessAndInvoice\Controller;


use Spipu\Html2Pdf\Html2Pdf;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Event\PdfEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Exception\TheliaProcessException;
use Thelia\Log\Tlog;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderAddressQuery;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderProductTax;
use Thelia\Model\OrderProductTaxQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;
use Thelia\Tools\URL;

class ProcessAndInvoiceController extends BaseAdminController
{
    public function returnHTMLInvoice($order_id, $fileName) {
        $html = $this->renderRaw(
            $fileName,
            array(
                'order_id' => $order_id
            ),
            $this->getTemplateHelper()->getActivePdfTemplate()
        );

        return $html;
    }

    public function getOrderTurnover(Order $order) {
        $orderProducts = OrderProductQuery::create()
            ->filterByOrder($order)
            ->find()
        ;
        $turnover = 0;

        foreach ($orderProducts as $orderProduct) {
            if ($orderProduct->getWasInPromo()) {
                $turnover += $orderProduct->getQuantity() * ($orderProduct->getPromoPrice() + OrderProductTaxQuery::create()->findOneByOrderProductId($orderProduct->getId())->getPromoAmount());
            } else {
                $turnover += $orderProduct->getQuantity() * ($orderProduct->getPrice() + OrderProductTaxQuery::create()->findOneByOrderProductId($orderProduct->getId())->getAmount());
            }
        }

        return $turnover;
    }

    public function processAndInvoice() {
        $paidStatus = OrderStatusQuery::create()->findOneByCode('paid');
        $processingStatus = OrderStatusQuery::create()->findOneByCode('processing');

        $orders = OrderQuery::create()
            ->filterByOrderStatus($paidStatus)
            ->find()
            ;

        $htmltopdf = new Html2Pdf('P', 'A4', 'fr');

        $rapport = '
        <style>
            .align-center {text-align: center;}
            .table-border {border: solid 1px #3a3b3c; text-align: center; vertical-align: center; height: auto;}
            table {border-collapse: collapse; width: 100%;}
            p {padding: 3px; margin: 3px; text-align: center; vertical-align: center;}
        </style>
        <page>
            <h1 class="align-center">Rapport</h1>';

        $rapport .= '
        <div>
            <table>
            <col style="width: 10%; padding: 1mm; " />
            <col style="width: 20%; padding: 1mm; " />
            <col style="width: 20%; padding: 1mm; " />
            <col style="width: 25%; padding: 1mm; " />
            <col style="width: 15%; padding: 1mm; " />
            <col style="width: 10%; padding: 1mm; text-align: right;" />
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Référence</th>
                        <th>Date et heure</th>
                        <th>Entreprise</th>
                        <th>Nom du client</th>
                        <th>Montant</th>
                    </tr>
                </thead>
                <tbody>    
        ';

        $totalTurnover = 0;
        foreach ($orders as $order) {
            $htmlInvoice = $this->returnHTMLInvoice($order->getId(), ConfigQuery::read('pdf_invoice_file', 'invoice'));
            $htmltopdf->writeHTML($htmlInvoice);

            $customer = $order->getCustomer();
            $invoiceAddress = OrderAddressQuery::create()->findOneById($order->getInvoiceOrderAddressId());

            $orderId = $order->getId();
            $orderRef = $order->getRef();
            $orderDate = $order->getCreatedAt()->format('d/m/Y H:i:s');
            $orderCompany = $invoiceAddress->getCompany();
            $orderCustomer = $customer->getFirstname() . ' ' . $customer->getLastname();
            $orderTurnover = $this->getOrderTurnover($order) - $order->getDiscount();

            $rapport .= "
            <tr>
                <td>$orderId</td>
                <td>$orderRef</td>
                <td>$orderDate</td>
                <td>$orderCompany</td>
                <td>$orderCustomer</td>
                <td>$orderTurnover</td>
            </tr>
            ";

            $totalTurnover += $orderTurnover;
        }

        $rapport .= '
                </tbody>
            </table>
        </div>
        ';

        $totalInvoices = count($orders);

        $rapport .= '
        <br><br>
        <div>
            <table>
            <col style="width: 30%; padding: 1mm; " />
            <col style="width: 20%; padding: 1mm; " />
                <thead>
                    <tr>
                        <th class="table-border ca" valign="middle">TOTAL CA</th>
                        <th class="table-border commandes" valign="middle">Commandes traitées</th>
                    </tr>
                </thead>
                <tbody>    
        ';

        $rapport .= "
                    <tr>
                        <td class=\"table-border ca\" valign=\"middle\">$totalTurnover</td>
                        <td class=\"table-border commandes\" valign=\"middle\">$totalInvoices</td>
                    </tr>
                </tbody>
            </table>
        </div>
        </page>
            ";

        $htmltopdf->writeHTML($rapport);

        $fileName = 'ordersInvoice_' . (new \DateTime())->format("Y-m-d_H-i-s") . '.pdf';
        $htmltopdf->output($fileName, 'D');

        foreach ($orders as $order) {
            $order->setOrderStatus($processingStatus)->save();
        }

        return $this->generateRedirectFromRoute('admin.order.list');
    }
}