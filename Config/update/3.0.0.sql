-- ProcessAndInvoice 3.0.0 update: the batch tables of the Thelia 3 processing.
-- The pdf_invoice table of the 2.x line is left in place, untouched: nothing reads it anymore.

SET FOREIGN_KEY_CHECKS = 0;

-- One run of the "Process paid orders" button. `open_marker` is 1 while the batch is
-- running and NULL once it is complete: its unique index allows a single open batch.
CREATE TABLE IF NOT EXISTS `process_and_invoice_batch`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `admin_id` INTEGER,
    `open_marker` TINYINT,
    `created_at` DATETIME NOT NULL,
    `completed_at` DATETIME,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `process_and_invoice_batch_open_marker_unique` (`open_marker`),
    INDEX `fi_process_and_invoice_batch_admin` (`admin_id`),
    CONSTRAINT `fk_process_and_invoice_batch_admin`
        FOREIGN KEY (`admin_id`)
            REFERENCES `admin` (`id`)
            ON UPDATE RESTRICT
            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The paid orders a batch took when it was opened, and what became of each of them.
CREATE TABLE IF NOT EXISTS `process_and_invoice_batch_order`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `batch_id` INTEGER NOT NULL,
    `order_id` INTEGER NOT NULL,
    `total_amount` DECIMAL(16,6),
    `processed_at` DATETIME,
    `failure` VARCHAR(32),
    PRIMARY KEY (`id`),
    UNIQUE INDEX `process_and_invoice_batch_order_unique` (`batch_id`, `order_id`),
    INDEX `fi_process_and_invoice_batch_order_order` (`order_id`),
    CONSTRAINT `fk_process_and_invoice_batch_order_batch`
        FOREIGN KEY (`batch_id`)
            REFERENCES `process_and_invoice_batch` (`id`)
            ON UPDATE RESTRICT
            ON DELETE CASCADE,
    CONSTRAINT `fk_process_and_invoice_batch_order_order`
        FOREIGN KEY (`order_id`)
            REFERENCES `order` (`id`)
            ON UPDATE RESTRICT
            ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
