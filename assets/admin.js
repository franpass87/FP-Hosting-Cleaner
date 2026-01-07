jQuery(document).ready(function($) {
    $('#fp-scan-button').on('click', function() {
        var $button = $(this);
        var $results = $('#fp-scan-results');
        
        $button.prop('disabled', true).text(fpHostingCleaner.strings.scanning);
        $results.hide().empty();
        
        $.ajax({
            url: fpHostingCleaner.ajax_url,
            type: 'POST',
            data: {
                action: 'fp_hosting_cleaner_scan',
                nonce: fpHostingCleaner.nonce
            },
            success: function(response) {
                $button.prop('disabled', false).text('Avvia Scansione');
                
                if (response.success) {
                    displayScanResults(response.data);
                    $results.show();
                } else {
                    $results.html('<div class="notice notice-error"><p>' + (response.data.message || fpHostingCleaner.strings.error) + '</p></div>').show();
                }
            },
            error: function(xhr, status, error) {
                $button.prop('disabled', false).text('Avvia Scansione');
                var errorMsg = fpHostingCleaner.strings.error;
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMsg = xhr.responseJSON.data.message;
                } else if (error) {
                    errorMsg += ' (' + error + ')';
                }
                $results.html('<div class="notice notice-error"><p><strong>Errore:</strong> ' + errorMsg + '</p><p><small>Controlla la console del browser (F12) per dettagli.</small></p></div>').show();
                console.error('FP Hosting Cleaner AJAX Error:', {xhr: xhr, status: status, error: error});
            }
        });
    });
    
    function displayScanResults(data) {
        var html = '<div class="card">';
        html += '<h2>Riepilogo Scansione</h2>';
        html += '<p><strong>File totali:</strong> ' + data.summary.total_files.toLocaleString() + '</p>';
        html += '<p><strong>Dimensione totale:</strong> ' + data.summary.total_size + '</p>';
        html += '<p><strong>Directory scansionate:</strong> ' + data.summary.scanned_dirs + '</p>';
        html += '</div>';
        
        html += '<div class="card" style="margin-top: 20px;">';
        html += '<h2>File da Pulire</h2>';
        
        for (var category in data.categories) {
            var cat = data.categories[category];
            if (cat.count === 0) continue;
            
            html += '<div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; background: #f9f9f9;">';
            html += '<h3>' + cat.label + ' (' + cat.count.toLocaleString() + ' - ' + cat.size + ')</h3>';
            
            if (cat.items && cat.items.length > 0) {
                html += '<div class="fp-file-list">';
                for (var i = 0; i < Math.min(cat.items.length, 50); i++) {
                    var item = cat.items[i];
                    var path = item.path || (item.files ? item.files[0].path : '');
                    var size = item.size || item.total_size || 0;
                    var sizeFormatted = formatBytes(size);
                    
                    html += '<div class="fp-file-item">';
                    html += '<strong>' + escapeHtml(path) + '</strong>';
                    html += ' <span class="fp-file-size">(' + sizeFormatted + ')</span>';
                    html += '</div>';
                }
                if (cat.items.length > 50) {
                    html += '<p><em>... e altri ' + (cat.items.length - 50) + ' file</em></p>';
                }
                html += '</div>';
            }
            
            html += '<button type="button" class="button button-secondary fp-clean-button" ';
            html += 'data-type="' + category + '" ';
            html += 'data-files=\'' + JSON.stringify(cat.items) + '\'>';
            html += 'Pulisci ' + cat.label;
            html += '</button>';
            
            html += '</div>';
        }
        
        html += '</div>';
        
        $('#fp-scan-results').html(html);
        
        // Bind clean buttons
        $('.fp-clean-button').on('click', function() {
            var $button = $(this);
            var type = $button.data('type');
            var files = $button.data('files');
            var categoryLabel = $button.closest('div').find('h3').text().split('(')[0].trim();
            
            // Prima conferma
            var fileCount = Array.isArray(files) ? files.length : 0;
            var totalSize = 0;
            if (Array.isArray(files)) {
                files.forEach(function(f) {
                    totalSize += (f.size || f.total_size || 0);
                });
            }
            
            var confirmMsg = '⚠️ ATTENZIONE ⚠️\n\n';
            confirmMsg += 'Stai per eliminare ' + fileCount + ' elementi dalla categoria: ' + categoryLabel + '\n';
            confirmMsg += 'Dimensione totale: ' + formatBytes(totalSize) + '\n\n';
            confirmMsg += 'I file verranno salvati in backup prima dell\'eliminazione.\n';
            confirmMsg += 'Sei ASSOLUTAMENTE SICURO di voler procedere?';
            
            if (!confirm(confirmMsg)) {
                return;
            }
            
            // Seconda conferma (doppia sicurezza)
            if (!confirm('Ultima conferma: Eliminare definitivamente ' + fileCount + ' file?\n\nQuesta operazione creerà un backup, ma eliminerà i file originali.')) {
                return;
            }
            
            $button.prop('disabled', true).text('Pulizia in corso...');
            
            $.ajax({
                url: fpHostingCleaner.ajax_url,
                type: 'POST',
                data: {
                    action: 'fp_hosting_cleaner_clean',
                    nonce: fpHostingCleaner.nonce,
                    type: type,
                    files: JSON.stringify(files),
                    dry_run: 'false'
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        var successMsg = '✅ Pulizia completata!\n\n';
                        successMsg += 'Eliminati: ' + data.deleted + ' file\n';
                        successMsg += 'Dimensione liberata: ' + formatBytes(data.total_size || 0) + '\n';
                        if (data.backed_up && data.backed_up.length > 0) {
                            successMsg += '\nBackup creati: ' + data.backed_up.length + ' file\n';
                            successMsg += 'I backup sono salvati in: wp-content/fp-hosting-cleaner-backups/';
                        }
                        if (data.failed > 0) {
                            successMsg += '\n⚠️ Alcuni file non sono stati eliminati (verifica i log)';
                        }
                        alert(successMsg);
                        $button.closest('div').fadeOut();
                    } else {
                        alert('❌ Errore: ' + (response.data.message || fpHostingCleaner.strings.error));
                        $button.prop('disabled', false).text('Pulisci ' + categoryLabel);
                    }
                },
                error: function() {
                    alert('❌ ' + fpHostingCleaner.strings.error);
                    $button.prop('disabled', false).text('Pulisci ' + categoryLabel);
                }
            });
        });
    }
    
    function formatBytes(bytes, decimals) {
        if (bytes === 0) return '0 Bytes';
        var k = 1024;
        var dm = decimals || 2;
        var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }
    
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
});
