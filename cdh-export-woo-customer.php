<?php
/**
 * Plugin Name: CodeHive - Export WooCommerce Customer Data
 * Description: Export name and phone from your Woocommerce store.
 * Plugin URI: https://github.com/gabrielfilippi/cdh-export-woo-customer
 * Author: CodeHive
 * Author URI: https://codehive.com.br
 * Version: 1.1.2
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: chd_image_converter
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // security if access directly
}

class WC_HPOS_Export_To_CSV_Optimized {
    private $batch_size = 100; // batch size to proccess orders

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_export_menu' ] );
        add_action( 'wp_ajax_wc_hpos_start_export', [ $this, 'start_export_process' ] );
    }

    /**
     * Add submenu inside Woocommerce Menu
     */
    public function add_export_menu() {
        add_submenu_page(
            'woocommerce',
            'Exportar Clientes para Excel',
            'Exportar Clientes',
            'manage_woocommerce',
            'wc-hpos-export-csv',
            [ $this, 'render_export_page' ]
        );
    }

    /**
     * Render Export Page in ADMIN
     */
    public function render_export_page() {
        // register js file
        wp_enqueue_script(
            'wc-export-csv',
            plugins_url('assets/js/cdh-export-woo-customer.min.js', __FILE__),
            [ 'jquery' ],
            '1.0.0',
            true // load on footer
        );

        // localize js file
        wp_localize_script('wc-export-csv', 'exportCsvParams', [
            'nonce' => wp_create_nonce('woocommerce_admin'),
        ]);
    
        //print HTML
        echo '<div class="wrap">';
            echo '<h1>Exportar Dados de Clientes que Já Compraram para Excel</h1>';
            echo '<button id="start-export" class="button button-primary">Iniciar Exportação</button>';
            echo '<p id="export-status"></p>';
        echo '</div>';
    }

    public function start_export_process() {
        check_ajax_referer( 'woocommerce_admin', '_wpnonce' );

        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $upload_dir = wp_upload_dir();
        $file_name = '/codehive_export_woo_customer_and_users.csv';
        $file_path = $upload_dir['basedir'] . $file_name;

        // If it is the first batch, create the file and add the header
        if ( $offset === 0 && file_exists( $file_path ) ) {
            unlink( $file_path ); // Remove arquivo antigo
        }

        // Open the file and add the BOM at the beginning (to support accentuation in tools like Excel)
        $file = fopen( $file_path, $offset === 0 ? 'w' : 'a' );
        if ( $offset === 0 ) {
            // Add BOM to support UTF-8
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // Write the header separated by ";"
            $header = array_map(fn($col) => mb_convert_encoding($col, 'UTF-8'), [ 'ID', 'Nome', 'Sobrenome', 'Nome Completo', 'Telefone' ]);
            fputcsv( $file, $header, ';', '"' );
        }

        // Retrieve orders in batches
        $orders = wc_get_orders( [
            'limit'  => $this->batch_size,
            'offset' => $offset,
        ] );

        foreach ( $orders as $order ) {
            // Make sure the object is an instance of WC_Order and not a refund
            if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
                continue; // Ignore refunds or invalid objects
            }

            //If you are invited, we generate a UUID for you
            $user_id = $order->get_user_id();
            $user_id = $user_id ? $user_id : wp_generate_uuid4();

            //get data from order
            $first_name = $order->get_billing_first_name();
            $last_name  = $order->get_billing_last_name();
            $full_name  = $first_name . ' ' . $last_name;
            $phone      = preg_replace('/\D/', '', $order->get_billing_phone());

            $row = [
                mb_convert_encoding($user_id, 'UTF-8'),
                mb_convert_encoding($first_name, 'UTF-8'),
                mb_convert_encoding($last_name, 'UTF-8'),
                mb_convert_encoding($full_name, 'UTF-8'),
                mb_convert_encoding($phone, 'UTF-8'),
            ];
            fputcsv( $file, $row, ';', '"' );
        }

        // Check if there are more requests to process
        $next_offset = count( $orders ) === $this->batch_size ? $offset + $this->batch_size : null;

        // If it is the last batch, skip a line and execute the query for users (to get user that never bought)
        if ( !$next_offset ) {
            fputcsv( $file, [], ';' );  // Skip a line

            global $wpdb;
            $sql = "
                SELECT
                    users.id, 
                    (SELECT meta_value FROM wp_usermeta WHERE meta_key = 'first_name' and wp_usermeta.user_id = users.ID) AS first_name,
                    (SELECT meta_value FROM wp_usermeta WHERE meta_key = 'last_name' and wp_usermeta.user_id = users.ID) AS last_name,
                    (SELECT meta_value FROM wp_usermeta WHERE meta_key = 'billing_phone' and wp_usermeta.user_id = users.ID) AS billing_phone
                FROM wp_users users
                INNER JOIN wp_usermeta umeta ON umeta.user_id = users.ID
                WHERE EXISTS (SELECT 1 FROM wp_usermeta WHERE meta_key = 'billing_phone' AND wp_usermeta.user_id = users.ID)
                GROUP BY users.id
            ";
            
            $results = $wpdb->get_results( $sql );

            // Add the query data to the CSV
            foreach ( $results as $user ) {
                $row = [
                    mb_convert_encoding($user->id, 'UTF-8'),
                    mb_convert_encoding($user->first_name, 'UTF-8'),
                    mb_convert_encoding($user->last_name, 'UTF-8'),
                    mb_convert_encoding($user->first_name . ' ' . $user->last_name, 'UTF-8'),
                    mb_convert_encoding(preg_replace('/\D/', '', $user->billing_phone), 'UTF-8'),
                ];
                fputcsv( $file, $row, ';', '"' );
            }
        }

        fclose( $file );

        wp_send_json_success( [
            'message'     => "Processado até o lote com início no pedido $offset.",
            'next_offset' => $next_offset,
            'file_url'    => $next_offset ? null : $upload_dir['baseurl'] . $file_name,
        ] );
    }
}

new WC_HPOS_Export_To_CSV_Optimized();