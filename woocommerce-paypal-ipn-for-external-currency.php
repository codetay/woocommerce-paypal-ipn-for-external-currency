<?php

/**
 *
 * @link              https://developer.vn
 * @since             1.0.0
 * @package           Woocommerce_Paypal_Ipn_For_External_Currency
 *
 * @wordpress-plugin
 * Plugin Name:       WooCommerce Paypal IPN for External Currency
 * Plugin URI:        https://codetay.com
 * Description:       This plugin will enable currency not supported by Paypal. It will exchange money by the config rate to USD so that can make payment via Paypal.
 * Version:           1.0.0
 * Author:            CODETAY
 * Author URI:        https://developer.vn
 * Text Domain:       woocommerce-paypal-ipn-for-external-currency
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

require_once WP_PLUGIN_DIR . '/woocommerce/includes/gateways/paypal/includes/class-wc-gateway-paypal-response.php';
require_once plugin_dir_path(__FILE__) . 'includes/framework/framework.php';

class Woocommerce_Paypal_Ipn_For_External_Currency extends WC_Gateway_Paypal_Response
{

    protected $pluginName = 'woocommerce-paypal-ipn-for-external-currency';
    protected $exchangeRate;
    protected $receiverEmail;
    protected $options;

    public function __construct()
    {

        // Control core classes for avoid errors
        if (class_exists('CSF')) {

            // Create options
            CSF::createOptions($this->pluginName, array(
                'framework_title' => 'WooCommerce Paypal IPN for External Currency',
                'footer_credit' => 'Made with &#9825; by <a href="https://codetay.com" title="#CODETAY">#CODETAY</a>',
                'show_reset_all' => false,
                'show_reset_section' => false,
                'show_search' => false,
                'show_bar_menu' => false,
                'menu_title' => 'WooCommerce Paypal IPN for External Currency',
                'menu_slug' => $this->pluginName,
                'menu_type' => 'submenu',
                'menu_parent' => 'edit.php?post_type=product',
            ));

            //
            // Create a section
            CSF::createSection($this->pluginName, array(
                'title' => 'Currency Code',
                'fields' => array(

                    array(
                        'id' => 'currencyCode',
                        'type' => 'text',
                        'title' => 'Currency Code',
                        'sanitize' => true,
                    ),

                    array(
                        'id' => 'exchangeRate',
                        'type' => 'number',
                        'title' => 'Exchange Rate',
                        'sanitize' => true,
                    ),

                )
            ));

        }

        $this->options = get_option($this->pluginName);
        $this->exchangeRate = $this->options['exchangeRate'];

        add_filter('woocommerce_paypal_supported_currencies', [$this, 'add_vnd_paypal_valid_currency']);
        add_filter('woocommerce_paypal_args', [$this, 'convert_vnd_to_usd'], 11);

        add_action('valid-paypal-standard-ipn-request', [$this, 'checkIPN']);

    }

    public function add_vnd_paypal_valid_currency($currencies)
    {
        array_push($currencies, strtoupper($this->options['currencyCode']));
        return $currencies;

    }

    public function convert_vnd_to_usd($paypal_args)
    {
        WC_Gateway_Paypal::log('Encoded Paypal Args: '.json_encode($paypal_args));

        if (strtolower($paypal_args['currency_code']) == strtolower($this->options['currencyCode'])) {

            $convertRate = $this->options['exchangeRate'];
            $paypal_args['currency_code'] = 'USD';

            $i = 1;

            if (isset($paypal_args['amount_' . $i])) {
                $paypal_args['amount_' . $i] = round($paypal_args['amount_' . $i] / $convertRate, 2);
            }

            if ($paypal_args['shipping_'.$i] > 0) {
                $paypal_args['shipping_'.$i] = round($paypal_args['shipping_'.$i] / $convertRate, 2);
            }

            if ($paypal_args['discount_amount_cart'] > 0) {
                $paypal_args['discount_amount_cart'] = round($paypal_args['discount_amount_cart'] / $convertRate, 2);
            }

            if ($paypal_args['tax_cart'] > 0) {
                $paypal_args['tax_cart'] = round($paypal_args['tax_cart'] / $convertRate, 2);
            }

        }

        return $paypal_args;

    }


    public function checkIPN($posted)
    {

        $order = !empty($posted['custom']) ? $this->get_paypal_order($posted['custom']) : false;

        if ($order) {

            // Lowercase returned variables.
            $posted['payment_status'] = strtolower($posted['payment_status']);

            WC_Gateway_Paypal::log('Found order #' . $order->get_id());
            WC_Gateway_Paypal::log('Payment status: ' . $posted['payment_status']);

            if (method_exists($this, 'payment_status_' . $posted['payment_status'])) {
                call_user_func(array($this, 'payment_status_' . $posted['payment_status']), $order, $posted);
            }
        }

        die; // stop others checking because we all checked here !
    }

    /**
     * Handle a completed payment.
     *
     * @param WC_Order $order Order object.
     * @param array $posted Posted data.
     */
    protected function payment_status_completed($order, $posted)
    {
        if ($order->has_status(wc_get_is_paid_statuses())) {
            WC_Gateway_Paypal::log('Aborting, Order #' . $order->get_id() . ' is already complete.');
            exit;
        }

        $this->validate_transaction_type($posted['txn_type']);
        $this->validate_amount($order, $posted['mc_gross'], $posted['mc_currency']);
        $this->validate_receiver_email($order, $posted['receiver_email']);
        $this->save_paypal_meta_data($order, $posted);

        if ('completed' === $posted['payment_status']) {
            if ($order->has_status('cancelled')) {
                $this->payment_status_paid_cancelled_order($order, $posted);
            }

            if (!empty($posted['mc_fee'])) {
                $order->add_meta_data('PayPal Transaction Fee', wc_clean($posted['mc_fee']));
            }

            $this->payment_complete($order, (!empty($posted['txn_id']) ? wc_clean($posted['txn_id']) : ''), __('IPN payment completed', 'woocommerce'));
        } else {
            if ('authorization' === $posted['pending_reason']) {
                $this->payment_on_hold($order, __('Payment authorized. Change payment status to processing or complete to capture funds.', 'woocommerce'));
            } else {
                /* translators: %s: pending reason. */
                $this->payment_on_hold($order, sprintf(__('Payment pending (%s).', 'woocommerce'), $posted['pending_reason']));
            }
        }
    }

    /**
     * Check for a valid transaction type.
     *
     * @param string $txn_type Transaction type.
     */
    protected function validate_transaction_type($txn_type)
    {
        $accepted_types = array('cart', 'instant', 'express_checkout', 'web_accept', 'masspay', 'send_money', 'paypal_here');

        if (!in_array(strtolower($txn_type), $accepted_types, true)) {
            WC_Gateway_Paypal::log('Aborting, Invalid type:' . $txn_type);
            exit;
        }
    }

    /**
     * Check payment amount from IPN matches the order.
     *
     * @param WC_Order $order Order object.
     * @param int $amount Amount to validate.
     * @param $currency
     */
    protected function validate_amount($order, $amount, $currency)
    {
        $orderCurrency = $order->get_currency();

        // Convert based rate if difference currency
        if (strtolower($orderCurrency) == 'vnd' && strtolower($currency) == 'usd') {
            $amount *= $this->exchangeRate;
        }

        WC_Gateway_Paypal::log('Order Amount:' . number_format($order->get_total(), 2, '.', '') . ' | Exchanged Amount: ' . number_format($amount, 2, '.', ''));

        if (number_format($order->get_total(), 2, '.', '') !== number_format($amount, 2, '.', '')) {
            WC_Gateway_Paypal::log('Payment error: Amounts do not match (gross ' . $amount . ')');

            /* translators: %s: Amount. */
            $order->update_status('on-hold', sprintf(__('Validation error: PayPal amounts do not match (gross %s).', 'woocommerce'), $amount));
            exit;
        }
    }

    /**
     * Check receiver email from PayPal. If the receiver email in the IPN is different than what is stored in.
     * WooCommerce -> Settings -> Checkout -> PayPal, it will log an error about it.
     *
     * @param WC_Order $order Order object.
     * @param string $receiver_email Email to validate.
     */
    protected function validate_receiver_email($order, $receiver_email)
    {
        $payment_gateway = WC()->payment_gateways->payment_gateways()['paypal'];
        $this->receiverEmail = $payment_gateway->receiver_email ?: $payment_gateway->email;

        if (strcasecmp(trim($receiver_email), trim($this->receiverEmail)) !== 0) {
            WC_Gateway_Paypal::log("IPN Response is for another account: {$receiver_email}. Your email is {$this->receiverEmail}");

            /* translators: %s: email address . */
            $order->update_status('on-hold', sprintf(__('Validation error: PayPal IPN response from a different email address (%s).', 'woocommerce'), $receiver_email));
            exit;
        }
    }

    /**
     * Save important data from the IPN to the order.
     *
     * @param WC_Order $order Order object.
     * @param array $posted Posted data.
     */
    protected function save_paypal_meta_data($order, $posted)
    {
        if (!empty($posted['payment_type'])) {
            update_post_meta($order->get_id(), 'Payment type', wc_clean($posted['payment_type']));
        }
        if (!empty($posted['txn_id'])) {
            update_post_meta($order->get_id(), '_transaction_id', wc_clean($posted['txn_id']));
        }
        if (!empty($posted['payment_status'])) {
            update_post_meta($order->get_id(), '_paypal_status', wc_clean($posted['payment_status']));
        }
    }

    /**
     * When a user cancelled order is marked paid.
     *
     * @param WC_Order $order Order object.
     * @param array $posted Posted data.
     */
    protected function payment_status_paid_cancelled_order($order, $posted)
    {
        $this->send_ipn_email_notification(
        /* translators: %s: order link. */
            sprintf(__('Payment for cancelled order %s received', 'woocommerce'), '<a class="link" href="' . esc_url($order->get_edit_order_url()) . '">' . $order->get_order_number() . '</a>'),
            /* translators: %s: order ID. */
            sprintf(__('Order #%s has been marked paid by PayPal IPN, but was previously cancelled. Admin handling required.', 'woocommerce'), $order->get_order_number())
        );
    }

    /**
     * Send a notification to the user handling orders.
     *
     * @param string $subject Email subject.
     * @param string $message Email message.
     */
    protected function send_ipn_email_notification($subject, $message)
    {
        $new_order_settings = get_option('woocommerce_new_order_settings', array());
        $mailer = WC()->mailer();
        $message = $mailer->wrap_message($subject, $message);

        $woocommerce_paypal_settings = get_option('woocommerce_paypal_settings');
        if (!empty($woocommerce_paypal_settings['ipn_notification']) && 'no' === $woocommerce_paypal_settings['ipn_notification']) {
            return;
        }

        $mailer->send(!empty($new_order_settings['recipient']) ? $new_order_settings['recipient'] : get_option('admin_email'), strip_tags($subject), $message);
    }

}

new Woocommerce_Paypal_Ipn_For_External_Currency();