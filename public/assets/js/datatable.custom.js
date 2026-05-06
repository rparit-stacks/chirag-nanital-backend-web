// Define a global namespace for datatable utilities
window.DatatableUtils = {
    // Public method to refresh a specific datatable by ID or all datatables
    refreshDatatable: function (tableId = null) {
        if (tableId && $.fn.DataTable.isDataTable('#' + tableId)) {
            // Refresh specific table if ID is provided and table exists
            $('#' + tableId).DataTable().ajax.reload(function () {
                console.log('Datatable ' + tableId + ' refreshed successfully');
                // Refresh lightbox for new content
                if (typeof refreshFsLightbox !== 'undefined') {
                    refreshFsLightbox();
                }
            }, false);
        } else {
            // Refresh all datatables if no ID provided or table not found
            const datatables = $.fn.dataTable.tables();
            if (datatables.length > 0) {
                $(datatables).each(function () {
                    $(this).DataTable().ajax.reload(function () {
                        console.log('Datatable refreshed successfully');
                        // Refresh lightbox for new content
                        if (typeof refreshFsLightbox !== 'undefined') {
                            refreshFsLightbox();
                        }
                    }, false);
                });
            }
        }
    }
};

$(document).ready(function () {


    // Show spinner on AJAX start, hide on complete
    $(document).on('ajaxStart', function () {
        $('#datatable-loading').removeClass('d-none');
    }).on('ajaxStop', function () {
        $('#datatable-loading').addClass('d-none');
    });
    // Reset filters
    $('#resetFilters').on('click', function () {
        $('#statusFilter, #brandFilter, #categoryFilter').val('');
        $('#reportrange').val('');
        // Trigger filter update if needed
    });


    const datatableElements = document.querySelectorAll('[data-datatable]');

    datatableElements.forEach((element) => {
        const route = element.getAttribute('data-route');
        const columns = JSON.parse(element.getAttribute('data-columns'));
        // Read the custom options, if they exist.
        const rawOptions = element.getAttribute('data-options');
        let customOptions = rawOptions ? JSON.parse(rawOptions) : {};

        // Check if the DataTable is already initialized
        if ($.fn.DataTable.isDataTable(element)) {
            return; // Skip initialization if already initialized
        }

        // Define your default options.
        let defaultOptions = {
            language: {
                emptyTable: "<div class='text-center text-secondary p-3'><i class='ti ti-database fs-1'></i><br>No data available.</div>"
            },
            layout: {
                topStart: {
                    search: "applied",
                    pageLength: true
                },
                topEnd: {
                    buttons: [
                        {
                            extend: 'colvis',
                            text: '<i class="ti ti-columns-3 me-1"></i><span>Columns</span>',
                            columns: ':not(:first-child)', // excludes first column (ID)
                            align: 'button-right',
                            columnText: function (dt, idx, title) {
                                return idx + ': ' + title;
                            },
                            init: function (dt, node, config) {
                                $(node)
                                    .removeClass('btn-secondary')
                                    .addClass('btn btn-outline-primary rounded-2 dt-toolbar-btn dropdown-toggle');
                            }
                        },
                        {
                            extend: 'collection',
                            text: '<i class="ti ti-download me-1"></i><span>Export</span>',
                            className: 'btn',
                            align: 'button-right',
                            buttons: [
                                {
                                    extend: 'excelHtml5',
                                    text: '<i class="ti ti-file-spreadsheet me-2 text-success"></i><span>Excel</span>',
                                    exportOptions: {
                                        columns: ':visible'
                                    }
                                }
                            ],
                            init: function (dt, node, config) {
                                $(node)
                                    .removeClass('btn-secondary')
                                    .addClass('btn btn-outline-primary ms-2 rounded-2 dt-toolbar-btn dropdown-toggle');
                            }
                        },
                    ]
                },
            },
            initComplete: function () {
                let searchInput = $('.dt-search input');
                searchInput.removeClass('form-control-sm').addClass('ms-0').attr('placeholder', 'Search...');
                $('.dt-search label').contents().filter(function () {
                    return this.nodeType === 3;
                }).remove();

                $('.dt-length select')
                    .removeClass('form-select-sm');
            },
            select: false,
            responsive: true,
            processing: true,
            serverSide: true,
            scrollX: true,
            ajax: {
                url: route,
            },
            columns: columns,
            drawCallback: function() {
                // Check if current page exceeds total pages after filtering
                const api = this.api();
                const info = api.page.info();

                // If current page is greater than total pages and there are records
                if (info.page > 0 && info.pages > 0 && info.page >= info.pages) {
                    // Redirect to the last page
                    api.page(info.pages - 1).draw('page');
                    return;
                }

                // Refresh lightbox after table draw/redraw
                if (typeof refreshFsLightbox !== 'undefined') {
                    refreshFsLightbox();
                }
            }
        };

        // Merge custom options with defaultOptions.
        // If customOptions is an array, merge each object into defaultOptions.
        if (Array.isArray(customOptions)) {
            customOptions.forEach(option => {
                defaultOptions = $.extend(true, {}, defaultOptions, option);
            });
        } else {
            defaultOptions = $.extend(true, {}, defaultOptions, customOptions);
        }
        $(element).DataTable(defaultOptions);
    });
});
$(document).ready(function () {
    // Add click event listener for refresh button
    $('#refresh, .refresh-table').on('click', function () {
        // Try to find the closest datatable to the clicked button
        const closestTable = $(this).closest('.card').find('table.dataTable');

        if (closestTable.length > 0) {
            // If found, refresh only that table
            const tableId = closestTable.attr('id');
            if (tableId) {
                // Use the global method to refresh the specific table
                window.DatatableUtils.refreshDatatable(tableId);
                return;
            }
        }

        // If no specific table found, refresh all datatables
        window.DatatableUtils.refreshDatatable();
    });
});
