<?php
/**
 * Plugin Name: CodeHive - Export WooCommerce Customer Data
 * Description: Export name and phone from your Woocommerce store.
 * Plugin URI: https://github.com/gabrielfilippi/cdh-export-woo-customer
 * Author: CodeHive
 * Author URI: https://codehive.com.br
 * Version: 1.0.0
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: chd_image_converter
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Saia se acessado diretamente
}

class WC_HPOS_Export_To_CSV_Optimized {
    private $batch_size = 100; // Número de pedidos processados por lote

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_export_menu' ] );
        add_action( 'wp_ajax_wc_hpos_start_export', [ $this, 'start_export_process' ] );
    }

    public function add_export_menu() {
        add_submenu_page(
            'woocommerce',
            'Exportar Clientes para CSV',
            'Exportar CSV',
            'manage_woocommerce',
            'wc-hpos-export-csv',
            [ $this, 'render_export_page' ]
        );
    }

    public function render_export_page() {
        echo '<div class="wrap">';
        echo '<h1>Exportar Dados de Clientes que Já Compraram para CSV</h1>';
        echo '<button id="start-export" class="button button-primary">Iniciar Exportação</button>';
        echo '<p id="export-status"></p>';
        echo '</div>';
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#start-export').on('click', function() {
                    const button = $(this);
                    button.prop('disabled', true).text('Exportando...');
                    $('#export-status').text('');

                    const nonce = '<?php echo wp_create_nonce( "woocommerce_admin" ); ?>';
                    const processExport = (offset = 0) => {
                        $.post(ajaxurl, {
                            action: 'wc_hpos_start_export',
                            _wpnonce: nonce, // Inclua o nonce aqui
                            offset: offset
                        }, function(response) {
                            if (response.success) {
                                $('#export-status').text(response.data.message);
                                if (response.data.next_offset) {
                                    processExport(response.data.next_offset);
                                } else {
                                    button.prop('disabled', false).text('Iniciar Exportação');
                                    $('#export-status').append('<br>Exportação concluída! <a href="' + response.data.file_url + '" target="_blank">Baixar CSV</a>');
                                }
                            } else {
                                $('#export-status').text('Erro: ' + response.data);
                                button.prop('disabled', false).text('Iniciar Exportação');
                            }
                        });
                    };

                    processExport();
                });
            });
        </script>
        <?php
    }

    public function start_export_process() {
        check_ajax_referer( 'woocommerce_admin', '_wpnonce' );

        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/codehive_export_woo_customer_and_users.csv';

        // Se for o primeiro lote, cria o arquivo e adiciona o cabeçalho
        if ( $offset === 0 && file_exists( $file_path ) ) {
            unlink( $file_path ); // Remove arquivo antigo
        }

        // Abre o arquivo e adiciona o BOM no início (para suportar acentuação em ferramentas como Excel)
        $file = fopen( $file_path, $offset === 0 ? 'w' : 'a' );
        if ( $offset === 0 ) {
            // Adiciona o BOM para suportar UTF-8
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // Escreve o cabeçalho separado por ";"
            $header = array_map(fn($col) => mb_convert_encoding($col, 'UTF-8'), [ 'ID', 'Nome', 'Sobrenome', 'Nome Completo', 'Telefone' ]);
            fputcsv( $file, $header, ';', '"' );
        }

        // Recupera pedidos em lotes
        $orders = wc_get_orders( [
            'limit'  => $this->batch_size,
            'offset' => $offset,
        ] );

        foreach ( $orders as $order ) {
            // Certifique-se de que o objeto é uma instância de WC_Order e não um reembolso
            if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
                continue; // Ignora reembolsos ou objetos inválidos
            }

            $user_id = $order->get_user_id();

            $user_id = $user_id ? $user_id : wp_generate_uuid4();

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

         // Verifica se há mais pedidos para processar
        $next_offset = count( $orders ) === $this->batch_size ? $offset + $this->batch_size : null;

        // Se for o último lote, pula uma linha e executa a query para usuários
        if ( !$next_offset ) {
            fputcsv( $file, [], ';' );  // Pula uma linha

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

            // Adiciona os dados da query ao CSV
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
            'file_url'    => $next_offset ? null : $upload_dir['baseurl'] . '/pedidos_woocommerce.csv',
        ] );
    }
}

new WC_HPOS_Export_To_CSV_Optimized();