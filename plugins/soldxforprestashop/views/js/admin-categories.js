/**
 * Soldx for PrestaShop — Categories page JS.
 *
 * Handles: live search/filter, AJAX category creation, "Create All" batch.
 *
 * @author    Soldx
 * @copyright Soldx
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 * @version   0.1.0
 */
(function () {
    'use strict';

    var ajaxUrl = (typeof soldxCatsAjaxUrl !== 'undefined') ? soldxCatsAjaxUrl : '';
    var token = (typeof soldxToken !== 'undefined') ? soldxToken : '';

    document.addEventListener('DOMContentLoaded', function () {
        // --- Live search/filter ---
        var searchInput = document.getElementById('soldx-cat-search');
        var countSpan = document.querySelector('.soldx-search-count');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var q = searchInput.value.toLowerCase();
                var rows = document.querySelectorAll('.soldx-table tbody tr');
                var visible = 0;
                for (var i = 0; i < rows.length; i++) {
                    var name = (rows[i].getAttribute('data-name') || '').toLowerCase();
                    var show = name.indexOf(q) !== -1;
                    rows[i].style.display = show ? '' : 'none';
                    if (show) {
                        visible++;
                    }
                }
                if (countSpan) {
                    countSpan.textContent = visible + ' / ' + rows.length;
                }
            });
        }

        // --- Create category in Studio (individual buttons) ---
        var createButtons = document.querySelectorAll('.soldx-create-cat-btn');
        for (var i = 0; i < createButtons.length; i++) {
            createButtons[i].addEventListener('click', handleCreateCategory);
        }

        // --- Create All Unmapped ---
        var createAllBtn = document.getElementById('soldx-create-all');
        if (createAllBtn) {
            createAllBtn.addEventListener('click', handleCreateAll);
        }

        function handleCreateCategory(e) {
            e.preventDefault();
            var btn = e.currentTarget;
            var name = btn.getAttribute('data-wc-name');
            var termId = btn.getAttribute('data-wc-term-id');
            var parent = btn.getAttribute('data-wc-parent');

            // Find current select for this row.
            var row = btn.closest('tr');
            var select = row ? row.querySelector('.soldx-cat-select') : null;
            var parentId = '';
            if (select && select.value) {
                parentId = select.value;
            }

            btn.disabled = true;
            btn.textContent = 'Creating…';

            var formData = new FormData();
            formData.append('action', 'createCategory');
            formData.append('ajax', '1');
            formData.append('token', token);
            formData.append('designation', name);
            formData.append('wcTermId', termId);
            if (parentId) {
                formData.append('idParent', parentId);
            }

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success && data.category) {
                        var cat = data.category;
                        var catId = cat.id;
                        var catLabel = cat.designation || cat.reference || catId;

                        // Add to all selects.
                        var allSelects = document.querySelectorAll('.soldx-cat-select');
                        for (var j = 0; j < allSelects.length; j++) {
                            var opt = document.createElement('option');
                            opt.value = catId;
                            opt.textContent = catLabel;
                            allSelects[j].appendChild(opt);
                        }

                        // Select it for this row.
                        if (select) {
                            select.value = catId;
                        }

                        btn.textContent = 'Created ✓';
                        setTimeout(function () {
                            btn.textContent = '+ Studio';
                            btn.disabled = false;
                        }, 1500);
                    } else {
                        alert(data.message || 'Failed to create category.');
                        btn.textContent = '+ Studio';
                        btn.disabled = false;
                    }
                })
                .catch(function (err) {
                    var msg = 'Network error creating category.';
                    if (err && err.message) {
                        msg += '\n' + err.message;
                    }
                    alert(msg);
                    btn.textContent = '+ Studio';
                    btn.disabled = false;
                });
        }

        function handleCreateAll(e) {
            e.preventDefault();
            var btn = e.currentTarget;
            var rows = document.querySelectorAll('.soldx-table tbody tr');

            // Collect unmapped rows.
            var unmapped = [];
            for (var i = 0; i < rows.length; i++) {
                var select = rows[i].querySelector('.soldx-cat-select');
                var createBtn = rows[i].querySelector('.soldx-create-cat-btn');
                if (select && !select.value && createBtn) {
                    unmapped.push(createBtn);
                }
            }

            if (unmapped.length === 0) {
                alert('No unmapped categories to create.');
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Creating ' + unmapped.length + '…';

            var done = 0;
            var failed = 0;

            function processNext(idx) {
                if (idx >= unmapped.length) {
                    btn.textContent = 'Done (' + (done - failed) + ' created, ' + failed + ' failed)';
                    setTimeout(function () {
                        btn.textContent = 'Create All Unmapped in Studio';
                        btn.disabled = false;
                    }, 2000);
                    return;
                }

                var createBtn = unmapped[idx];
                var row = createBtn.closest('tr');
                var select = row ? row.querySelector('.soldx-cat-select') : null;
                var name = createBtn.getAttribute('data-wc-name');
                var termId = createBtn.getAttribute('data-wc-term-id');

                createBtn.disabled = true;
                createBtn.textContent = 'Creating…';

                var formData = new FormData();
                formData.append('action', 'createCategory');
                formData.append('ajax', '1');
                formData.append('token', token);
                formData.append('designation', name);
                formData.append('wcTermId', termId);

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        done++;
                        if (data.success && data.category) {
                            var cat = data.category;
                            var catId = cat.id;
                            var catLabel = cat.designation || cat.reference || catId;

                            var allSelects = document.querySelectorAll('.soldx-cat-select');
                            for (var j = 0; j < allSelects.length; j++) {
                                var opt = document.createElement('option');
                                opt.value = catId;
                                opt.textContent = catLabel;
                                allSelects[j].appendChild(opt);
                            }
                            if (select) {
                                select.value = catId;
                            }
                            createBtn.textContent = 'Created ✓';
                        } else {
                            failed++;
                            createBtn.textContent = 'Failed';
                        }
                        processNext(idx + 1);
                    })
                    .catch(function (err) {
                        done++;
                        failed++;
                        createBtn.textContent = 'Failed';
                        processNext(idx + 1);
                    });
            }

            processNext(0);
        }
    });
})();
