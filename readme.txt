=== PaymentSpring Gravity Forms Add-On ===
Contributors: rjwilson, jesseangell
Donate link: https://www.paymentspring.com/docs/integrations/wordpress
Tags: paymentspring, gravity forms, gravity, gravity form, payment, payments, authorize.net, paypal, credit card, credit cards, online payment, online payments
Requires at least: 3.6
Tested up to: 3.8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html


Secure credit card processing with PaymentSpring beautifully integrated into Wordpress and Gravity Forms

== Description ==

[PaymentSpring](https://www.paymentspring.com/) is a credit card processing gateway with a developer friendly API.  This plugin is officially supported by PaymentSpring and will allow you to send credit card data in a secure manner from a Gravity Forms form.

> This plugin will not work if Gravity Forms is not installed.  You will need to purchase your own gravity forms license.
> PaymentSpring API keys are required.  You can obtain your own by [registering for a free PaymentSpring test account](https://www.paymentspring.com/signup).

== Installation ==

1. Make sure that Gravity Forms is installed and activated
2. Upload the `paymentspring-gravity-forms` folder to the `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Add your PaymentSpring API keys to the PaymentSpring section on the Settings page (Forms->Settings).
5. Create a form, add the 'Credit Card' field that appears under 'Pricing Fields.'
6. Click the downwards pointing arrow inside that Credit Card field.  Select 'Use With PaymentSpring'
7. Inside the above credit card field map the amount field to a field that represents the amount you want to charge.
8. Inside the above credit card field, under the advanced tab, select Force SSL.

== Frequently Asked Questions ==

= How do I get support? =
This plugin is officially supported by PaymentSpring. If you're having a problem send us an e-mail at support@paymentspring.com

= Do I need to have SSL? =
Yes, we require any page that has a credit card field be encrypted by SSL

== Screenshots ==

1. Settings page
2. Form configuration
3. Form on webpage

== Changelog ==
= 1.0.0 =
* Initial release
