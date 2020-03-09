<?php


namespace ProcessAndInvoice\Controller;


use ColissimoLabel\Exception\Exception;
use Front\Front;
use LynX39\LaraPdfMerger\Facades\PdfMerger;
use LynX39\LaraPdfMerger\PdfManage;
use ProcessAndInvoice\Form\InvoicingForm;
use ProcessAndInvoice\Model\PdfInvoice;
use ProcessAndInvoice\Model\PdfInvoiceQuery;
use ProcessAndInvoice\ProcessAndInvoice;
use Spipu\Html2Pdf\Html2Pdf;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\User\User;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Event\PdfEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\JsonResponse;
use Thelia\Core\Template\Loop\Auth;
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
use Thelia\Tools\FileDownload\FileDownloader;
use Thelia\Tools\URL;

class ProcessAndInvoiceController extends BaseAdminController
{
    protected function returnHTMLInvoice($orderID, $fileName) {
        $html = $this->renderRaw(
            $fileName,
            array(
                'order_id' => $orderID
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
            if (null !== $orderTax = OrderProductTaxQuery::create()->findOneByOrderProductId($orderProduct->getId())) {
                if ($orderProduct->getWasInPromo()) {
                    $turnover += $orderProduct->getQuantity() * ($orderProduct->getPromoPrice() + $orderTax->getPromoAmount());
                } else {
                    $turnover += $orderProduct->getQuantity() * ($orderProduct->getPrice() + $orderTax->getAmount());
                }
            }
        }

        $turnover += $order->getPostage() + $order->getPostageTax() - $order->getDiscount();
        return $turnover;
    }

    /**
     * Delete all files from the sever
     */
    public function cleanFiles() {
        $errorMessage = null;

        $finder = new Finder();

        $currentUserId = $this->getSecurityContext()->getAdminUser()->getId();

        $finder->files()->in(THELIA_LOCAL_DIR . 'invoices/' . $currentUserId);

        foreach ($finder as $file) {
            @unlink($file);
        }

        return new JsonResponse([
            "status" => $errorMessage ? 'error' : 'success',
            "message" => $errorMessage ? $errorMessage : $this->getTranslator()->trans(
                'Report file correctly generated',
                [],
                ProcessAndInvoice::DOMAIN_NAME
            ),
        ], $errorMessage ? 500 : 200);
    }

    /***
     * Generate the report file
     *
     * @throws \Propel\Runtime\Exception\PropelException
     * @throws \Spipu\Html2Pdf\Exception\Html2PdfException
     */
    public function createReport() {
        $errorMessage = null;

        $htmltopdf = new Html2Pdf('P', 'A4', 'fr');

        $paidStatus = OrderStatusQuery::create()->findOneByCode('paid');
        $processingStatus = OrderStatusQuery::create()->findOneByCode('processing');

        $orders = OrderQuery::create()
            ->filterByOrderStatus($paidStatus)
            ->find()
        ;

        $totalTurnover = 0;
        foreach ($orders as $order) {
            $totalTurnover += $this->getOrderTurnover($order);
        }

        $rapport = $this->returnHTMLReport('InvoicesReport', round($totalTurnover, 2), count($orders));
        $htmltopdf->writeHTML($rapport);

        /** Sets all @var Order $order to processing status */
        foreach ($orders as $order) {
            $order->setOrderStatus($processingStatus)->save();
        }

        $currentUserId = $this->getSecurityContext()->getAdminUser()->getId();
        $fileName = THELIA_LOCAL_DIR . 'invoices/' . $currentUserId . '/report.pdf';
        $htmltopdf->output($fileName, 'F');

        return new JsonResponse([
            "status" => $errorMessage ? 'error' : 'success',
            "message" => $errorMessage ? $errorMessage : $this->getTranslator()->trans(
                'Report file correctly generated',
                [],
                ProcessAndInvoice::DOMAIN_NAME
            ),
        ], $errorMessage ? 500 : 200);
    }

    /**
     * Return the file as a response to be downloaded
     *
     * @return Response
     */
    public function downloadFile() {
        $finder = new Finder();
        $currentUserId = $this->getSecurityContext()->getAdminUser()->getId();
        $finder->files()->in(THELIA_LOCAL_DIR . 'invoices/' . $currentUserId . '/merged');

        foreach ($finder as $mergedFile) {
            $pdf = $mergedFile->getContents();
            $fileName = $mergedFile->getFilename();
        }

        return $this->pdfResponse($pdf, $fileName);
    }

    /**
     * Merge the different invoices PDF in a single file, as well as the report
     *
     * @return JsonResponse
     * @throws \Exception
     */
    public function mergeInvoices() {
        $errorMessage = null;

        $finder = new Finder();
        $currentUserId = $this->getSecurityContext()->getAdminUser()->getId();
        $finder->files()->in(THELIA_LOCAL_DIR . 'invoices/' . $currentUserId);

        $mergedPdf = new PdfManage();
        $mergedPdf->init();

        $reportFileName = THELIA_LOCAL_DIR . 'invoices/' . $currentUserId . '/report.pdf';

        $finder->sortByName();
        foreach ($finder as $invoiceFile) {
            if ($reportFileName !== $fileName = THELIA_LOCAL_DIR . 'invoices/' . $currentUserId . DS . $invoiceFile->getRelativePathname()) {
                try {
                    $mergedPdf->addPDF($fileName);
                } catch (\Exception $e) {
                    $errorMessage = $e->getMessage();
                }
            }
        }

        /** Check if report exists to handle merging for MultiOrder process */
        if (file_exists($reportFileName)) {
            $mergedPdf->addPDF($reportFileName);
        }

        $mergedFileName = 'invoices/' . $currentUserId . '/merged/' . 'ordersInvoice_' . (new \DateTime())->format("Y-m-d_H-i-s") . '.pdf';

        try {
            $mergedPdf->merge();
            $mergedPdf->save(THELIA_LOCAL_DIR . $mergedFileName, 'file');
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
        }

        return new JsonResponse([
            "status" => $errorMessage ? 'error' : 'success',
            "message" => $errorMessage ? $errorMessage : $this->getTranslator()->trans(
                'Merging finished',
                [],
                ProcessAndInvoice::DOMAIN_NAME
            ),
            "link" => URL::getInstance()->getBaseUrl() . '/admin/module/processandinvoice/download/',
        ], $errorMessage ? 500 : 200);
    }

    /**
     * Check if needed directories exist. Creates them otherwise
     */
    protected function checkDirectory() {
        $currentUserId = $this->getSecurityContext()->getAdminUser()->getId();
        $dir = new Filesystem();

        /** Don't change construct : warnings will kill the ajax response */
        try {
            if (!is_dir($concurrentDirectory = THELIA_LOCAL_DIR . 'invoices/' . $currentUserId)) {
                $dir->mkdir($concurrentDirectory);
            }

            if (!is_dir($concurrentDirectory = THELIA_LOCAL_DIR . 'invoices/' . $currentUserId . '/merged')) {
                $dir->mkdir($concurrentDirectory);
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Create the invoices for paid orders, 10 at a time
     *
     * @return Response|JsonResponse
     * @throws \Propel\Runtime\Exception\PropelException
     * @throws \Spipu\Html2Pdf\Exception\Html2PdfException
     */
    public function processAndInvoice() {
        $errorMessage = $this->checkDirectory();

        /** Make sure method was called by AJAX */
        if(!$this->getRequest()->isXmlHttpRequest()) {
            return $this->generateRedirectFromRoute('admin.order.list');
        }

        $request = $this->getRequest()->request;

        $turn = (int)$request->get('turn');
        $offset = 10 * $turn;

        $paidStatus = OrderStatusQuery::create()->findOneByCode('paid');

        $orders = OrderQuery::create()
            ->filterByOrderStatus($paidStatus)
            ->offset($offset)
            ->limit(10)
            ->find()
        ;

        if ($orders->count() > 0) {
            $htmltopdf = new Html2Pdf('P', 'A4', 'fr');

            $page = $offset;
            foreach ($orders as $order) {
                ++$page;
                $htmlInvoice = $this->returnHTMLInvoice($order->getId(), 'invoice');
                $htmltopdf->writeHTML($htmlInvoice);
            }

            $currentUserId = $this->getSecurityContext()->getAdminUser()->getId();

            $fileName = THELIA_LOCAL_DIR . 'invoices/' . $currentUserId . '/ordersInvoice_' . $turn . '.pdf';
            $htmltopdf->output($fileName, 'F');
        }

        /** JsonResponse for the AJAX call */
        return new JsonResponse([
            "status" => $errorMessage ? 'error' : 'success',
            "message" => $errorMessage ? $errorMessage : $this->getTranslator()->trans(
                "Part $turn of invoice : Done",
                [],
                ProcessAndInvoice::DOMAIN_NAME
            )
        ], $errorMessage ? 500 : 200);
    }

    /**
     * Create the invoices for multi-order, 10 at a time
     *
     * @return Response|JsonResponse
     * @throws \Propel\Runtime\Exception\PropelException
     * @throws \Spipu\Html2Pdf\Exception\Html2PdfException
     */
    public function multiOrderProcess() {
        $errorMessage = $this->checkDirectory();

        /** Make sure method was called by AJAX */
        if(!$this->getRequest()->isXmlHttpRequest()) {
            return $this->generateRedirectFromRoute('admin.order.list');
        }

        $request = $this->getRequest()->request;

        $turn = (int)$request->get('turn');
        $orders = $request->get('orders');
        $offset = 10 * $turn;
        $limit = $offset + 10;

        $htmltopdf = new Html2Pdf('P', 'A4', 'fr');

        while ($offset < $limit && $offset < count($orders)) {
            $htmlInvoice = $this->returnHTMLInvoice($orders[$offset], 'invoice');
            $htmltopdf->writeHTML($htmlInvoice);

            $invoiced = PdfInvoiceQuery::create()->filterByOrderId($orders[$offset]);
            $invoiced
                ->findOneOrCreate()
                ->setOrderId($orders[$offset])
                ->setInvoiced(1)
                ->save();

            $offset++;
        }

        $currentUserId = $this->getSecurityContext()->getAdminUser()->getId();

        $fileName = THELIA_LOCAL_DIR . 'invoices/' . $currentUserId . '/ordersInvoice_' . $turn . '.pdf';
        $htmltopdf->output($fileName, 'F');

        /** JsonResponse for the AJAX call */
        return new JsonResponse([
            "status" => $errorMessage ? 'error' : 'success',
            "message" => $errorMessage ? $errorMessage : $this->getTranslator()->trans(
                "Part $turn of invoicing : Done",
                [],
                ProcessAndInvoice::DOMAIN_NAME
            )
        ], $errorMessage ? 500 : 200);
    }

    /**
     * Process the form of the multi order tab to generate a list (array) of order IDs that will be invoiced, then return it
     * in a JSON response to be used with AJAX
     *
     * @return JsonResponse
     */
    public function multiOrderToPdf() {
        $errorMessage = null;

        $form = new InvoicingForm($this->getRequest());

        try {
            $orderForm = $this->validateForm($form)->getData();
            $orderList = [];

            $x = 0;

            $statusArray = [];
            if ($orderForm['status_paid']) {
                $statusArray[] = OrderStatusQuery::create()->findOneByCode('paid')->getId();
            }
            if ($orderForm['status_processing']) {
                $statusArray[] = OrderStatusQuery::create()->findOneByCode('processing')->getId();
            }
            if ($orderForm['status_sent']) {
                $statusArray[] = OrderStatusQuery::create()->findOneByCode('sent')->getId();
            }

            if (empty($statusArray)) {
                throw new \Exception('Veuillez sélectionner au moins un status de commande à traiter.');
            }

            if ($orderForm['order_day'] !== '' && $orderForm['order_day'] !== null) {
                /** @var \DateTime $date */
                $date = $orderForm['order_day']->format('Y-m-d') . '%';
                $ordersFromDay = OrderQuery::create()->where('`order`.`created_at` LIKE \'' . $date . '\'')->find();

                foreach ($ordersFromDay as $order) {
                    if (!PdfInvoiceQuery::create()->findOneByOrderId($order->getId()) && in_array($order->getStatusId(), $statusArray)) {
                        $orderList[$x] = $order->getId();
                        $x++;
                    }
                }

                if(0 === count($orderList)) {
                    throw new \Exception('Toutes les commandes ont été traitées pour cette journée avec ce(s) status de commande');
                }

            } else {
                if(empty($selectedOrders = $orderForm['order_id'])) {
                    throw new \Exception('Il faut sélectionner au moins une commande');
                }

                foreach ($selectedOrders as $selectedOrder => $value) {
                    if (!PdfInvoiceQuery::create()->findOneByOrderId($selectedOrder)
                        && in_array(OrderQuery::create()->findOneById($selectedOrder)->getStatusId(), $statusArray))
                    {
                        $orderList[$x] = $selectedOrder;
                        $x++;
                    }
                }
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
        }

        /** JsonResponse for the AJAX call */
        return new JsonResponse([
            "status" => $errorMessage ? 'error' : 'success',
            "message" => $errorMessage ? $errorMessage : $this->getTranslator()->trans(
                "MultiPdf",
                [],
                ProcessAndInvoice::DOMAIN_NAME
            ),
            "orderList" => $errorMessage ? null : $orderList,
            "orderNb" => $errorMessage ? null : $x
        ], 200);
    }

    /**
     * Set all orders as invoiced in table pdf_invoice
     *
     * @return RedirectResponse
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function setAllOrdersInvoiced() {
        $orders = OrderQuery::create()->find();

        foreach ($orders as $order) {
            $invoiced = PdfInvoiceQuery::create()
                ->filterById($order->getId())
                ->findOne()
                ;

            if (null === $invoiced) {
                $invoiced = new PdfInvoice();
            }

            $invoiced->setOrderId($order->getId())
                ->setInvoiced(1)
                ->save()
                ;

        }

        return new RedirectResponse(
            URL::getInstance()->absoluteUrl("/admin/module/ProcessAndInvoice")
        );
    }
}