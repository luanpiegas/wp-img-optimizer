(function($) {
    'use strict';

    $(function() {
        var optimizationInProgress = false;
        var i18n = imgOptimizer.i18n;

        // Habilita/desabilita campos de dimensão conforme toggle de resize
        function toggleDimensionFields() {
            var isEnabled = $('#img_optimizer_enable_resize').is(':checked');
            $('#max-width-field, #max-height-field').prop('disabled', !isEnabled);
            $('#row-max-width, #row-max-height').toggleClass('imgopt-disabled-row', !isEnabled);
        }

        // Serve WebP depende de Generate WebP
        function toggleServeWebp() {
            var webpOn = $('#img_optimizer_webp').is(':checked');
            $('#img_optimizer_serve_webp').prop('disabled', !webpOn);
            $('#row-serve-webp').toggleClass('imgopt-disabled-row', !webpOn);
        }

        toggleDimensionFields();
        toggleServeWebp();
        $('#img_optimizer_enable_resize').change(toggleDimensionFields);
        $('#img_optimizer_webp').change(toggleServeWebp);

        // Atualiza a barra de progresso customizada
        function updateProgressBar(processed, total) {
            var pct = (total > 0) ? (processed / total) * 100 : 0;
            $('#progress-bar-fill').css('width', Math.round(pct) + '%');
            $('#progress-text').text(Math.round(pct) + '%');
        }

        // Otimização em lote com progresso real
        $('#optimize-existing').click(function() {
            if (optimizationInProgress) {
                alert(i18n.in_progress);
                return;
            }

            optimizationInProgress = true;
            var button = $(this);
            button.prop('disabled', true).text(i18n.optimizing);
            $('#optimization-progress').show();
            updateProgressBar(0, 100);
            $('#current-file').text(i18n.starting);

            var totalImages = 0;
            var processedImages = 0;

            // Step 1: Get the list of images to process
            $.post(imgOptimizer.ajax_url, {
                action: 'optimize_existing_images',
                _ajax_nonce: imgOptimizer.nonce
            }).done(function(response) {
                if (response.success && response.data.total > 0) {
                    totalImages = response.data.total;
                    $('#current-file').text(i18n.found_images.replace('%d', totalImages));
                    processQueue();
                } else {
                    $('#current-file').text(response.data.message || i18n.no_images);
                    resetUI();
                }
            }).fail(function() {
                alert(i18n.start_error);
                resetUI();
            });

            function processQueue() {
                if (!optimizationInProgress) {
                    return;
                }

                $.post(imgOptimizer.ajax_url, {
                    action: 'get_optimization_progress',
                    _ajax_nonce: imgOptimizer.nonce
                }).done(function(response) {
                    if (response.success) {
                        processedImages++;
                        updateProgressBar(processedImages, totalImages);
                        if (response.data.processed_file !== i18n.none_sentinel) {
                            $('#current-file').html(i18n.optimized_label + ' <strong>' + response.data.processed_file + '</strong>. ' + i18n.remaining_label + ' ' + response.data.remaining);
                        }

                        if (response.data.remaining > 0) {
                            processQueue();
                        } else {
                            $('#current-file').text(i18n.batch_done);
                            setTimeout(resetUI, 2000);
                        }
                    } else {
                        processedImages++;
                        updateProgressBar(processedImages, totalImages);
                        $('#current-file').append('<br/><span style="color:red;">' + i18n.optimize_error + ' ' + (response.data.message || i18n.unknown_error) + '</span>');
                        if (totalImages > processedImages) {
                            setTimeout(processQueue, 1000);
                        } else {
                            $('#current-file').append('<br/>' + i18n.batch_done_errors);
                            setTimeout(resetUI, 3000);
                        }
                    }
                }).fail(function() {
                    alert(i18n.comm_error);
                    resetUI();
                });
            }

            function resetUI() {
                optimizationInProgress = false;
                button.prop('disabled', false).text(i18n.start_button);
                $('#optimization-progress').slideUp();
            }
        });
    });
})(jQuery);
