# Process And Invoice

Add a "Process paid orders" button to the order tab in your backoffice. Pressing it will 
print the invoices for all orders with the status 'paid' along with a report
of all these orders, then put them in the status 'processing' instead.

1.2.0: Add a new "PDF Invoices" button next to the first one. Pressing it will open
an interface which allows you to choose the orders you want to invoice. You
have the choice between choosing a specific day or checking specific orders.
Do note that only orders with a selected status will be invoiced. 

## Installation

### Composer

Add it in your main Thelia composer.json file

```
composer require thelia/process-and-invoice-module:~2.0.0
```

### Caches

Make sure to clear your website caches after installing, or the routes 
won't work properly.