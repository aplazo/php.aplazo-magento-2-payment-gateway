# Installation steps
- in your magento project do following on base dir: 
```
composer require spro/aplazopayment
```
- enable module:
```
php bin/magento module:enable Spro_AplazoPayment
```
- do project redeploy:
```
php bin/magento setup:upgrade
```
- Go to Admin panel of website, Stores->Configutation->Sales->Payment Methods. Find there Aplazo Payment
- Obtain Api token and Merchant id in your Aplazo Account, put into corresponding config fields
- Enable payment method and clear cache.
- New method should appear on checkuout
