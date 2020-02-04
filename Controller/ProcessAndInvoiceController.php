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
    protected function returnHTMLInvoice($order_id, $fileName) {
        $html = $this->renderRaw(
            $fileName,
            array(
                'order_id' => $order_id
            ),
            $this->getTemplateHelper()->getActivePdfTemplate()
        );

        return $html;
    }

    protected function returnHTMLReport($fileName, $totalTurnover, $totalOrders) {
        $html = $this->renderRaw(
            $fileName,
            array(
                'total_turnover' => $totalTurnover,
                'total_orders' => $totalOrders
            ),
            $this->getTemplateHelper()->getActivePdfTemplate()
        );

        return $html;
    }

    protected function getOrderTurnover(Order $order) {
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

        $turnover += $order->getPostage() + $order->getPostageTax() - $order->getDiscount();
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

        $totalTurnover = 0;
        foreach ($orders as $order) {
            $htmlInvoice = $this->returnHTMLInvoice($order->getId(), ConfigQuery::read('pdf_invoice_file', 'invoice'));
            $htmltopdf->writeHTML($htmlInvoice);

            $totalTurnover += $this->getOrderTurnover($order);
        }


        $rapport = $this->returnHTMLReport('InvoicesReport', $totalTurnover, count($orders));
        $htmltopdf->writeHTML($rapport);

        $fileName = 'ordersInvoice_' . (new \DateTime())->format("Y-m-d_H-i-s") . '.pdf';
        $htmltopdf->output($fileName, 'D');

        foreach ($orders as $order) {
            $order->setOrderStatus($processingStatus)->save();
        }

        return $this->generateRedirectFromRoute('admin.order.list');
    }
}