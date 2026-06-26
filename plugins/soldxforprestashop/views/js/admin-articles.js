/**
 * Soldx for PrestaShop — Articles page JS.
 *
 * Handles select-all checkbox and form submit validation.
 */
(function () {
    'use strict';

    var msgs = (typeof soldxArticles !== 'undefined') ? soldxArticles : {
        noSelection: 'Please select at least one product.',
        pushing: 'Pushing…'
    };

    document.addEventListener('DOMContentLoaded', function () {
        // Select-all checkbox.
        var selectAll = document.getElementById('soldx-select-all');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                var checks = document.querySelectorAll('.soldx-row-check');
                for (var i = 0; i < checks.length; i++) {
                    checks[i].checked = selectAll.checked;
                }
            });
        }

        // Form submit — validate at least one product selected.
        var form = document.getElementById('soldx-sync-form');
        var bulkBtn = document.getElementById('soldx-bulk-sync');
        if (form && bulkBtn) {
            form.addEventListener('submit', function (e) {
                var checked = document.querySelectorAll('.soldx-row-check:checked');
                if (checked.length === 0) {
                    e.preventDefault();
                    alert(msgs.noSelection);
                    return false;
                }
                bulkBtn.disabled = true;
                bulkBtn.textContent = msgs.pushing;
            });
        }

        // Tag pill toggle — clicking the label toggles the checkbox.
        var pills = document.querySelectorAll('.soldx-pill');
        for (var i = 0; i < pills.length; i++) {
            (function (pill) {
                pill.addEventListener('click', function (e) {
                    if (e.target.tagName === 'INPUT') {
                        return;
                    }
                    e.preventDefault();
                    var cb = pill.querySelector('input[type="checkbox"]');
                    if (cb) {
                        cb.checked = !cb.checked;
                        if (cb.checked) {
                            pill.classList.add('soldx-pill--on');
                        } else {
                            pill.classList.remove('soldx-pill--on');
                        }
                    }
                });
            })(pills[i]);
        }
    });
})();
