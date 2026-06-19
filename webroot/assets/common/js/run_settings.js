import $ from 'jquery';
import { ajaxErrorHandling, bootstrap_alert } from './main.js';

(function () {
    "use strict";
    function make_editor(i, elm) {
        var textarea = $(elm);
        var mode = textarea.data('editor');

        var editDiv = $('<div>', {
            position: 'absolute',
            width: "100%",
            height: textarea.height(),
            'class': textarea.attr('class')
        }).insertBefore(textarea);

        textarea.css('display', 'none');

        var editor = ace.edit(editDiv[0]);
        editor.setOptions({
            minLines: textarea.attr('rows') ? textarea.attr('rows') : 3,
            maxLines: 200
        });
        editor.setTheme("ace/theme/textmate");
        var session = editor.getSession();
        session.setValue(textarea.val());
        textarea.on('change', function () {
            session.setValue(textarea.val());
        });

        session.setUseWrapMode(true);
        session.setMode("ace/mode/" + mode);

        var form = $(elm).parents('form');
        editor.on('change', function () {
            form.trigger("change");
        });
        // Ensure textarea is updated before ANY form submission (AJAX or otherwise)
        form.on('submit ajax_submission', function () {
            textarea.val(session.getValue());
        });
    }
    function save_settings(i, elm) {
        $(elm).prop("disabled", true);
        var form = $(elm).parents('form');
        form.change(function () {
            $(elm).prop("disabled", false);
        }).submit(function () {
            // Prevent default submit for forms containing .save_settings buttons ONLY if triggered by non-button submit
            // The button click handler below will handle submission via AJAX
            return false; 
        });
        $(elm).click(function (e) {
            form.trigger("ajax_submission"); // Sync ACE editor
            e.preventDefault();
            $.ajax({
                url: form.attr('action'),
                dataType: 'html',
                data: form.serialize(),
                method: 'POST'
            }).done(function (data) {
                if (data !== '') {
                    form.prevAll('.run-settings-alerts').remove();
                    $('<div class="run-settings-alerts"></div>').html(data).insertBefore(form);
                }
                $(elm).prop("disabled", true);
            }).fail(function (e, x, settings, exception) {
                ajaxErrorHandling(e, x, settings, exception);
                $(elm).prop("disabled", false); // Re-enable button on failure
            });

            return false;
        });
    }

    // PWA Icon Upload and Clear Logic (Async/Await)
    async function handlePwaIconUpload(event) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        const submitButton = form.querySelector('button[type="submit"]');

        if (submitButton) {
            submitButton.disabled = true;
        }

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            
            if (data.success) {
                bootstrap_alert(data.messages.join('<br>'), 'Success', '.alerts-container', 'alert-success');
                // Reload to see changes (updated path, potentially clear button visibility)
                setTimeout(() => location.reload(), 1000);
            } else {
                bootstrap_alert(data.messages.join('<br>'), 'Error', '.alerts-container', 'alert-danger');
                if (submitButton) {
                    submitButton.disabled = false;
                }
            }
        } catch (error) {
            console.error('PWA Icon Upload Error:', error);
            bootstrap_alert('An error occurred during upload: ' + error.message, 'Error', '.alerts-container', 'alert-danger');
            if (submitButton) {
                submitButton.disabled = false;
            }
        }
    }

    async function handleClearPwaIcons() {
        if (!confirm('Are you sure you want to clear all PWA icons? This will delete the files and remove the path setting.')) {
            return;
        }
        
        const clearButton = document.getElementById('clear_pwa_icons_button');
        if (clearButton) {
            clearButton.disabled = true;
        }

        // We need the clear URL. Let's assume it's stored in a data attribute on the button.
        const clearUrl = clearButton?.dataset.actionUrl; 
        if(!clearUrl) {
             bootstrap_alert('Could not find clear URL. The data-action-url attribute might be missing on the clear button.', 'Error', '.alerts-container', 'alert-danger');
             console.error('Clear PWA Icons button missing data-action-url attribute');
             if (clearButton) {
                clearButton.disabled = false;
            }
             return;
        }

        try {
            const response = await fetch(clearUrl, {
                method: 'POST' // Assuming POST is appropriate
            });
            const data = await response.json();

            if (data.success) {
                bootstrap_alert(data.messages.join('<br>'), 'Success', '.alerts-container', 'alert-success');
                setTimeout(() => location.reload(), 1000);
            } else {
                bootstrap_alert(data.messages.join('<br>'), 'Error', '.alerts-container', 'alert-danger');
                 if (clearButton) {
                    clearButton.disabled = false;
                }
            }
        } catch (error) {
            console.error('Clear PWA Icons Error:', error);
            bootstrap_alert('An error occurred while clearing icons: ' + error.message, 'Error', '.alerts-container', 'alert-danger');
            if (clearButton) {
                clearButton.disabled = false;
            }
        }
    }

    // Attach listeners after DOM is ready
    $(function () {
        $('textarea.big_ace_editor').each(make_editor);
        $(".save_settings").each(save_settings);
        // Handle manifest generation
        $('.generate-manifest').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('clicked');
            e.stopImmediatePropagation(); // Stop any other click handlers on this element
            
            var $btn = $(this);
            var originalHtml = $btn.html();
            
            // Show loading state
            $btn.html('<i class="fa fa-spinner fa-spin"></i> Generating...').prop('disabled', true);
            
            $.ajax({
                url: $btn.data('href'),
                type: 'POST',
                dataType: 'json',
                success: function(response) {
                    if (response.error) {
                        alert(response.error);
                    } else {
                        if (response.cookie_expiry_adjusted) {
                            bootstrap_alert(
                                'PWA manifest generated.<br><br>Cookie expiry was automatically increased to 1 year so the study can resume after closing the browser/app. You might want to include a request_cookie item in your PWA onboarding survey, to ensure that participants have given permissions to track them across sessions. Otherwise, the PWA will break after a few days.',
                                'Notice',
                                '.alerts-container',
                                'alert-warning'
                            );
                        } else {    
                            bootstrap_alert(
                                'PWA manifest generated.<br><br>Cookie expiry was already >= 1 year, so the study can resume after closing the browser/app. You might want to include a request_cookie item in your PWA onboarding survey, to ensure that participants have given permissions to track them across sessions. Otherwise, the PWA will break after a few days.',
                                'Notice',
                                '.alerts-container',
                                'alert-warning'
                            );
                        }

                        const manifest = response.manifest || response;
                        // Update the manifest textarea if it exists
                        var $manifestArea = $('#manifest_json');
                        if ($manifestArea.length) {
                            const manifest_json = JSON.stringify(manifest, null, 2);
                            $manifestArea.val(manifest_json);
                            $manifestArea.trigger('change');
                        }
                    }
                },
                error: function(xhr) {
                    alert('Failed to generate manifest: ' + (xhr.responseText || 'Unknown error'));
                },
                complete: function() {
                    // Restore button state
                    $btn.html(originalHtml).prop('disabled', false);
                }
            });
        });

        // Initialize PWA Icon Upload Form Handler
        const pwaIconsForm = document.getElementById('pwa_icons_form');
        if (pwaIconsForm) {
            pwaIconsForm.addEventListener('submit', handlePwaIconUpload);
        }

        // Initialize PWA Icon Clear Button Handler
        const clearPwaIconsButton = document.getElementById('clear_pwa_icons_button');
        if (clearPwaIconsButton) {
            clearPwaIconsButton.addEventListener('click', handleClearPwaIcons);
        }

        // --- Secrets management (extracted from settings.php inline JS) ---
        {
            const secretsTable = document.getElementById('secrets-table');
            if (secretsTable) {
                const secretsTbody = document.getElementById('secrets-tbody');
                const secretsSaveUrl = secretsTable.dataset.saveUrl;
                const secretsSaveIndicator = document.getElementById('secrets-save-indicator');
                const secretsAlertsContainer = document.getElementById('secrets-alerts');

                jQuery('[data-toggle="tooltip"]').tooltip();

                // Keep in sync with RunSecret::NAME_PATTERN server-side.
                const SECRET_NAME_RE = /^[A-Za-z0-9_]+$/;

                // Write-only protocol: value null = keep stored value,
                // string = create/replace, name absent = delete.
                function collectSecrets() {
                    var secrets = {};
                    var rows = secretsTbody.querySelectorAll('tr');
                    rows.forEach(function(row) {
                        var nameInput = row.querySelector('.secret-name-hidden');
                        var valueInput = row.querySelector('.secret-value');
                        if (nameInput && valueInput) {
                            var name = nameInput.value.trim();
                            if (name) {
                                secrets[name] = valueInput.dataset.dirty === '1' ? valueInput.value : null;
                            }
                        }
                    });
                    return secrets;
                }

                function hasSecretName(name) {
                    var found = false;
                    var rows = secretsTbody.querySelectorAll('tr');
                    rows.forEach(function(row) {
                        var input = row.querySelector('.secret-name-hidden');
                        if (input && input.value.trim() === name) {
                            found = true;
                        }
                    });
                    return found;
                }

                function saveSecrets() {
                    var secrets = collectSecrets();
                    secretsSaveIndicator.style.visibility = 'visible';

                    var formData = new FormData();
                    formData.append('secrets_json', JSON.stringify(secrets));

                    fetch(secretsSaveUrl, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function(r) { return r.text(); })
                        .then(function(html) {
                            secretsSaveIndicator.innerHTML = '<i class="fa fa-check"></i> Saved';
                            if (html.indexOf('alert-danger') !== -1) {
                                secretsAlertsContainer.innerHTML = html;
                            }
                            // Saved values become write-only: blank the field
                            // so the plaintext doesn't linger in the DOM.
                            secretsTbody.querySelectorAll('.secret-value[data-dirty="1"]').forEach(function(input) {
                                delete input.dataset.dirty;
                                input.value = '';
                                input.type = 'password';
                                input.placeholder = '(unchanged — type to replace)';
                            });
                            setTimeout(function() {
                                secretsSaveIndicator.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';
                                secretsSaveIndicator.style.visibility = 'hidden';
                            }, 1200);
                        })
                        .catch(function() {
                            secretsSaveIndicator.style.visibility = 'hidden';
                            secretsAlertsContainer.innerHTML = '<div class="alert alert-danger">Failed to save secrets.</div>';
                        });
                }

                // On the whole table so the tfoot "new secret" toggle works too.
                secretsTable.addEventListener('click', function(e) {
                    var btn = e.target.closest('.secret-toggle');
                    if (btn) {
                        var wrap = btn.closest('.secret-value-wrap');
                        if (!wrap) return;
                        var input = wrap.querySelector('input');
                        if (!input) return;
                        input.type = input.type === 'password' ? 'text' : 'password';
                        btn.querySelector('i').className = input.type === 'password' ? 'fa fa-eye' : 'fa fa-eye-slash';
                        return;
                    }

                    var del = e.target.closest('.delete-secret');
                    if (del) {
                        jQuery(del).tooltip('destroy');
                        del.closest('tr').remove();
                        saveSecrets();
                    }
                });

                secretsTbody.addEventListener('input', function(e) {
                    var input = e.target.closest('.secret-value');
                    if (input) {
                        input.dataset.dirty = '1';
                    }
                });

                secretsTbody.addEventListener('blur', function(e) {
                    var input = e.target.closest('.secret-value');
                    if (input && input.dataset.dirty === '1') {
                        saveSecrets();
                    }
                }, true);

                document.getElementById('new-secret-name').addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('add-secret-btn').click(); }
                });
                document.getElementById('new-secret-value').addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('add-secret-btn').click(); }
                });

                document.getElementById('add-secret-btn').addEventListener('click', function() {
                    var nameInput = document.getElementById('new-secret-name');
                    var valueInput = document.getElementById('new-secret-value');
                    var name = nameInput.value.trim();
                    var value = valueInput.value.trim();

                    if (!name) { alert('Please enter a secret name.'); return; }
                    if (!SECRET_NAME_RE.test(name)) {
                        alert('Secret names may only contain letters, digits and underscores.');
                        return;
                    }
                    if (!value) { alert('Please enter a secret value.'); return; }
                    if (hasSecretName(name)) {
                        alert('A secret with this name already exists.');
                        return;
                    }

                    // Built with DOM APIs: values assigned via .value are
                    // never HTML-parsed, so nothing needs stripping and the
                    // secret round-trips byte-exact.
                    var tr = document.createElement('tr');

                    var tdName = document.createElement('td');
                    var codeEl = document.createElement('code');
                    codeEl.textContent = 'secret_' + name;
                    tdName.appendChild(codeEl);

                    var tdValue = document.createElement('td');
                    var nameHidden = document.createElement('input');
                    nameHidden.type = 'hidden';
                    nameHidden.className = 'secret-name-hidden';
                    nameHidden.value = name;
                    var wrap = document.createElement('div');
                    wrap.className = 'secret-value-wrap';
                    var valueField = document.createElement('input');
                    valueField.type = 'password';
                    valueField.className = 'form-control input-sm secret-value';
                    valueField.autocomplete = 'new-password';
                    valueField.value = value;
                    valueField.dataset.dirty = '1'; // new secret: send the value once
                    var toggleBtn = document.createElement('button');
                    toggleBtn.type = 'button';
                    toggleBtn.className = 'secret-toggle';
                    toggleBtn.setAttribute('data-toggle', 'tooltip');
                    toggleBtn.title = 'Show what you typed';
                    toggleBtn.innerHTML = '<i class="fa fa-eye"></i>';
                    wrap.appendChild(valueField);
                    wrap.appendChild(toggleBtn);
                    tdValue.appendChild(nameHidden);
                    tdValue.appendChild(wrap);

                    var tdDelete = document.createElement('td');
                    var deleteBtn = document.createElement('button');
                    deleteBtn.type = 'button';
                    deleteBtn.className = 'btn btn-danger btn-xs delete-secret';
                    deleteBtn.setAttribute('data-toggle', 'tooltip');
                    deleteBtn.title = 'Delete secret';
                    deleteBtn.innerHTML = '<i class="fa fa-trash"></i>';
                    tdDelete.appendChild(deleteBtn);

                    tr.appendChild(tdName);
                    tr.appendChild(tdValue);
                    tr.appendChild(tdDelete);
                    secretsTbody.appendChild(tr);
                    jQuery(tr).find('[data-toggle="tooltip"]').tooltip();

                    nameInput.value = '';
                    valueInput.value = '';
                    nameInput.focus();
                    saveSecrets();
                });
            }
        }

        // --- Ingestion keys management (mirrors API credentials UX) ---
        {
            const ingestPanel = document.getElementById('ingest-keys-panel');
            if (ingestPanel) {
                const createUrl = ingestPanel.dataset.createUrl;
                const revokeUrl = ingestPanel.dataset.revokeUrl;
                const apiBaseUrl = ingestPanel.dataset.apiBaseUrl;
                const runName = ingestPanel.dataset.runName;
                const labelInput = document.getElementById('ingest-label-input');
                const sourceInput = document.getElementById('ingest-source-input');
                const createBtn = document.getElementById('ingest-create-btn');
                const listWrap = document.getElementById('ingest-keys-list-wrap');
                const onceBox = document.getElementById('ingest-key-once');

                const SOURCE_PATTERN = /^[A-Za-z0-9_.\-]{1,50}$/;

                function escAttr(s) {
                    return String(s)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;');
                }

                function showOnceBox(key, sourceName) {
                    ingestPanel.querySelector('.ingest-out-key').textContent = key;
                    var curlCmd = 'curl -X POST ' + escAttr(apiBaseUrl) + '/ingest/' + escAttr(runName) + '/<key> \\\n' +
                        '  -H "Content-Type: application/json" \\\n' +
                        '  -H "X-Api-Key: <key>" \\\n' +
                        '  -d \'{"ref": "participant_42", "data": {"score": 7.3}}\'';
                    ingestPanel.querySelector('.ingest-out-curl').textContent = curlCmd;
                    onceBox.classList.remove('hidden');
                    jQuery(onceBox).find('.copy-on-click').each(function () {
                        jQuery(this).off('click.copy').on('click.copy', function () {
                            try {
                                var text = jQuery(this).text();
                                navigator.clipboard.writeText(text);
                                jQuery(this).tooltip({title: 'Copied!', position: 'top'}).tooltip('show');
                                var self = this;
                                setTimeout(function () { jQuery(self).tooltip('destroy'); }, 1500);
                            } catch (e) {}
                        });
                    });
                }

                function ensureTable() {
                    var table = listWrap.querySelector('.ingest-keys-list');
                    if (table) return table;
                    listWrap.querySelector('.ingest-keys-empty').remove();
                    table = document.createElement('table');
                    table.className = 'table table-striped ingest-keys-list';
                    table.innerHTML = '<thead><tr><th>Label</th><th>Source</th><th>Created</th><th>Last used</th><th></th></tr></thead><tbody></tbody>';
                    listWrap.appendChild(table);
                    return table;
                }

                function addRow(data) {
                    var table = ensureTable();
                    var tbody = table.querySelector('tbody');
                    var tr = document.createElement('tr');
                    tr.dataset.id = data.id;
                    tr.innerHTML =
                        '<td class="ingest-key-label">' + escAttr(data.label) + '</td>' +
                        '<td><code class="ingest-key-source">' + escAttr(data.source_name) + '</code></td>' +
                        '<td class="ingest-key-created">' + escAttr(data.created) + '</td>' +
                        '<td class="ingest-key-last-used"><span class="text-muted">never</span></td>' +
                        '<td><button type="button" class="btn btn-danger btn-xs revoke-ingest-key"><i class="fa fa-trash"></i> Revoke</button></td>';
                    tbody.prepend(tr);
                    return tr;
                }

                createBtn.addEventListener('click', function () {
                    var label = (labelInput.value || '').trim();
                    var source = (sourceInput.value || '').trim();

                    if (!source) {
                        alert('Please enter a source namespace (1–50 chars: letters, digits, dot, dash, underscore).');
                        return;
                    }
                    if (!SOURCE_PATTERN.test(source)) {
                        alert('Source must be 1–50 characters: letters, digits, dot, dash, or underscore.');
                        return;
                    }

                    createBtn.disabled = true;
                    var fd = new FormData();
                    fd.append('label', label);
                    fd.append('ingest_source', source);

                    fetch(createUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (r) { return r.json(); })
                        .then(function (json) {
                            createBtn.disabled = false;
                            if (!json || !json.success) {
                                alert((json && json.message) || 'Could not create ingestion key.');
                                return;
                            }
                            showOnceBox(json.data.key, json.data.source_name);
                            addRow(json.data);
                            labelInput.value = '';
                            sourceInput.value = '';
                            labelInput.focus();
                        })
                        .catch(function () {
                            createBtn.disabled = false;
                            alert('Request failed.');
                        });
                });

                sourceInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); createBtn.click(); }
                });
                labelInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); createBtn.click(); }
                });

                ingestPanel.addEventListener('click', function (e) {
                    var revokeBtn = e.target.closest('.revoke-ingest-key');
                    if (!revokeBtn) return;
                    var row = revokeBtn.closest('tr');
                    if (!row) return;
                    var id = row.dataset.id;
                    if (!confirm('Revoke this ingestion key? Tools using it will stop working immediately. The key will be removed from this list.')) return;

                    revokeBtn.disabled = true;
                    var fd = new FormData();
                    fd.append('id', id);

                    fetch(revokeUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (r) { return r.json(); })
                        .then(function (json) {
                            if (json && json.success) {
                                var tbody = row.parentNode;
                                row.remove();
                                if (tbody && tbody.children.length === 0) {
                                    var tbl = tbody.closest('table');
                                    if (tbl) tbl.remove();
                                    var emptyP = document.createElement('p');
                                    emptyP.className = 'text-muted ingest-keys-empty';
                                    emptyP.innerHTML = '<em>You have no ingestion keys yet. Create one below.</em>';
                                    listWrap.appendChild(emptyP);
                                }
                            } else {
                                revokeBtn.disabled = false;
                                alert((json && json.message) || 'Could not revoke that key.');
                            }
                        })
                        .catch(function () { revokeBtn.disabled = false; alert('Request failed.'); });
                });
            }
        }

        // --- Save & test R Syntax (extracted from settings.php inline JS) ---
        {
            const rForm = document.querySelector('#r-functions form');
            if (rForm) {
                const rResultEl = document.getElementById('r-code-parse-result');
                if (rResultEl) {
                    const rValidateUrl = rForm.dataset.validateUrl;
                    let rBusy = false;

                    function escapeHtml(text) {
                        var d = document.createElement('div');
                        d.appendChild(document.createTextNode(text));
                        return d.innerHTML;
                    }

                    rForm.querySelector('.btn-save-test-r-code').addEventListener('click', async function() {
                        if (rBusy) return;
                        rBusy = true;
                        jQuery(rForm).trigger('ajax_submission');
                        var textarea = rForm.querySelector('textarea[name="custom_r"]');
                        if (!textarea) { rBusy = false; return; }
                        var code = textarea.value;

                        rResultEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';

                        try {
                            var saveRes = await fetch(rForm.action, {
                                method: 'POST',
                                body: new FormData(rForm),
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            });
                            if (!saveRes.ok) {
                                rBusy = false;
                                rResultEl.innerHTML = '<span class="text-warning"><i class="fa fa-warning"></i> Save failed</span>';
                                return;
                            }

                            var saveHtml = await saveRes.text();
                            if (saveHtml) {
                                var pane = rForm.closest('.tab-pane');
                                if (pane) {
                                    var tmp = document.createElement('div');
                                    tmp.innerHTML = saveHtml;
                                    while (tmp.firstChild) {
                                        pane.insertBefore(tmp.firstChild, pane.firstChild);
                                    }
                                }
                            }

                            if (code.trim() === '') {
                                rBusy = false;
                                rResultEl.innerHTML = '';
                                return;
                            }

                            rResultEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Checking syntax…';
                            var fd = new FormData();
                            fd.append('r_code', code);
                            var checkRes = await fetch(rValidateUrl, {
                                method: 'POST',
                                body: fd,
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            });
                            var data = await checkRes.json();
                            rBusy = false;

                            if (data.valid === true) {
                                rResultEl.innerHTML = '<span class="text-success"><i class="fa fa-check"></i> R syntax is valid</span>';
                            } else if (data.valid === false) {
                                var rLastCode = code;
                                var rLastError = data.message;
                                rResultEl.innerHTML = '<pre class="text-danger" style="white-space: pre-wrap; margin: 8px 0">'
                                    + escapeHtml(data.message)
                                    + '</pre>'
                                    + '<div style="margin: 6px 0"><p class="pull-right"><button type="button" class="btn btn-sm btn-default" title="Copy code + error for LLM"><i class="fa fa-clipboard"></i> Copy for LLM</button></p></div>';
                                var copyBtn = rResultEl.querySelector('button');
                                if (copyBtn) {
                                    copyBtn.addEventListener('click', function() {
                                        var text = 'TASK: Debug this R code syntax error.\n\nCODE:\n' + rLastCode + '\n\nERROR:\n' + rLastError;
                                        navigator.clipboard.writeText(text);
                                    });
                                }
                            } else {
                                rResultEl.innerHTML = '<span class="text-warning"><i class="fa fa-warning"></i> ' + (data.message || 'Could not validate') + '</span>';
                            }
                        } catch (e) {
                            rBusy = false;
                            rResultEl.innerHTML = '<span class="text-warning"><i class="fa fa-warning"></i> Save or syntax check failed</span>';
                        }
                    });
                }
            }
        }
    });

})();
