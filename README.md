# Process And Invoice

Adds a "Process paid orders" button above the order list of the back office. The button prints
the invoices of every order in the `paid` status, followed by a report of these orders, as a single
PDF, and moves exactly these orders to the `processing` status.

Thelia 3.2 or later, with the Twig back office (`default-twig`). The 2.x line of this module is for
Thelia 2.

## Installation

```
composer require thelia/process-and-invoice-module:^3.0
php bin/console module:refresh
php bin/console module:activate ProcessAndInvoice
```

Activation creates the two tables of the module (`process_and_invoice_batch`,
`process_and_invoice_batch_order`) and never drops anything.

## Usage

The button needs the `UPDATE` access on orders (`admin.order`). When no order is paid, it answers
that there is nothing to process and opens nothing. Otherwise a click runs a batch:

1. The batch takes the orders that are `paid` at that moment. An order paid afterwards waits for
   the next batch.
2. The orders are processed ten at a time, each in its own transaction. The order row is locked and
   its status read again: an order no longer paid is left out. The invoice is printed with the
   `invoice` template of the active PDF template (the one the order sheet prints) and checked
   readable, then the order is moved to `processing` through `TheliaEvents::ORDER_UPDATE_STATUS`:
   the transition graph, the stock rules and the status actions configured in the back office apply,
   as for a change made by hand. The listeners of this event (status e-mails included) run inside the
   transaction of the order: if the change fails afterwards and is rolled back, an e-mail they sent
   is already gone.
3. Once every order is done, the report (`ProcessAndInvoice/invoices_report.html.twig`, which a PDF
   template may override) is printed after the invoices, and the whole document is downloaded. The
   files are merged by groups of fifty, so the number of open files stays low for any batch size.

An order the batch cannot process keeps its status, its invoice is left out of the document, and it
is listed under the button with the reason: no longer paid when its turn came, a transition the
graph refuses, an invoice that could not be printed, a failed status change. The batch goes on with
the other orders.

A single batch runs at a time. If the page is closed or an error stops the batch, the next click
resumes it: an order is either printed, moved and recorded, or none of these, so no order is
processed twice, even by two tabs running the same batch. When a batch cannot go on (its document
cannot be built, for instance), the error message offers to close it: the orders not processed yet
keep their status, the document is built from the processed orders when it can be, and the next
click opens a new batch.

The files live under `var/process-and-invoice/<batch id>/` of the project, out of the public
directory. The document of a batch is kept until the next batch starts.

### Event

`ProcessAndInvoiceEvents::ORDER_PROCESSED` (`process_and_invoice.order_processed`) is dispatched once
for each order the batch moves to `processing`, after the change is committed, with an
`OrderProcessedEvent`:

| Method | Returns |
|---|---|
| `getOrder()` | the `Thelia\Model\Order`, now in `processing` |
| `getBatchId()` | the id of the batch |

An order whose status change fails is never announced. A listener that throws is logged and does not
undo the processing. Use this event, rather than an action on the `processing` status, for what
belongs to the batch only (a "your order is being prepared" e-mail sent by the batch but not by a
change made by hand, for instance).

## Changes in 3.0.0

- Thelia 3.2 or later only (PHP 8.3 or later). `routing.xml`, the Smarty templates, the
  `config.xml` hooks and the `html2pdf` rendering are gone: routes are PHP attributes, the button is
  a Twig template on the `orders.top` hook (the Twig order list has no `orders.table-header`), the
  PDFs are rendered by the core (`TheliaEvents::GENERATE_PDF`, dompdf) and merged with FPDI
  (`setasign/fpdi` replaces `daltcore/lara-pdf-merger`).
- The status goes through `TheliaEvents::ORDER_UPDATE_STATUS` instead of being written to the order
  row: stock, transition graph, status actions and every listener of the shop now see the change.
- The orders moved to `processing` are exactly the orders whose invoice is in the document. The 2.x
  line printed the orders paid at the time of each part of ten, by offset, then moved to
  `processing` every order paid at the end, including orders paid in between whose invoice was never
  printed.
- A batch is recorded, so an interrupted run resumes without printing or moving an order twice.
- Each order is processed in its own transaction, under a lock on the order row: a status changed
  meanwhile is seen, and two steps running at once never process an order twice. A failure is
  listed and the batch goes on; a batch that cannot go on can be closed.
- The steps are POST requests that check the order `UPDATE` access and the back-office token. The
  2.x routes had no access check. The files moved from `local/invoices/<admin id>/` to
  `var/process-and-invoice/`.
- The report total is the order total computed by the core (`Order::getTotalAmount()`): the 2.x
  formula added the postage tax a second time and skipped the order lines without a tax row.
- New `ProcessAndInvoiceEvents::ORDER_PROCESSED` event.
- The "PDF Invoices" button (invoices by day or by selection, without status change), its
  "Set all orders as invoiced" configuration action and the `InvoicePdfOrderLoop` loop are removed.
  The `pdf_invoice` table they used is no longer read; it is left in place and can be dropped by
  hand.
- Translations: English, French, German.

## Tests

Integration tests, run from the root of the Thelia project against a disposable database whose name
ends with `_test` (created by `php bin/test-prepare`). Under `APP_ENV=test`, Symfony Dotenv skips
`.env.local`: the connection details and the kernel class of the project are passed explicitly.

```
DATABASE_HOST=<host> DATABASE_PORT=<port> DATABASE_NAME=processinvoice_test DATABASE_USER=<user> DATABASE_PASSWORD=<password> php bin/test-prepare
DATABASE_HOST=<host> DATABASE_PORT=<port> DATABASE_NAME=processinvoice_test DATABASE_USER=<user> DATABASE_PASSWORD=<password> \
  KERNEL_CLASS='App\Kernel' APP_ENV=test \
  vendor/bin/phpunit --bootstrap vendor/thelia/modules/ProcessAndInvoice/Tests/bootstrap.php vendor/thelia/modules/ProcessAndInvoice/Tests
```

`BatchTransactionTest`, `ConcurrentStepTest` and `UpdateTest` run outside the test transaction (real
rollback, two processes, DDL); they remove their fixtures themselves and `UpdateTest` puts the 3.0.0
tables back. `PdfMergerTest` merges 300 small PDFs in a child process limited to 64 open files.
