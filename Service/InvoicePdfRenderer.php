<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ProcessAndInvoice\Service;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\PdfEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\TemplateHelperInterface;
use Thelia\Model\Order;

/**
 * Renders the documents of a batch with the active PDF template and the core PDF
 * generation (TheliaEvents::GENERATE_PDF): the invoice is the template's own `invoice`,
 * the one the order sheet prints; the report is shipped by the module and may be
 * overridden by the PDF template.
 */
final readonly class InvoicePdfRenderer
{
    public const REPORT_TEMPLATE = 'ProcessAndInvoice/invoices_report';

    private const INVOICE_TEMPLATE = 'invoice';

    public function __construct(
        private ParserResolver $parserResolver,
        private TemplateHelperInterface $templateHelper,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function invoice(Order $order): string
    {
        $html = $this->render(self::INVOICE_TEMPLATE, ['order_id' => $order->getId()]);

        return $this->generate($html, self::INVOICE_TEMPLATE, (string) $order->getRef(), $order);
    }

    /**
     * @param list<ReportRow> $rows
     */
    public function report(array $rows, string $totalTurnover): string
    {
        $html = $this->render(self::REPORT_TEMPLATE, [
            'orders' => $rows,
            'total_turnover' => $totalTurnover,
            'total_orders' => \count($rows),
        ]);

        return $this->generate($html, self::REPORT_TEMPLATE, 'report', null);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function render(string $templateName, array $parameters): string
    {
        $pdfTemplate = $this->templateHelper->getActivePdfTemplate();

        $parser = $this->parserResolver->getParser($pdfTemplate->getAbsolutePath(), $templateName);
        $parser->setTemplateDefinition($pdfTemplate, true);

        return $parser->render($templateName, $parameters);
    }

    private function generate(string $html, string $templateName, string $fileName, ?Order $order): string
    {
        $event = new PdfEvent($html);
        $event->setTemplateName($templateName);
        $event->setFileName($fileName);

        if (null !== $order) {
            $event->setObject($order);
        }

        $this->dispatcher->dispatch($event, TheliaEvents::GENERATE_PDF);

        if (!$event->hasPdf()) {
            throw new \RuntimeException(\sprintf('The PDF generation produced nothing for "%s".', $fileName));
        }

        return (string) $event->getPdf();
    }
}
