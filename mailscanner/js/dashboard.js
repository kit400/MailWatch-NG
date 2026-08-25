/**
 * MailWatch-NG Interactive Dashboard Engine
 * Copyright (C) 2026 MailWatch-NG / EFA-NG Project
 */

(function () {
    'use strict';

    var isEditMode = false;
    var autoRefreshInterval = 0;
    var autoRefreshTimer = null;
    var countdownSeconds = 0;
    var draggedWidget = null;

    window.initDashboard = function () {
        setupDragAndDrop();
        setupAutoRefresh();
        bindWindowResize();
    };

    /**
     * Switch Time Range (e.g. 24h, today, 7d, 30d)
     */
    window.changeDashTimeRange = function (range) {
        var url = new URL(window.location.href);
        url.searchParams.set('range', range);
        window.location.href = url.toString();
    };

    /**
     * Switch Tab inside widgets (e.g. Top Senders / Recipients)
     */
    window.switchDashTab = function (btn, tabId) {
        var container = btn.closest('.dash-tab-container');
        if (!container) return;
        container.querySelectorAll('.dash-tab-btn').forEach(function (b) {
            b.classList.remove('active');
        });
        container.querySelectorAll('.dash-tab-content').forEach(function (c) {
            c.classList.remove('active');
        });
        btn.classList.add('active');
        var target = container.querySelector('#' + tabId);
        if (target) target.classList.add('active');
    };

    /**
     * Toggle Edit Mode
     */
    window.toggleDashboardEditMode = function () {
        isEditMode = !isEditMode;
        var container = document.getElementById('dashboardGrid');
        var editControls = document.getElementById('dashEditBar');
        var btnToggle = document.getElementById('btnEditDashboard');

        if (isEditMode) {
            container.classList.add('editing-mode');
            if (editControls) editControls.style.display = 'flex';
            if (btnToggle) {
                btnToggle.innerHTML = '✕ Exit Edit Mode';
                btnToggle.classList.add('btn-active');
            }
        } else {
            container.classList.remove('editing-mode');
            if (editControls) editControls.style.display = 'none';
            if (btnToggle) {
                btnToggle.innerHTML = '✏️ Customize Layout';
                btnToggle.classList.remove('btn-active');
            }
        }
        resizeAllCharts();
    };

    /**
     * Change Widget Width (e.g. col-3, col-4, col-6, col-8, col-12)
     */
    window.setWidgetWidth = function (btn, newWidth) {
        var widget = btn.closest('.dash-widget-wrapper');
        if (!widget) return;

        // Remove existing col- classes
        widget.className = widget.className.replace(/\bcol-\d+\b/g, '').trim();
        widget.classList.add(newWidth);

        // Update active button state
        var selector = btn.closest('.dash-width-selector');
        if (selector) {
            selector.querySelectorAll('button').forEach(function (b) {
                b.classList.remove('active');
            });
            btn.classList.add('active');
        }

        resizeAllCharts();
    };

    /**
     * Remove Widget from Grid
     */
    window.removeWidget = function (btn) {
        var widget = btn.closest('.dash-widget-wrapper');
        if (!widget) return;
        if (confirm('Remove this widget from your dashboard?')) {
            widget.style.transition = 'all 0.2s ease';
            widget.style.opacity = '0';
            widget.style.transform = 'scale(0.9)';
            setTimeout(function () {
                widget.remove();
                resizeAllCharts();
            }, 200);
        }
    };

    /**
     * Move Widget Up / Left
     */
    window.moveWidgetOrder = function (btn, direction) {
        var widget = btn.closest('.dash-widget-wrapper');
        if (!widget) return;
        if (direction === -1 && widget.previousElementSibling) {
            widget.parentNode.insertBefore(widget, widget.previousElementSibling);
        } else if (direction === 1 && widget.nextElementSibling) {
            widget.parentNode.insertBefore(widget.nextElementSibling, widget);
        }
        resizeAllCharts();
    };

    /**
     * Save Current Layout to Database via AJAX
     */
    window.saveDashboardLayout = function () {
        var grid = document.getElementById('dashboardGrid');
        if (!grid) return;

        var layout = [];
        grid.querySelectorAll('.dash-widget-wrapper').forEach(function (el) {
            var id = el.getAttribute('data-widget-id');
            var type = el.getAttribute('data-widget-type');
            var title = el.querySelector('.dash-widget-title-text') ? el.querySelector('.dash-widget-title-text').innerText.trim() : '';
            
            // Extract current width class
            var widthMatch = el.className.match(/\bcol-\d+\b/);
            var width = widthMatch ? widthMatch[0] : 'col-6';

            if (type) {
                layout.push({
                    id: id || (type + '_' + Date.now()),
                    type: type,
                    width: width,
                    title: title
                });
            }
        });

        var token = document.getElementById('dashCsrfToken') ? document.getElementById('dashCsrfToken').value : '';

        var formData = new FormData();
        formData.append('action', 'save_layout');
        formData.append('token', token);
        formData.append('layout', JSON.stringify(layout));

        var saveBtn = document.getElementById('btnSaveLayout');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '⏳ Saving...';
        }

        fetch('dashboard.php', {
            method: 'POST',
            body: formData
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '💾 Save Layout';
            }
            if (data && data.success) {
                showToast('✔ Layout saved successfully!', 'success');
                toggleDashboardEditMode();
            } else {
                showToast('✖ Error saving layout: ' + (data.error || 'Unknown error'), 'error');
            }
        })
        .catch(function (err) {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '💾 Save Layout';
            }
            showToast('✖ Network error saving layout', 'error');
        });
    };

    /**
     * Reset Layout to Default
     */
    window.resetDashboardLayout = function () {
        if (!confirm('Reset dashboard layout to default settings? All custom positions and widget widths will be restored.')) {
            return;
        }

        var token = document.getElementById('dashCsrfToken') ? document.getElementById('dashCsrfToken').value : '';
        var formData = new FormData();
        formData.append('action', 'reset_layout');
        formData.append('token', token);

        fetch('dashboard.php', {
            method: 'POST',
            body: formData
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data && data.success) {
                window.location.reload();
            } else {
                showToast('✖ Error resetting layout', 'error');
            }
        });
    };

    /**
     * Add Widget Modal Controls
     */
    window.openAddWidgetModal = function () {
        var modal = document.getElementById('addWidgetModal');
        if (modal) modal.style.display = 'flex';
    };

    window.closeAddWidgetModal = function () {
        var modal = document.getElementById('addWidgetModal');
        if (modal) modal.style.display = 'none';
    };

    /**
     * Add Widget from Catalog to Dashboard
     */
    window.addWidgetToGrid = function (type, title, defaultWidth) {
        closeAddWidgetModal();
        var grid = document.getElementById('dashboardGrid');
        if (!grid) return;

        var widgetId = type + '_' + Date.now();
        var timeRange = new URLSearchParams(window.location.search).get('range') || '24h';

        // Placeholder wrapper while loading content
        var wrapper = document.createElement('div');
        wrapper.className = 'dash-widget-wrapper ' + defaultWidth;
        wrapper.setAttribute('data-widget-id', widgetId);
        wrapper.setAttribute('data-widget-type', type);
        wrapper.setAttribute('draggable', 'true');
        wrapper.innerHTML = '<div class="dash-widget"><div class="dash-widget-body" style="padding:20px;text-align:center;">⏳ Loading widget content...</div></div>';

        grid.appendChild(wrapper);
        setupDragAndDrop();

        // Fetch widget HTML from server
        fetch('dashboard.php?action=get_widget_html&type=' + encodeURIComponent(type) + '&widget_id=' + encodeURIComponent(widgetId) + '&range=' + encodeURIComponent(timeRange))
            .then(function (res) { return res.text(); })
            .then(function (html) {
                wrapper.innerHTML = html;
                resizeAllCharts();
                showToast('✔ Added "' + title + '" widget', 'success');
            })
            .catch(function () {
                wrapper.innerHTML = '<div class="dash-widget"><div class="dash-widget-body text-danger">Failed to load widget</div></div>';
            });
    };

    /**
     * Refresh Single Widget
     */
    window.refreshWidget = function (btn) {
        var wrapper = btn.closest('.dash-widget-wrapper');
        if (!wrapper) return;
        var type = wrapper.getAttribute('data-widget-type');
        var widgetId = wrapper.getAttribute('data-widget-id');
        var timeRange = new URLSearchParams(window.location.search).get('range') || '24h';
        var body = wrapper.querySelector('.dash-widget-body');

        btn.classList.add('rotating');

        fetch('dashboard.php?action=get_widget_body&type=' + encodeURIComponent(type) + '&widget_id=' + encodeURIComponent(widgetId) + '&range=' + encodeURIComponent(timeRange))
            .then(function (res) { return res.text(); })
            .then(function (html) {
                if (body) {
                    body.innerHTML = html;
                    // Execute scripts in injected HTML
                    Array.from(body.querySelectorAll('script')).forEach(function (oldScript) {
                        var newScript = document.createElement('script');
                        Array.from(oldScript.attributes).forEach(function (attr) {
                            newScript.setAttribute(attr.name, attr.value);
                        });
                        newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                        oldScript.parentNode.replaceChild(newScript, oldScript);
                    });
                }
                btn.classList.remove('rotating');
                resizeAllCharts();
            })
            .catch(function () {
                btn.classList.remove('rotating');
            });
    };

    /**
     * Refresh Entire Dashboard
     */
    window.refreshDashboard = function () {
        var refreshBtn = document.getElementById('btnManualRefresh');
        if (refreshBtn) refreshBtn.classList.add('rotating');

        var wrappers = document.querySelectorAll('.dash-widget-wrapper');
        var completed = 0;

        wrappers.forEach(function (w) {
            var btn = w.querySelector('.btn-dash-refresh');
            if (btn) refreshWidget(btn);
        });

        setTimeout(function () {
            if (refreshBtn) refreshBtn.classList.remove('rotating');
            resetCountdown();
        }, 600);
    };

    /**
     * Drag & Drop Setup
     */
    function setupDragAndDrop() {
        var grid = document.getElementById('dashboardGrid');
        if (!grid) return;

        var wrappers = grid.querySelectorAll('.dash-widget-wrapper');
        wrappers.forEach(function (item) {
            item.setAttribute('draggable', 'true');

            item.addEventListener('dragstart', function (e) {
                if (!isEditMode) {
                    e.preventDefault();
                    return false;
                }
                draggedWidget = item;
                item.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
            });

            item.addEventListener('dragend', function () {
                item.classList.remove('is-dragging');
                grid.querySelectorAll('.dash-widget-wrapper').forEach(function (w) {
                    w.classList.remove('drag-over');
                });
                draggedWidget = null;
                resizeAllCharts();
            });

            item.addEventListener('dragover', function (e) {
                if (!isEditMode || !draggedWidget || draggedWidget === item) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                item.classList.add('drag-over');
            });

            item.addEventListener('dragleave', function () {
                item.classList.remove('drag-over');
            });

            item.addEventListener('drop', function (e) {
                if (!isEditMode || !draggedWidget || draggedWidget === item) return;
                e.preventDefault();
                item.classList.remove('drag-over');

                var allWidgets = Array.from(grid.querySelectorAll('.dash-widget-wrapper'));
                var draggedIdx = allWidgets.indexOf(draggedWidget);
                var targetIdx = allWidgets.indexOf(item);

                if (draggedIdx < targetIdx) {
                    item.after(draggedWidget);
                } else {
                    item.before(draggedWidget);
                }
                resizeAllCharts();
            });
        });
    }

    /**
     * Auto Refresh Controller
     */
    function setupAutoRefresh() {
        var select = document.getElementById('dashAutoRefresh');
        if (!select) return;

        var savedVal = localStorage.getItem('mw_dash_autorefresh');
        if (savedVal !== null) {
            select.value = savedVal;
        }

        autoRefreshInterval = parseInt(select.value, 10) || 0;
        resetCountdown();

        select.addEventListener('change', function () {
            autoRefreshInterval = parseInt(this.value, 10) || 0;
            localStorage.setItem('mw_dash_autorefresh', this.value);
            resetCountdown();
        });

        if (window.dashTimer) clearInterval(window.dashTimer);
        window.dashTimer = setInterval(function () {
            if (autoRefreshInterval <= 0 || isEditMode) return;
            countdownSeconds--;
            updateCountdownUi();

            if (countdownSeconds <= 0) {
                refreshDashboard();
            }
        }, 1000);
    }

    function resetCountdown() {
        countdownSeconds = autoRefreshInterval;
        updateCountdownUi();
    }

    function updateCountdownUi() {
        var indicator = document.getElementById('dashRefreshCountdown');
        if (!indicator) return;
        if (autoRefreshInterval <= 0) {
            indicator.innerText = 'Off';
            indicator.style.opacity = '0.5';
        } else {
            indicator.innerText = countdownSeconds + 's';
            indicator.style.opacity = '1';
        }
    }

    /**
     * ECharts Auto-Resize Helper
     */
    function resizeAllCharts() {
        setTimeout(function () {
            if (window.echarts) {
                document.querySelectorAll('.dash-widget-body [id^="traffic_chart_"], .dash-widget-body [id^="threat_donut_"]').forEach(function (el) {
                    var inst = echarts.getInstanceByDom(el);
                    if (inst) inst.resize();
                });
            }
        }, 100);
    }

    function bindWindowResize() {
        window.addEventListener('resize', function () {
            resizeAllCharts();
        });
    }

    /**
     * Toast notification popup
     */
    function showToast(msg, type) {
        var toast = document.createElement('div');
        toast.className = 'dash-toast ' + (type || 'info');
        toast.innerText = msg;
        document.body.appendChild(toast);
        setTimeout(function () { toast.classList.add('visible'); }, 20);
        setTimeout(function () {
            toast.classList.remove('visible');
            setTimeout(function () { toast.remove(); }, 300);
        }, 3000);
    }

})();
