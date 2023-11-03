<?php


namespace ProcessAndInvoice\Controller;


use DateTime;
use Exception;
use LynX39\LaraPdfMerger\PdfManage;
use ProcessAndInvoice\Form\InvoicingForm;
use ProcessAndInvoice\Model\PdfInvoice;
use ProcessAndInvoice\Model\PdfInvoiceQuery;
use ProcessAndInvoice\ProcessAndInvoice;
use Propel\Runtime\Exception\PropelException;
use Spipu\Html2Pdf\Exception\Html2PdfException;
use Spipu\Html2Pdf\Html2Pdf;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\HttpFoundation\JsonResponse;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Order;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderProductTaxQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;
use Thelia\Tools\URL;

#[Route('/admin/module/ProcessAndInvoice', name: 'process_and_invoice_')]
class ProcessAndInvoiceController extends BaseAdminController
{
    public function __construct(
        protected SecurityContext $securityContext,
        protected RequestStack $requestStack
    )
    {}

    protected function returnHTMLInvoice($orderID, $fileName, TemplateHelperInterface $templateHelper)
    {
        return $this->renderRaw(
            $fileName,
            array(
                'order_id' => $orderID
            ),
            $templateHelper->getActivePdfTemplate()
        );
    }

    protected function returnHTMLReport($fileName, $totalTurnover, $totalOrders, TemplateHelperInterface $templateHelper)
    {
        return $this->renderRaw(
            $fileName,
            array(
                'total_turnover' => $totalTurnover,
                'total_orders' => $totalOrders
            ),
            $templateHelper->getActivePdfTemplate()
        );
    }

    /**
     * @throws PropelException
     */
    protected function getOrderTurnover(Order $order): float|int|string|null
    {
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
                    $turnover += $orderProduct->getQuantity() * ((float)$orderProduct->getPrice() + (float)$orderTax->getAmount());
                }
            }
        }

        $turnover += (float)$order->getPostage() + (float)$order->getPostageTax() - (float)$order->getDiscount();
        return $turnover;
    }

    /**
     * Delete all files from the sever
     */
    #[Route('/clean', name: 'clean')]
    public function cleanFiles(): JsonResponse
    {
        $errorMessage = $this->checkDirectory();

        $finder = new Finder();

        $currentUserId = $this->securityContext->getAdminUser()->getId();

        if(is_dir(THELIA_LOCAL_DIR . 'invoices/' . $currentUserId)) {

            $finder->files()->in(THELIA_LOCAL_DIR . 'invoices/' . $currentUserId);

            foreach ($finder as $file) {
                @unlink($file);
            }
        }

        return new JsonResponse([
            "status" => $errorMessage ? 'error' : 'success',
            "message" => $errorMessage ?: Translator::getInstance()->trans(
                'Report file correctly generated',
                [],
                ProcessAndInvoice::DOMAIN_NAME
            ),
        ], $errorMessage ? 500 : 200);
    }

    /***
     * Generate the report file
     *
     * @throws PropelException
     * @throws Html2PdfException
     */
    #[Route('/report', name: 'report')]
    public function createReport(TemplateHelperInterface $templateHelper): JsonResponse
    {
        $errorMessage = null;

        $htmlToPdf = new Html2Pdf('P', 'A4', 'fr');

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

        $rapport = $this->returnHTMLReport(
            'InvoicesReport', round($totalTurnover, 2), count($orders), $templateHelper);
        $htmlToPdf->writeHTML($rapport);

        /** Sets all @var Order $order to processing status */
        foreach ($orders as $order) {
            $order->setOrderStatus($processingStatus)->save();
        }

        $currentUserId = $this->securityContext->getAdminUser()->getId();
        $fileName = THELIA_LOCAL_DIR . 'invoices/' . $currentUserId . '/report.pdf';
        $htmlToPdf->output($fileName, 'F');

        return new JsonResponse([
            "status" => $errorMessage ? 'error' : 'success',
            "message" => $errorMessage ?: Translator::getInstance()->trans(
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
    #[Route('/download', name: 'download')]
    public function downloadFile(): Response
    {
        $pdf = "";
        $fileName = "";

        $finder = new Finder();
        $currentUserId = $this->securityContext->getAdminUser()->getId();
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
     * @throws Exception
     */
    #[Route('/merge', name: 'merge')]
    public function mergeInvoices(): JsonResponse
    {
        $errorMessage = null;

        $finder = new Finder();
        $currentUserId = $this->securityContext->getAdminUser()->getId();
        $finder->files()->in(THELIA_LOCAL_DIR . 'invoices/' . $currentUserId);

        $mergedPdf = new PdfManage();
        $mergedPdf->init();

        $reportFileName = THELIA_LOCAL_DIR . 'invoices/' . $currentUserId . '/report.pdf';

        $finder->sortByName();

        foreach ($finder as $invoiceFile) {
            if ($reportFileName !== $fileName = THELIA_LOCAL_DIR . 'invoices/' . $currentUserId . DS . $invoiceFile->getRelativePathname()) {
                try {
                    $mergedPdf->addPDF($fileName);
                } catch (Exception $e) {
                    $errorMessage = $e->getMessage();
                }
            }
        }

        /** Check if a report exists to handle merging for MultiOrder process */
        if (file_exists($reportFileName)) {
            $mergedPdf->addPDF($reportFileName);
        }

        $mergedFileName = 'invoices/' . $currentUserId . '/merged/' . 'ordersInvoice_' . (new DateTime())->format("Y-m-d_H-i-s") . '.pdf';

        try {
            $mergedPdf->merge();
            $mergedPdf->save(THELIA_LOCAL_DIR . $mergedFileName);
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
        }

        return new JsonResponse([
            "status" => $errorMessage ? 'error' : 'success',
            "message" => $errorMessage ?: Translator::getInstance()->trans(
                'Merging finished',
                [],
                ProcessAndInvoice::DOMAIN_NAME
            ),
            "link" => URL::getInstance()->getBaseUrl() . '/admin/module/ProcessAndInvoice/download/',
        ], $errorMessage ? 500 : 200);
    }

    /**
     * Check if needed directories exist. Creates them otherwise
     */
    protected function checkDirectory(): ?string
    {
        $currentUserId = $this->securityContext->getAdminUser()->getId();
        $dir = new Filesystem();

        /** Don't change construct: warnings will kill the ajax response */
        try {
            if (!is_dir($concurrentDirectory = THELIA_LOCAL_DIR . 'invoices/')) {
                $dir->mkdir($concurrentDirectory);
            }

            if (!is_dir($concurrentDirectory = THELIA_LOCAL_DIR . 'invoices/' . $currentUserId)) {
                $dir->mkdir($concurrentDirectory);
            }

            if (!is_dir($concurrentDirectory = THELIA_LOCAL_DIR . 'invoices/' . $currentUserId . '/merged')) {
                $dir->mkdir($concurrentDirectory);
            }
        } catch (Exception $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Create the invoices for paid orders, 10 at a time
     *
     * @param TemplateHelperInterface $templateHelper
     * @return Response|JsonResponse
     * @throws Html2PdfException
     * @throws PropelException
     */
    #[Route('', name: 'process_and_invoice', methods: 'POST')]
    public function processAndInvoice(TemplateHelperInterface $templateHelper): JsonResponse|Response
    {
        $errorMessage = $this->checkDirectory();

        /** Make sure AJAX called the method */
        if(!$this->requestStack->getCurrentRequest()->isXmlHttpRequest()) {
            return $this->generateRedirectFromRoute('admin.order.list');
        }

        $request = $this->requestStack->getCurrentRequest()->request;

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
            $htmlToPdf = new Html2Pdf('P', 'A4', 'fr');

            $page = $offset;
            foreach ($orders as $order) {
                ++$page;
                $htmlInvoice = $this->returnHTMLInvoice($order->getId(), 'invoice', $templateHelper);
                $htmlToPdf->writeHTML($htmlInvoice);
            }

            $currentUserId = $this->securityContext->getAdminUser()->getId();

            $fileName = THELIA_LOCAL_DIR . 'invoices/' . $currentUserId . '/ordersInvoice_' . $turn . '.pdf';
            $htmlToPdf->output($fileName, 'F');
        }

        /** JsonResponse for the AJAX call */
        return new JsonResponse([
            "status" => $errorMessage ? 'error' : 'success',
            "message" => $errorMessage ?: Translator::getInstance()->trans(
                "Part $turn of invoice : Done",
                [],
                ProcessAndInvoice::DOMAIN_NAME
            )
        ], $errorMessage ? 500 : 200);
    }

    /**
     * Create the invoices for multi-order, 10 at a time
     *
     * @param TemplateHelperInterface $templateHelper
     * @return Response|JsonResponse
     * @throws Html2PdfException
     * @throws PropelException
     */
    #[Route('/multi-process', name: 'multi_process')]
    public function multiOrderProcess(TemplateHelperInterface $templateHelper): JsonResponse|Response
    {
        $errorMessage = $this->checkDirectory();

        /** Make sure AJAX called the method */
        if(!$this->requestStack->getCurrentRequest()->isXmlHttpRequest()) {
            return $this->generateRedirectFromRoute('admin.order.list');
        }

        $request = $this->requestStack->getCurrentRequest()->request;

        $turn = (int)$request->get('turn');
        $orders = (array)$request->get('orders');
        $offset = 10 * $turn;
        $limit = $offset + 10;

        $htmlToPdf = new Html2Pdf('P', 'A4', 'fr');

        while ($offset < $limit && $offset < count($orders)) {
            $htmlInvoice = $this->returnHTMLInvoice($orders[$offset], 'invoice', $templateHelper);
            $htmlToPdf->writeHTML($htmlInvoice);

            $invoiced = PdfInvoiceQuery::create()->filterByOrderId($orders[$offset]);
            $invoiced
                ->findOneOrCreate()
                ->setOrderId($orders[$offset])
                ->setInvoiced(1)
                ->save();

            $offset++;
        }

        $currentUserId = $this->securityContext->getAdminUser()->getId();

        $fileName = THELIA_LOCAL_DIR . 'invoices/' . $currentUserId . '/ordersInvoice_' . $turn . '.pdf';
        $htmlToPdf->output($fileName, 'F');

        /** JsonResponse for the AJAX call */
        return new JsonResponse([
            "status" => $errorMessage ? 'error' : 'success',
            "message" => $errorMessage ?: Translator::getInstance()->trans(
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
    #[Route('/multi', name: 'multi')]
    public function multiOrderToPdf(): JsonResponse
    {
        $errorMessage = null;

        $form = $this->createForm(InvoicingForm::getName());

        $orderList = [];
        $x = 0;
        try {
            $orderForm = $this->validateForm($form)->getData();

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
                throw new Exception('Veuillez sélectionner au moins un status de commande à traiter.');
            }

            if ($orderForm['order_day'] !== '' && $orderForm['order_day'] !== null) {
                /** @var DateTime $date */
                $date = $orderForm['order_day']->format('Y-m-d') . '%';
                $ordersFromDay = OrderQuery::create()->where('`order`.`created_at` LIKE \'' . $date->format('Y-m-d') . '\'')->find();

                foreach ($ordersFromDay as $order) {
                    if ($invoiced = PdfInvoiceQuery::create()->findOneByOrderId($order->getId())) {
                        if (!$invoiced->getInvoiced() && in_array($order->getStatusId(), $statusArray)) {
                            $orderList[$x] = $order->getId();
                            $x++;
                        }
                    }
                    if (!$invoiced && in_array($order->getStatusId(), $statusArray)) {
                        $orderList[$x] = $order->getId();
                        $x++;
                    }
                }

                if(0 === count($orderList)) {
                    throw new Exception('Toutes les commandes ont été traitées pour cette journée avec ce(s) status de commande');
                }

            } else {
                if(empty($selectedOrders = $orderForm['order_id'])) {
                    throw new Exception('Il faut sélectionner au moins une commande');
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
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
        }

        /** JsonResponse for the AJAX call */
        return new JsonResponse([
            "status" => $errorMessage ? 'error' : 'success',
            "message" => $errorMessage ?: Translator::getInstance()->trans(
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
     * @throws PropelException
     */
    #[Route('/set_all', name: 'set_all')]
    public function setAllOrdersInvoiced(): RedirectResponse
    {
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