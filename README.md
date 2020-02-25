# Process And Invoice

Add a "Process paid orders" button to the order tab in your backoffice. Pressing it will 
print the invoices for all orders with the status 'paid', along with a report
of all these orders, then put them in the status 'processing' instead.

1.2.0 : Add a new "PDF Invoices" button next to the first one. Pressing it will open
an interface which allows you to choose the orders you want to invoice. You
have the choice between choosing a specific day or checking specific orders.
Do note that only orders with a selected status will be invoiced. 

## Installation

### Manually

* Copy the module into ```<thelia_root>/local/modules/``` directory and be sure that the name of the module is ProcessAndInvoice.
* Activate it in your Thelia administration panel

### Composer

Add it in your main Thelia composer.json file

```
composer require thelia/process-and-invoice-module:~1.2.0
```

###2.3.X

The module should theoretically work on 2.3.X, but you'll need to
add spipu/html2pdf to your vendors and change the module supported
version (the <thelia></thelia> tag)

###Caches

Make sure to clear your website caches after installing, or the routes 
won't work properly.