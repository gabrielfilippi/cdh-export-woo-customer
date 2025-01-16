jQuery(document).ready(function ($) {
    $('#start-export').on('click', function () {
        const button = $(this);
        button.prop('disabled', true).text('Exportando...');
        $('#export-status').text('');

        const nonce = exportCsvParams.nonce;
        const processExport = (offset = 0) => {
            $.post(ajaxurl, {
                action: 'wc_hpos_start_export',
                _wpnonce: nonce, // Inclua o nonce aqui
                offset: offset,
            }, function (response) {
                if (response.success) {
                    $('#export-status').text(response.data.message);
                    if (response.data.next_offset) {
                        processExport(response.data.next_offset);
                    } else {
                        button.prop('disabled', false).text('Iniciar Exportação');
                        $('#export-status').append(
                            '<br>Exportação concluída! <a href="' +
                            response.data.file_url +
                            '" target="_blank">Baixar CSV</a>'
                        );
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
