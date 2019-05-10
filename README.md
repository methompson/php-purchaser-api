# PHP Purchaser API

This API receives notifications from Shopify, processes store orders and then places orders for Wheatgrass to a supplier that uses WooCommerce.

This API had to overcome a few challenges. Shopify will send multiple notifications for the same order in short order, so the script has to add each order to a database so that it can process each order one after another.

The script uses a lock file to determine if another worker is already processing orders.

Much of the data needed to process the orders isn't sent with the initial order, such as product meta data, or location meta data.

Sometimes customers manage to circumvent the Javascript that is implemented in the checkout process, including the process of selecting a shipping date. So, the Class retrieves the date or generates its own.
