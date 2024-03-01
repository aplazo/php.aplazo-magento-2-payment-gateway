# Aplazo Payment Gateway for Magento 2 Version History
## 1.0.0
* Initial S-PRO version.
## 1.0.1
* Composer Release
## 1.0.2
* Fix for older Magento 2 versions
## 1.0.3
* fix for checkout flow.
## 1.0.4
* New S-PRO version.
## 1.0.5
* More than one product fix.
## 1.0.5.1
* Logo fix
## 1.0.9
* Add cron to cancel orders
## 1.0.9.1
* add conoce mas description and fix logo
## 1.0.10
* Add widgets to product and cart page
## 2.0.0
* Fix cronjob bugs
* Add webhook to listen Aplazo SDK Petitions
* Add error catalog to debug
## 2.1.0
* Add new logical to the payment method
## 2.1.1
* Refactor interfaces
## 2.2.0
* Optimize and fix js
## 2.3.0
* Fix webhook
## 2.3.1
* Fix thankyoupage
## 2.3.2
* Fix minicart and order number
## 2.3.3
* Fix customer email
## 2.3.4
* Fix composer.json
## 2.4.0
* Fix customer email data to magento 2.3
* Remove cronjob code
* Widgets Fix
## 2.4.1
* Fix header status code from curl
## 2.4.2
* change declaration into require-config.js
## 2.4.3
* fix widget functionality
## 2.4.4
* no cache on webhook controller
## 2.4.5
* fix js file
## 2.4.6
* add logger
## 2.4.7
* no cache fix varnish
## 2.5.0
* create webapi to listen Aplazo
* add custom logs from Aplazo
## 2.5.1
* fix version
## 2.6.0
* Refund added
## 2.6.1
* Partial refund added
## 2.6.3
* Version fix
## 2.6.4
* Partial refund fix
## 2.6.5
* Aplazo Order and magento bug communication fixed
## 2.6.6
* Php version properties fixed
## 2.6.7
* Webhook improved
## 3.0.0
* Change to Conexa version
## 3.0.1
* Sandbox changed from dev to net
## 3.0.2
* Refund added
## 3.0.3
* Orders Canceled by Cron with system configuration. Fix with multisource inventory reservation
## 3.0.4
* Console command removed. No needed more, it was replaced by the cron
## 3.0.5
* Cancel Endpoint used instead of refund endpoint when an order is cancelled
## 3.0.6
* Cancel Order Controller Removed
## 3.1.0
* Webhook security improved
## 3.1.1
* Webhook security expiration added
## 3.1.2
* Webhook security token changed to Bearer
## 3.1.3
* Added Configuration to send email order after webhook
## 3.1.4
* If email on webhook config is active, stop sending de new_order email when the order is created
## 3.2.0
* Added aplazo_checkout_url to db. Changed the way to create the aplazo loan. JWT Firebase used to validate the token.
## 3.2.1
* Bug Fixed.
## 3.2.2
* Webhook and cron errors solved.
## 3.2.3
* Object manager removed in product and cart page.
## 3.2.4
* Aplazo cartId changed by magento increment_id.
## 3.2.5
* Check order status in Aplazo before cancel in cron. Logs added in webhook.
## 3.2.6
* Not MSI stock.
## 3.2.7
* Success controller fix failure redirect.
## 3.2.8
* Success controller fix order id in session.
## 3.2.9
* Uploaded file.
## 3.2.10
* Webhook advance the order to processing regardless the invoice creation
## 3.3.0
* PHP 8.2 fixes. Checkout agreements added. 
## 3.3.1
* Aplazo js script updated 
## 3.3.2
* Invoice managed in cronCancelOrders. Logs to Aplazo 
## 3.3.3
* Url Logs changed 
## 3.3.4
* Refund to Aplazo available via API 
## 3.3.5
* Code Refactor
* Url to Aplazo logs Fixed
* Added more logs to Aplazo
* In the cancel order process, the orders are sorted by newest. Try catch modified in order to get more scenarios with errors
* Added aplazo_order_canceled status in orders. Sometimes orders can't be canceled, and this orders are always iterating in the cron process.
