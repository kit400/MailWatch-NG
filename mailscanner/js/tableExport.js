/**
 * Table to CSV Exporter for MailWatch-NG / EFA-NG
 * Copyright (C) 2026 MailWatch-NG Team
 */

(function () {
    'use strict';

    /**
     * Exports an HTML table to a clean CSV file
     * @param {string|HTMLTableElement} tableRef - DOM element or ID of the table
     * @param {string} [filename] - Optional filename for the downloaded CSV
     */
    window.exportTableToCSV = function (tableRef, filename) {
        var table = (typeof tableRef === 'string') ? document.getElementById(tableRef) : tableRef;
        if (!table) {
            table = document.querySelector('.reportTable') || document.querySelector('table');
        }
        if (!table) {
            alert('No table found to export.');
            return;
        }

        filename = filename || 'report_export_' + (new Date().toISOString().slice(0, 10)) + '.csv';
        if (!filename.toLowerCase().endsWith('.csv')) {
            filename += '.csv';
        }

        var rows = table.querySelectorAll('tr');
        if (!rows || rows.length === 0) {
            alert('No rows found in table.');
            return;
        }

        // 2D grid matrix to handle rowspan and colspan
        var grid = [];
        var maxCols = 0;

        for (var r = 0; r < rows.length; r++) {
            var row = rows[r];
            // Skip hidden rows
            if (row.style.display === 'none' || row.classList.contains('hidden') || row.classList.contains('noprint')) {
                continue;
            }

            var cells = row.querySelectorAll('th, td');
            if (cells.length === 0) continue;

            if (!grid[r]) grid[r] = [];

            var colIdx = 0;
            for (var c = 0; c < cells.length; c++) {
                var cell = cells[c];

                // Advance colIdx if position is already occupied by a previous rowspan/colspan
                while (grid[r][colIdx] !== undefined) {
                    colIdx++;
                }

                var rowspan = parseInt(cell.getAttribute('rowspan') || '1', 10);
                var colspan = parseInt(cell.getAttribute('colspan') || '1', 10);

                // Clone cell to sanitize and extract plain text
                var clone = cell.cloneNode(true);

                // Remove unwanted interactive/media elements
                var removeElements = clone.querySelectorAll('script, style, button, input, select, textarea, svg, img');
                for (var k = 0; k < removeElements.length; k++) {
                    removeElements[k].remove();
                }

                var text = (clone.innerText || clone.textContent || '').trim();
                // Replace non-breaking spaces, newlines, tabs, and excess whitespace
                text = text.replace(/\u00A0/g, ' ').replace(/[\r\n\t]+/g, ' ').replace(/\s{2,}/g, ' ').trim();

                // Populate grid with span handling
                for (var ro = 0; ro < rowspan; ro++) {
                    var targetRow = r + ro;
                    if (!grid[targetRow]) grid[targetRow] = [];
                    for (var co = 0; co < colspan; co++) {
                        var targetCol = colIdx + co;
                        grid[targetRow][targetCol] = (ro === 0 && co === 0) ? text : (colspan > 1 && ro === 0 ? text : '');
                        if (targetCol + 1 > maxCols) {
                            maxCols = targetCol + 1;
                        }
                    }
                }
                colIdx += colspan;
            }
        }

        // Build CSV rows
        var csvLines = [];
        for (var i = 0; i < grid.length; i++) {
            if (!grid[i] || grid[i].length === 0) continue;
            var line = [];
            for (var j = 0; j < maxCols; j++) {
                var val = (grid[i][j] !== undefined) ? String(grid[i][j]) : '';
                // Escape double quotes
                val = val.replace(/"/g, '""');
                line.push('"' + val + '"');
            }
            csvLines.push(line.join(','));
        }

        if (csvLines.length === 0) {
            alert('No data available to export.');
            return;
        }

        // Prepend UTF-8 BOM (\uFEFF) for Excel compatibility
        var csvContent = '\uFEFF' + csvLines.join('\r\n');
        var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        var url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', filename);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        setTimeout(function () {
            URL.revokeObjectURL(url);
        }, 1500);
    };
})();
