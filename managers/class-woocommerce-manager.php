<?php

namespace MoneyManager\Managers;

defined( 'ABSPATH' ) || die( 'No direct script access allowed.' );

use MoneyManager\Models\Account;
use MoneyManager\Models\Category;
use MoneyManager\Models\Party;
use MoneyManager\Models\Transaction;
use WC_Order;

/**
 * Class WooCommerce_Manager
 * @package MoneyManager\Managers
 */
class WooCommerce_Manager
{
    /**
     * Check whether WooCommerce is active (including network activated)
     *
     * @return bool
     */
    public static function active()
    {
        $plugin_path = trailingslashit( WP_PLUGIN_DIR ) . 'woocommerce/woocommerce.php';

        return in_array( $plugin_path, wp_get_active_and_valid_plugins() ) ||
            is_multisite() && in_array( $plugin_path, wp_get_active_network_plugins() );
    }

    /**
     * Init WooCommerce integration
     */
    public static function init()
    {
        add_action( 'woocommerce_after_order_object_save', array( self::class, 'handle_order_save' ) );
    }

    /**
     * Handle WooCommerce order save event
     *
     * @param WC_Order $order
     */
    public static function handle_order_save( WC_Order $order )
    {
        $integration = self::settings();
        if ( $integration['enabled'] && in_array( $order->get_status(), $integration['paid_order_statuses'] ) ) {
            $need_save = false;
            $transaction = self::find_transaction( $order->get_id() );
            if ( $transaction ) {
                // Update amount just in case
                $new_amount = self::get_order_net_payment( $order );
                if ( $new_amount != $transaction->amount ) {
                    $transaction->amount = $new_amount;
                    $need_save = true;
                }
            } else {
                // Create new transaction
                $account = $integration['account_id'] ? Account::find( $integration['account_id'] ) : null;
                if ( $account ) {
                    $party = $integration['party_id'] ? Party::find( $integration['party_id'] ) : null;
                    $category = $integration['category_id'] ? Category::find( $integration['category_id'] ) : null;

                    $transaction = new Transaction();
                    $transaction->account_id = $account->id;
                    $transaction->party_id = $party ? $party->id : null;
                    $transaction->category_id = $category ? $category->id : null;
                    $transaction->date = self::get_order_date_paid( $order );
                    $transaction->type = 'income';
                    $transaction->amount = self::get_order_net_payment( $order );
                    $transaction->notes = sprintf( esc_html__( 'WooCommerce order #%d', 'money-manager' ), $order->get_id() );
                    $transaction->wc_order_id = $order->get_id();
                    $need_save = true;
                }
            }

            if ( $need_save && $transaction->save() ) {
                Account_Manager::refresh_balance( $transaction->account_id );
            }
        }
    }

    /**
     * Get WooCommerce integration settings
     *
     * @return array
     */
    public static function settings()
    {
        return get_option( 'money_manager_woocommerce', array() ) + array(
            'enabled' => false,
            'paid_order_statuses' => array( 'processing', 'completed' ),
            'account_id' => null,
            'party_id' => null,
            'category_id' => null,
            'auto_delete_transactions' => false,
        );
    }

    /**
     * Find transaction by order ID
     *
     * @param int $order_id
     * @return Transaction|null
     */
    public static function find_transaction( $order_id )
    {
        $transactions = Transaction::get_results( function ( $query ) use ( $order_id ) {
            global $wpdb;
            return $query . $wpdb->prepare( ' where wc_order_id = %d', $order_id );
        }, true );

        return empty ( $transactions ) ? null : $transactions[0];
    }

    /**
     * Get order Net Payment value
     *
     * @param WC_Order $order
     * @return float
     */
    public static function get_order_net_payment( WC_Order $order )
    {
        return $order->get_total() - $order->get_total_refunded();
    }

    /**
     * Get order payment date
     *
     * @param WC_Order $order
     * @return string
     */
    public static function get_order_date_paid( WC_Order $order )
    {
        return ( $order->get_date_paid() ?: $order->get_date_created() ?: new \WC_DateTime() )->date( 'Y-m-d' );
    }
}