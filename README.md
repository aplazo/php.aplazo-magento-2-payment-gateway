# Installation steps
- in your magento project do following on base dir: 
```
composer require aplazo/aplazopayment
```
- enable module:
```
php bin/magento module:enable Aplazo_AplazoPayment
```
- do project redeploy:
```
php bin/magento setup:upgrade
```
- Go to Admin panel of website, Stores->Configutation->Sales->Payment Methods. Find there Aplazo Payment
- Obtain Api token and Merchant id in your Aplazo Account, put into corresponding config fields
- Enable payment method and clear cache.
- New method should appear on checkuout
```
 aditional installation steps ( if anything fails)
```
1.- composer require aplazo/aplazopayment
2.- php bin/magento module:enable Aplazo_AplazoPayment
3.- php bin/magento setup:di:compile
4.- php bin/magento setup:upgrade
5.- php bin/magento cache:flush
Static  content: php bin/magento setup:static-content:deploy

NOTE: speficic for 2CAP php bin/magento setup:static-content:deploy es_MX
